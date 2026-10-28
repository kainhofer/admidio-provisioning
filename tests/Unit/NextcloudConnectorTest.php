<?php
namespace AdmidioPlugin\Provisioning\Tests\Unit;

use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\Provisioning\Connector\Account;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\Connector\GroupState;
use AdmidioPlugin\Provisioning\Connector\Nextcloud\NextcloudConnector;

require_once dirname(__DIR__) . '/Support/autoload.php';

/**
 * The OCS requests of the Nextcloud connector, against a fake transport that records them and
 * answers from prepared responses.
 */
final class NextcloudConnectorTest extends AdmidioTestCase
{
    /** @var array<int,array{method: string, url: string, headers: array<int,string>, body: array<string,string>}> */
    private array $requests = array();
    /** @var array<int,array{0:int, 1:array, 2:string}> */
    private array $responses = array();

    private function connector(): NextcloudConnector
    {
        return new NextcloudConnector(
            array('base_url' => 'https://cloud.example/', 'username' => 'admin'),
            array('password' => 'app-password'),
            false,
            function (string $method, string $url, array $headers, ?string $body): array {
                parse_str((string) $body, $form);
                $this->requests[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $form);
                return array_shift($this->responses) ?? self::ocs(200, 200, array());
            }
        );
    }

    private static function ocs(int $status, int $code, mixed $data, string $message = ''): array
    {
        return array($status, array(), json_encode(array('ocs' => array(
            'meta' => array('status' => $code === 200 ? 'ok' : 'failure', 'statuscode' => $code, 'message' => $message),
            'data' => $data
        ))));
    }

    private function respond(int $status, int $code, mixed $data = array(), string $message = ''): void
    {
        $this->responses[] = self::ocs($status, $code, $data, $message);
    }

    private function account(bool $active = true): Account
    {
        return new Account('uuid-1', 'jdoe', 'jdoe@example.org', $active, array(
            'displayname' => 'Jane Doe',
            'email' => 'jdoe@example.org',
            'phone' => '+43 1 234'
        ));
    }

    public function testRequestsUseTheOcsHeadersAndBasicAuthentication(): void
    {
        $this->respond(404, 404, null, 'User does not exist');
        $this->assertNull($this->connector()->findAccount($this->account()));

        $request = $this->requests[0];
        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://cloud.example/ocs/v2.php/cloud/users/jdoe', $request['url']);
        $this->assertContains('OCS-APIRequest: true', $request['headers']);
        $this->assertContains('Authorization: Basic ' . base64_encode('admin:app-password'), $request['headers']);
    }

    public function testAnExistingUserIsTakenOver(): void
    {
        $this->respond(200, 200, array('id' => 'jdoe', 'enabled' => true));

        $this->assertSame('jdoe', $this->connector()->findAccount($this->account()));
    }

    public function testCreatingSetsTheRemainingAttributesAfterwards(): void
    {
        $this->respond(200, 200, array('id' => 'jdoe'));
        $this->respond(200, 200, array('id' => 'jdoe', 'displayname' => 'Jane Doe', 'email' => 'jdoe@example.org', 'phone' => '', 'enabled' => true));

        $this->assertSame('jdoe', $this->connector()->createAccount($this->account()));

        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame(array('userid' => 'jdoe', 'displayName' => 'Jane Doe', 'email' => 'jdoe@example.org'), $this->requests[0]['body']);
        // only the phone differs after the creation
        $this->assertCount(3, $this->requests);
        $this->assertSame('PUT', $this->requests[2]['method']);
        $this->assertSame(array('key' => 'phone', 'value' => '+43 1 234'), $this->requests[2]['body']);
    }

    public function testUpdateChangesOnlyWhatDiffersAndTheEnabledState(): void
    {
        $this->respond(200, 200, array('displayname' => 'Old', 'email' => 'jdoe@example.org', 'phone' => '+43 1 234', 'enabled' => true));

        $this->connector()->updateAccount('jdoe', new Account('uuid-1', 'jdoe', '', false, array('displayname' => 'Jane Doe', 'phone' => '+43 1 234')));

        $this->assertCount(3, $this->requests);
        $this->assertSame(array('key' => 'displayname', 'value' => 'Jane Doe'), $this->requests[1]['body']);
        $this->assertSame('https://cloud.example/ocs/v2.php/cloud/users/jdoe/disable', $this->requests[2]['url']);
    }

    public function testDeactivationDisablesTheUser(): void
    {
        $this->connector()->deactivateAccount('jdoe', $this->account());

        $this->assertSame('PUT', $this->requests[0]['method']);
        $this->assertSame('https://cloud.example/ocs/v2.php/cloud/users/jdoe/disable', $this->requests[0]['url']);
    }

    public function testGroupsAreFoundByIdOrDisplayName(): void
    {
        $this->respond(200, 200, array('groups' => array(
            array('id' => 'board-old', 'displayname' => 'Board'),
            array('id' => 'Boarding', 'displayname' => 'Boarding')
        )));

        $this->assertSame('board-old', $this->connector()->findGroup(new GroupState('g-1', 'Board')));
        $this->assertStringContainsString('groups/details?search=Board', $this->requests[0]['url']);
    }

