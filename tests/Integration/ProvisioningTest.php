<?php
namespace AdmidioPlugin\Provisioning\Tests\Integration;

use Admidio\Hooks\Hooks;
use Admidio\Hooks\ValueObject\EntityChangeSet;
use Admidio\Hooks\ValueObject\EntityFieldChange;
use Admidio\Infrastructure\Database;
use Admidio\Tests\Integration\Sso\SsoClientFixture;
use Admidio\Tests\Support\AdministratorTestCase;
use Admidio\Tests\Support\AdmidioTestFixture;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\Connector\Connectors;
use AdmidioPlugin\Provisioning\Connector\Scim\ScimConnector;
use AdmidioPlugin\Provisioning\Entity\Group;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\ProvisioningTables;
use AdmidioPlugin\Provisioning\Service\ChangeCollector;
use AdmidioPlugin\Provisioning\Service\Planner;
use AdmidioPlugin\Provisioning\Service\Queue;
use AdmidioPlugin\Provisioning\Service\Scheduler;
use AdmidioPlugin\Provisioning\Service\TargetService;
use AdmidioPlugin\Provisioning\Service\TargetStatus;
use AdmidioPlugin\Provisioning\Service\Worker;
use AdmidioPlugin\Provisioning\Tests\Support\MemoryConnector;

require_once dirname(__DIR__) . '/Support/autoload.php';

/**
 * The provisioning flow against the real database, with the in-memory connector as target system:
 * who belongs to a target, what is sent, and what happens when memberships end, users are deleted
 * or the target answers with an error.
 */
final class ProvisioningTest extends AdministratorTestCase
{
    use SsoClientFixture;

