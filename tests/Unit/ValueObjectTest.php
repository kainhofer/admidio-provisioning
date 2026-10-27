<?php
namespace AdmidioPlugin\Provisioning\Tests\Unit;

use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\Provisioning\Connector\Account;
use AdmidioPlugin\Provisioning\Connector\GroupState;
use AdmidioPlugin\Provisioning\Entity\Group;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use AdmidioPlugin\Provisioning\Service\Queue;

require_once dirname(__DIR__) . '/Support/autoload.php';

final class ValueObjectTest extends AdmidioTestCase
{
    public function testAccountHashDoesNotDependOnTheOrderOfAttributes(): void
    {
        $first = new Account('k', 'n', 'e@x', true, array('a' => '1', 'b' => '2'));
        $second = new Account('k', 'n', 'e@x', true, array('b' => '2', 'a' => '1'));

        $this->assertSame($first->hash(), $second->hash());
    }

    public function testAccountHashChangesWithEverythingThatIsSent(): void
    {
        $account = new Account('k', 'n', 'e@x', true, array('a' => '1'));

        $this->assertNotSame($account->hash(), $account->withActive(false)->hash());
        $this->assertNotSame($account->hash(), (new Account('k', 'n2', 'e@x', true, array('a' => '1')))->hash());
        $this->assertNotSame($account->hash(), (new Account('k', 'n', 'e@y', true, array('a' => '1')))->hash());
        $this->assertNotSame($account->hash(), (new Account('k', 'n', 'e@x', true, array('a' => '2')))->hash());
    }

    public function testGroupHashDoesNotDependOnTheOrderOfMembers(): void
    {
        $this->assertSame(GroupState::hashOf('G', array('b', 'a')), GroupState::hashOf('G', array('a', 'b')));
        $this->assertNotSame(GroupState::hashOf('G', array('a')), GroupState::hashOf('H', array('a')));
        $this->assertNotSame(GroupState::hashOf('G', array('a')), GroupState::hashOf('G', array('a', 'b')));
    }

    public function testRoleIdsKeepLeadersAsNegativeIds(): void
    {
        $json = Group::encodeRoleIds(array(5, -3, 5, 0, '2'));

        $this->assertSame('[-3,2,5]', $json);
        $this->assertSame(array(-3, 2, 5), Group::decodeRoleIds($json));
        $this->assertSame(array(), Group::decodeRoleIds('not json'));
    }

    public function testBackoffGrowsAndStaysAtTheMaximum(): void
    {
        $this->assertSame(60, Queue::backoffSeconds(1));
        $this->assertSame(300, Queue::backoffSeconds(2));
        $this->assertSame(1800, Queue::backoffSeconds(3));
        $this->assertSame(7200, Queue::backoffSeconds(4));
        $this->assertSame(43200, Queue::backoffSeconds(5));
        $this->assertSame(43200, Queue::backoffSeconds(12));
    }

    /**
     * The end date is the first day without membership: a membership that ends today is no longer
     * valid today, one that starts today already is.
     */
    public function testValidMembershipConditionTreatsTheEndDateAsExclusive(): void
    {
        $condition = ProvisioningPlugin::validMembershipCondition('2026-10-27', 'm');

        $this->assertSame('m.mem_begin <= ? AND m.mem_end > ?', $condition['sql']);
        $this->assertSame(array('2026-10-27', '2026-10-27'), $condition['params']);
    }
}