    public function testAFoundGroupWithTheRightDisplayNameIsNotChanged(): void
    {
        $this->respond(200, 200, array('groups' => array(array('id' => 'Board', 'displayname' => 'Board'))));
        $connector = $this->connector();
        $group = new GroupState('g-1', 'Board');

        $connector->updateGroup((string) $connector->findGroup($group), $group);

        $this->assertCount(1, $this->requests);
    }

    public function testAFoundGroupWithAnotherDisplayNameGetsTheName(): void
    {
        $this->respond(200, 200, array('groups' => array(array('id' => 'board', 'displayname' => 'board'))));
        $connector = $this->connector();
        $group = new GroupState('g-1', 'Board');

        $connector->updateGroup((string) $connector->findGroup($group), $group);

        $this->assertCount(2, $this->requests);
        $this->assertSame('PUT', $this->requests[1]['method']);
        $this->assertSame('https://cloud.example/ocs/v2.php/cloud/groups/board', $this->requests[1]['url']);
        $this->assertSame(array('key' => 'displayname', 'value' => 'Board'), $this->requests[1]['body']);
    }

    public function testMembersAreAddedAndRemovedPerUser(): void
    {
        $this->connector()->changeMembers('Board', new GroupState('g-1', 'Board'), array('jdoe'), array('max'));

        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://cloud.example/ocs/v2.php/cloud/users/jdoe/groups', $this->requests[0]['url']);
        $this->assertSame(array('groupid' => 'Board'), $this->requests[0]['body']);
        $this->assertSame('DELETE', $this->requests[1]['method']);
        $this->assertSame('https://cloud.example/ocs/v2.php/cloud/users/max/groups?groupid=Board', $this->requests[1]['url']);
    }

    public function testAMissingGroupIsReportedAsMissing(): void
    {
        $this->respond(400, 102, null, 'Group does not exist');

        try {
            $this->connector()->changeMembers('Gone', new GroupState('g-1', 'Gone'), array('jdoe'), array());
            $this->fail('No error was raised.');
        } catch (ConnectorError $error) {
            $this->assertSame(ConnectorError::MISSING, $error->kind);
        }
    }

    public function testRefusedCredentialsAreAnErrorOfTheTarget(): void
    {
        $this->respond(401, 997, null, 'Current user is not logged in');

        try {
            $this->connector()->findAccount($this->account());
            $this->fail('No error was raised.');
        } catch (ConnectorError $error) {
            $this->assertSame(ConnectorError::TARGET, $error->kind);
            $this->assertStringNotContainsString('app-password', $error->getMessage());
        }
    }

    public function testVerificationComparesTheEnabledState(): void
    {
        $this->respond(200, 200, array('id' => 'jdoe', 'enabled' => false));
        $this->respond(404, 404, null);

        $connector = $this->connector();
        $this->assertFalse($connector->verifyAccount('jdoe', $this->account()));
        $this->assertFalse($connector->verifyAccount('gone', $this->account()));
    }

    public function testGroupMembersAreRead(): void
    {
        $this->respond(200, 200, array('users' => array('jdoe', 'max')));

        $this->assertSame(array('jdoe', 'max'), $this->connector()->readGroupMembers('Board'));
    }

    public function testAFieldTheBackendManagesIsLeftAlone(): void
    {
        // a user created by a SAML login: the display name comes from SAML
        $this->respond(200, 200, array('displayname' => 'Schmidt, Paul', 'email' => 'old@example.org', 'enabled' => true));
        $this->respond(400, 113);

        $this->connector()->updateAccount('admin', new Account('uuid-1', 'admin', '', true,
            array('displayname' => 'Paul Schmidt', 'email' => 'paul@example.org')));

        $this->assertCount(3, $this->requests);
        $this->assertSame(array('key' => 'email', 'value' => 'paul@example.org'), $this->requests[2]['body']);
    }

    public function testARejectedValueDoesNotStopTheOtherFields(): void
    {
        $this->respond(200, 200, array('displayname' => 'Old', 'phone' => '', 'enabled' => true));
        $this->respond(400, 101, null, 'Invalid phone');

        try {
            $this->connector()->updateAccount('demo', new Account('uuid-1', 'demo', '', false,
                array('phone' => '030-85858585', 'displayname' => 'User Demo')));
            $this->fail('The rejected phone number was not reported.');
        } catch (ConnectorError $error) {
            $this->assertSame(ConnectorError::INVALID, $error->kind);
            $this->assertStringContainsString('phone (Invalid phone)', $error->getMessage());
        }

        // the display name and the deactivation were still sent
        $this->assertSame(array('key' => 'displayname', 'value' => 'User Demo'), $this->requests[2]['body']);
        $this->assertStringEndsWith('/users/demo/disable', $this->requests[3]['url']);
    }
}