    private AdmidioTestFixture $fixture;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // The schema is created outside of the per-test transaction: DDL commits implicitly.
        $db = self::$gDb;
        $directory = dirname(__DIR__, 2) . '/db_scripts/';
        foreach (array('uninstall.sql', 'install.sql') as $file) {
            foreach (Database::getSqlStatementsFromSqlFile($directory . $file) as $statement) {
                $db->queryPrepared($statement);
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        foreach (Database::getSqlStatementsFromSqlFile(dirname(__DIR__, 2) . '/db_scripts/uninstall.sql') as $statement) {
            self::$gDb->queryPrepared($statement);
        }
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new AdmidioTestFixture($this->getDatabase());
        MemoryConnector::reset();
        ChangeCollector::reset();
        Hooks::addFilter(Connectors::HOOK, static function (array $connectors): array {
            $connectors['memory'] = MemoryConnector::class;
            $connectors['scim'] = ScimConnector::class;
            return $connectors;
        }, 10, 1, 'provisioning-test-connectors');
        // the plugin is not enabled in the test installation; administrators may administer it here
        Hooks::addFilter('component_administrable', static function (bool $answer, string $component): bool {
            return $component === 'provisioning' ? $GLOBALS['gCurrentUser']->isAdministrator() : $answer;
        }, 10, 2, 'provisioning-test-administrable');
    }

    protected function tearDown(): void
    {
        Hooks::removeFilter('component_administrable', 'provisioning-test-administrable');
        Hooks::removeFilter(Connectors::HOOK, 'provisioning-test-connectors');
        parent::tearDown();
    }

    protected function getFixture(): AdmidioTestFixture
    {
        return $this->fixture;
    }

    /**
     * @param array<int,int> $userRoles
     */
    private function createTarget(array $userRoles, bool $catchAll = false, ?array $ssoLink = null): Target
    {
        global $gCurrentOrgId;

        $target = new Target($this->getDatabase());
        $target->setValue('pvt_org_id', (int) $gCurrentOrgId);
        $target->setValue('pvt_name', 'Test target ' . $this->suffix());
        $target->setValue('pvt_connector', 'memory');
        $target->setValue('pvt_enabled', true);
        $target->setUserRoleIds($userRoles);
        $target->setAttributeMapping(array(array('path' => 'displayName', 'source' => 'usr_login_name')));
        $target->setValue('pvt_group_catchall', $catchAll);
        if ($ssoLink !== null) {
            $target->setValue('pvt_sso_type', $ssoLink[0]);
            $target->setValue('pvt_sso_client_id', $ssoLink[1]);
        }
        $target->save();
        return $target;
    }

    /**
     * @param array<int,int> $roleIds negative IDs for leaders
     */
    private function createGroup(Target $target, string $name, array $roleIds): Group
    {
        $group = new Group($this->getDatabase());
        $group->setValue('pvg_pvt_id', $target->getId());
        $group->setValue('pvg_source', Group::SOURCE_MANUAL);
        $group->setValue('pvg_key', $name . '-' . $this->suffix());
        $group->setValue('pvg_name', $name);
        $group->setRoleIds($roleIds);
        $group->save();
        return $group;
    }

    /**
     * @return array<string,mixed>
     */
    private function sync(Target $target, array $options = array()): array
    {
        return (new Scheduler($this->getDatabase()))->run($options + array('target' => $target, 'full' => true))[0];
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>} role, user
     */
    private function member(string $name, string $begin = DATE_NOW, string $end = DATE_MAX, ?array $role = null, bool $leader = false): array
    {
        global $gCurrentOrgId;

        $role ??= $this->fixture->createAndSaveRole('Role ' . $name . ' ' . $this->suffix(), (int) $gCurrentOrgId);
        $user = $this->fixture->createAndSaveUser('prov-' . $name . '-' . $this->suffix(), $name . '@example.local');
        $this->fixture->assignUserToRolePeriod($user['usr_id'], $role['rol_id'], $begin, $end, $leader);
        return array($role, $user);
    }

    private static function yesterday(): string
    {
        return date('Y-m-d', strtotime(DATE_NOW . ' -1 day'));
    }

    private static function tomorrow(): string
    {
        return date('Y-m-d', strtotime(DATE_NOW . ' +1 day'));
    }

    public function testMembersOfTheSelectedRolesAreProvisioned(): void
    {
        [$role, $member] = $this->member('member');
        [, $outsider] = $this->member('outsider');
        $target = $this->createTarget(array($role['rol_id']));

        $result = $this->sync($target);

        $this->assertTrue($result['reconciled']);
        $account = MemoryConnector::accountByKey($member['usr_uuid']);
        $this->assertNotNull($account);
        $this->assertSame($member['usr_login_name'], $account['name']);
        $this->assertTrue($account['active']);
        $this->assertSame(array('displayName' => $member['usr_login_name']), $account['attributes']);
        $this->assertNull(MemoryConnector::accountByKey($outsider['usr_uuid']));
    }

    public function testGroupsGetTheMembersAndLeadersOfTheirRoles(): void
    {
        [$role, $member] = $this->member('grp-member');
        [, $leader] = $this->member('grp-leader', DATE_NOW, DATE_MAX, $role, true);
        $target = $this->createTarget(array(-1));
        $this->createGroup($target, 'Everybody', array($role['rol_id']));
        $this->createGroup($target, 'Leaders', array(-$role['rol_id']));

        $this->sync($target);

        $memberId = MemoryConnector::accountByKey($member['usr_uuid'])['remoteId'];
        $leaderId = MemoryConnector::accountByKey($leader['usr_uuid'])['remoteId'];
        $this->assertEqualsCanonicalizing(array($memberId, $leaderId), array_keys(MemoryConnector::groupByName('Everybody')['members']));
        $this->assertSame(array($leaderId), array_keys(MemoryConnector::groupByName('Leaders')['members']));
    }

    public function testUnchangedDataIsNotSentAgain(): void
    {
        [$role] = $this->member('twice');
        $target = $this->createTarget(array($role['rol_id']));
        $this->createGroup($target, 'Twice', array($role['rol_id']));
        $this->sync($target);

        MemoryConnector::$calls = array();
        $result = $this->sync($target);

        $this->assertSame(0, $result['queued']);
        $this->assertSame(array(), MemoryConnector::$calls);
    }

    public function testAMembershipThatEndedDeactivatesTheAccountAndLeavesTheGroup(): void
    {
        [$role, $user] = $this->member('ended');
        $target = $this->createTarget(array($role['rol_id']));
        $this->createGroup($target, 'Ended', array($role['rol_id']));
        $this->sync($target);
        $this->assertCount(1, MemoryConnector::groupByName('Ended')['members']);

        // the end date is the first day without membership
        $this->getDatabase()->queryPrepared(
            'UPDATE ' . TBL_MEMBERS . ' SET mem_end = ? WHERE mem_usr_id = ? AND mem_rol_id = ?',
            array(DATE_NOW, $user['usr_id'], $role['rol_id'])
        );
        $this->sync($target);

        $account = MemoryConnector::accountByKey($user['usr_uuid']);
        $this->assertNotNull($account, 'The account is kept, only deactivated.');
        $this->assertFalse($account['active']);
        $this->assertSame(array(), MemoryConnector::groupByName('Ended')['members']);
    }

    public function testAMembershipIsProvisionedFromItsStartDateOn(): void
    {
        [$role, $today] = $this->member('starts-today', DATE_NOW);
        [, $tomorrow] = $this->member('starts-tomorrow', self::tomorrow(), DATE_MAX, $role);
        [, $yesterday] = $this->member('ended-yesterday', '2020-01-01', self::yesterday(), $role);
        $target = $this->createTarget(array($role['rol_id']));

        $this->sync($target);

        $this->assertNotNull(MemoryConnector::accountByKey($today['usr_uuid']));
        $this->assertNull(MemoryConnector::accountByKey($tomorrow['usr_uuid']));
        $this->assertNull(MemoryConnector::accountByKey($yesterday['usr_uuid']));
    }

    public function testADeletedUserIsDeletedOnTheTarget(): void
    {
        [$role, $user] = $this->member('deleted');
        $target = $this->createTarget(array($role['rol_id']));
        $this->sync($target);
        $this->assertNotNull(MemoryConnector::accountByKey($user['usr_uuid']));

        $this->fixture->deleteUser($user['usr_id']);
        $this->sync($target);

        $this->assertNull(MemoryConnector::accountByKey($user['usr_uuid']));
        $mappings = $this->getDatabase()->queryPrepared(
            'SELECT COUNT(*) FROM ' . ProvisioningTables::accounts() . ' WHERE pva_usr_uuid = ?',
            array($user['usr_uuid'])
        )->fetchColumn();
        $this->assertSame(0, (int) $mappings);
    }

    public function testUsersWithoutAccountNameAreSkipped(): void
    {
        [$role, $user] = $this->member('nameless');
        $target = $this->createTarget(array($role['rol_id']));
        $target->setValue('pvt_account_name_source', 'LAST_NAME');
        $target->save();

        $this->sync($target);

        $this->assertNull(MemoryConnector::accountByKey($user['usr_uuid']));
        $this->assertSame(1, (int) (new TargetStatus($this->getDatabase()))->get($target->getId())['pvs_skipped_users']);
    }

    public function testAChangedMembershipIsQueuedForTheUser(): void
    {
        [$role, $user] = $this->member('hook');
        $this->createTarget(array($role['rol_id']));

        $changeSet = new EntityChangeSet(
            'membership', \Admidio\Roles\Entity\Membership::class, TBL_MEMBERS, 'mem', 'mem_id', 1, null,
            EntityChangeSet::OPERATION_CREATE, 'op-1',
            array(
                'mem_usr_id' => new EntityFieldChange('mem_usr_id', null, $user['usr_id'], 'integer', EntityFieldChange::KIND_BUSINESS),
                'mem_rol_id' => new EntityFieldChange('mem_rol_id', null, $role['rol_id'], 'integer', EntityFieldChange::KIND_BUSINESS)
            ),
            array()
        );

        $this->assertTrue(ChangeCollector::collect($changeSet));
        $queued = $this->getDatabase()->queryPrepared(
            'SELECT pvq_type FROM ' . ProvisioningTables::queue() . ' WHERE pvq_key = ?',
            array($user['usr_uuid'])
        )->fetchColumn();
        $this->assertSame(Queue::TYPE_ACCOUNT, $queued);
    }

    public function testACascadingMembershipDeletionIsLeftToTheUserDeletion(): void
    {
        [$role, $user] = $this->member('cascade');
        $this->createTarget(array($role['rol_id']));

        $changeSet = new EntityChangeSet(
            'membership', \Admidio\Roles\Entity\Membership::class, TBL_MEMBERS, 'mem', 'mem_id', 1, null,
            EntityChangeSet::OPERATION_DELETE, 'op-2', array(),
            array('mem_usr_id' => $user['usr_id'], 'mem_rol_id' => $role['rol_id']),
            'user', $user['usr_id']
        );

        $this->assertFalse(ChangeCollector::collect($changeSet));
    }

    public function testTheQueueMergesItemsAndANewChangeOutlivesARunningWorker(): void
    {
        $target = $this->createTarget(array());
        $queue = new Queue($this->getDatabase());
        $queue->enqueue($target->getId(), Queue::TYPE_ACCOUNT, 'same');
        $queue->enqueue($target->getId(), Queue::TYPE_ACCOUNT, 'same');
        $this->assertSame(1, $queue->counts($target->getId())[Queue::STATE_PENDING]);

        $item = $queue->dueItems($target->getId())[0];
        $this->assertTrue($queue->claim((int) $item['pvq_id'], 'worker-1'));
        $this->assertFalse($queue->claim((int) $item['pvq_id'], 'worker-2'), 'A claimed item cannot be claimed twice.');

        // queued again while worker-1 processes it: its result must not remove the item
        $queue->enqueue($target->getId(), Queue::TYPE_ACCOUNT, 'same');
        $queue->complete((int) $item['pvq_id'], 'worker-1');
        $this->assertSame(1, $queue->counts($target->getId())[Queue::STATE_PENDING]);
    }

    public function testOnlyOneWorkerProcessesATarget(): void
    {
        $target = $this->createTarget(array());
        $status = new TargetStatus($this->getDatabase());

        $this->assertTrue($status->claim($target->getId(), 'first'));
        $this->assertFalse($status->claim($target->getId(), 'second'));
        $status->release($target->getId(), 'first');
        $this->assertTrue($status->claim($target->getId(), 'second'));
    }

    public function testTheDailyReconciliationRunsOncePerDay(): void
    {
        $target = $this->createTarget(array());
        $status = new TargetStatus($this->getDatabase());

        $this->assertTrue($status->claimReconciliation($target->getId()));
        $this->assertFalse($status->claimReconciliation($target->getId()));
        // the next day it is due again
        $this->assertTrue($status->claimReconciliation($target->getId(), self::tomorrow()));
    }

    public function testATargetOfAnotherOrganizationCannotBeRead(): void
    {
        $organization = $this->fixture->createAndSaveOrganization('Provisioning other', 'PRV' . substr($this->suffix(), 0, 6));
        $this->getDatabase()->queryPrepared(
            'INSERT INTO ' . ProvisioningTables::targets() . ' (pvt_uuid, pvt_org_id, pvt_name, pvt_connector) VALUES (?, ?, ?, ?)',
            array('00000000-0000-4000-8000-000000000001', $organization['org_id'], 'Foreign', 'memory')
        );

        $this->expectException(\Admidio\Infrastructure\Exception::class);
        (new TargetService($this->getDatabase()))->getTarget('00000000-0000-4000-8000-000000000001');
    }

    public function testCatchAllCreatesAGroupForEveryUnmappedRole(): void
    {
        [$mapped] = $this->member('mapped');
        [$unmapped, $user] = $this->member('unmapped');
        $target = $this->createTarget(array($mapped['rol_id'], $unmapped['rol_id']), true);
        $this->createGroup($target, 'Mapped', array($mapped['rol_id']));

        $this->sync($target);

        $catchAll = MemoryConnector::groupByName($unmapped['rol_name']);
        $this->assertNotNull($catchAll);
        $this->assertSame(array(MemoryConnector::accountByKey($user['usr_uuid'])['remoteId']), array_keys($catchAll['members']));
        $this->assertNull(MemoryConnector::groupByName($mapped['rol_name']), 'An explicitly mapped role gets no group of its own.');

        // switching the option off deletes the groups it created
        $target->setValue('pvt_group_catchall', false);
        $target->save();
        $this->sync($target);
        $this->assertNull(MemoryConnector::groupByName($unmapped['rol_name']));
    }

    public function testALinkedSsoClientDefinesUsersGroupsAndAccountNames(): void
    {
        [$access, $allowed] = $this->member('sso-access');
        [$staff, $staffMember] = $this->member('sso-staff');
        [, $other] = $this->member('sso-other');

        $client = $this->createRestrictedClient($this->suffix(), array($access['rol_id']));
        $client->setRoleMapping(array(array('staff', $staff['rol_id'])));
        $client->save();

        $target = $this->createTarget(array(), false, array('oidc', (int) $client->getValue('ocl_id')));
        $this->sync($target);

        // the client identifies users by usr_uuid, so that is the account name
        $this->assertSame($allowed['usr_uuid'], MemoryConnector::accountByKey($allowed['usr_uuid'])['name']);
        $this->assertNotNull(MemoryConnector::accountByKey($staffMember['usr_uuid']), 'Members of a mapped group are always provisioned.');
        $this->assertNull(MemoryConnector::accountByKey($other['usr_uuid']));
        $this->assertSame(
            array(MemoryConnector::accountByKey($staffMember['usr_uuid'])['remoteId']),
            array_keys(MemoryConnector::groupByName('staff')['members'])
        );
    }

    public function testAMissingSsoClientStopsPlanningInsteadOfDeactivatingEverybody(): void
    {
        $target = $this->createTarget(array(), false, array('oidc', 999999));

        $this->expectException(\RuntimeException::class);
        (new Planner($this->getDatabase(), $target))->planAll();
    }

    public function testAConflictNeedsAttention(): void
    {
        [$role] = $this->member('conflict');
        $target = $this->createTarget(array($role['rol_id']));
        $queue = new Queue($this->getDatabase());
        $queue->enqueueAll($target->getId(), (new Planner($this->getDatabase(), $target))->planAll());

        MemoryConnector::$failNext = ConnectorError::conflict('taken');
        $result = (new Worker($this->getDatabase(), $target))->run();

        $this->assertSame(1, $result['attention']);
        $this->assertSame(1, $queue->counts($target->getId())[Queue::STATE_ATTENTION]);
        $this->assertSame('taken', $queue->attentionItems($target->getId())[0]['pvq_last_error']);
    }

    public function testAGroupWaitsForAMemberWhoseAccountWasRejected(): void
    {
        [$role] = $this->member('rejected');
        $target = $this->createTarget(array($role['rol_id']));
        $this->createGroup($target, 'Waiting', array($role['rol_id']));
        $queue = new Queue($this->getDatabase());
        $queue->enqueueAll($target->getId(), (new Planner($this->getDatabase(), $target))->planAll());

        MemoryConnector::$failNext = ConnectorError::invalid('rejected');
        (new Worker($this->getDatabase(), $target))->run();

        // the account keeps waiting for an administrator, the group is created and waits for it
        $this->assertSame(1, $queue->counts($target->getId())[Queue::STATE_ATTENTION]);
        $this->assertSame(1, $queue->counts($target->getId())[Queue::STATE_PENDING]);
        $this->assertSame(array(), MemoryConnector::groupByName('Waiting')['members']);
        $this->assertSame(0, count(array_filter(MemoryConnector::$calls, static fn(string $call): bool => str_starts_with($call, 'updateGroup'))));
    }

    public function testAnAuthenticationErrorPausesTheTarget(): void
    {
        [$role] = $this->member('paused');
        $target = $this->createTarget(array($role['rol_id']));
        (new Queue($this->getDatabase()))->enqueueAll($target->getId(), (new Planner($this->getDatabase(), $target))->planAll());

        MemoryConnector::$failNext = ConnectorError::target('HTTP 401');
        $result = (new Worker($this->getDatabase(), $target))->run();

        $this->assertSame(TargetStatus::STATE_PAUSED, $result['state']);
        $this->assertTrue((new TargetStatus($this->getDatabase()))->isPaused($target->getId()));
        $this->assertSame(1, (new Queue($this->getDatabase()))->counts($target->getId())[Queue::STATE_PENDING], 'The item is kept.');
    }

    public function testVerificationRestoresAMemberRemovedOnTheTarget(): void
    {
        [$role, $user] = $this->member('verify');
        $target = $this->createTarget(array($role['rol_id']));
        $this->createGroup($target, 'Verified', array($role['rol_id']));
        $this->sync($target);

        $remoteId = MemoryConnector::accountByKey($user['usr_uuid'])['remoteId'];
        foreach (MemoryConnector::$groups as &$group) {
            unset($group['members'][$remoteId]);
        }
        unset($group);

        $result = $this->sync($target, array('verify' => true));

        $this->assertTrue($result['verified']);
        $this->assertSame(array($remoteId), array_keys(MemoryConnector::groupByName('Verified')['members']));
    }

    public function testSavingKeepsStoredSecretsAndRequestsAReconciliation(): void
    {
        $service = new TargetService($this->getDatabase());
        $values = array(
            'pvt_name' => 'SCIM ' . $this->suffix(),
            'pvt_connector' => 'scim',
            'pvt_enabled' => '1',
            'cfg_base_url' => 'https://scim.example/v2',
            'cfg_auth_type' => 'bearer',
            'cfg_token' => 'first-token',
            'pvt_verify_interval' => '7',
            'map_0' => 'FIRST_NAME'
        );
        $target = $service->save(new Target($this->getDatabase()), $values);

        $values['cfg_token'] = '';
        $values['pvt_name'] = 'Renamed';
        $target = $service->save($service->getTarget((string) $target->getValue('pvt_uuid')), $values);

        $this->assertSame(array('token' => 'first-token'), $target->getSecrets());
        $this->assertSame('https://scim.example/v2', $target->getConfig()['base_url']);
        $this->assertSame(7, $target->getVerifyInterval());
        $this->assertSame(array(array('path' => 'name.givenName', 'source' => 'FIRST_NAME')), $target->getAttributeMapping());
        $this->assertContains('pvt_secrets', $target->getIgnoredLogColumns());
        $this->assertNull((new TargetStatus($this->getDatabase()))->get($target->getId())['pvs_last_reconcile_date']);
    }
}
