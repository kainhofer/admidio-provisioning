<?php
namespace AdmidioPlugin\Provisioning\Tests\Support;

use Admidio\Hooks\Hooks;
use Admidio\Infrastructure\Database;
use Admidio\Tests\Integration\Sso\SsoClientFixture;
use Admidio\Tests\Support\AdministratorTestCase;
use Admidio\Tests\Support\AdmidioTestFixture;
use AdmidioPlugin\Provisioning\Connector\Connectors;
use AdmidioPlugin\Provisioning\Connector\Scim\ScimConnector;
use AdmidioPlugin\Provisioning\Entity\Group;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\Service\ChangeCollector;
use AdmidioPlugin\Provisioning\Service\Scheduler;

/**
 * Base of the database tests: creates the plugin schema, registers the test connectors, lets
 * administrators administer the plugin (it is not enabled in the test installation) and offers
 * helpers for targets, groups and members.
 */
abstract class ProvisioningDatabaseTestCase extends AdministratorTestCase
{
    use SsoClientFixture;

    protected AdmidioTestFixture $fixture;

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
    protected function createTarget(array $userRoles, bool $catchAll = false, ?array $ssoLink = null, string $connector = 'memory', array $config = array(), array $secrets = array()): Target
    {
        global $gCurrentOrgId;

        $target = new Target($this->getDatabase());
        $target->setConfig($config);
        $target->setSecrets($secrets);
        $target->setValue('pvt_org_id', (int) $gCurrentOrgId);
        $target->setValue('pvt_name', 'Test target ' . $this->suffix());
        $target->setValue('pvt_connector', $connector);
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
    protected function createGroup(Target $target, string $name, array $roleIds): Group
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
    protected function sync(Target $target, array $options = array()): array
    {
        return (new Scheduler($this->getDatabase()))->run($options + array('target' => $target, 'full' => true))[0];
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,mixed>} role, user
     */
    protected function member(string $name, string $begin = DATE_NOW, string $end = DATE_MAX, ?array $role = null, bool $leader = false): array
    {
        global $gCurrentOrgId;

        $role ??= $this->fixture->createAndSaveRole('Role ' . $name . ' ' . $this->suffix(), (int) $gCurrentOrgId);
        $user = $this->fixture->createAndSaveUser('prov-' . $name . '-' . $this->suffix(), $name . '@example.local');
        $this->fixture->assignUserToRolePeriod($user['usr_id'], $role['rol_id'], $begin, $end, $leader);
        return array($role, $user);
    }

    protected static function yesterday(): string
    {
        return date('Y-m-d', strtotime(DATE_NOW . ' -1 day'));
    }

    protected static function tomorrow(): string
    {
        return date('Y-m-d', strtotime(DATE_NOW . ' +1 day'));
    }
}
