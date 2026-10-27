<?php
namespace AdmidioPlugin\Provisioning\Tests\Unit;

use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\Provisioning\Connector\Account;
use AdmidioPlugin\Provisioning\Connector\Connector;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\Connector\GroupState;
use AdmidioPlugin\Provisioning\Connector\Mailman\MailmanConnector;

require_once dirname(__DIR__) . '/Support/autoload.php';

/**
 * The REST requests of the Mailman connector, against a fake transport.
 */
final class MailmanConnectorTest extends AdmidioTestCase
{
    /** @var array<int,array{method: string, url: string, headers: array<int,string>, body: array<string,string>}> */
    private array $requests = array();
    /** @var array<int,array{0:int, 1:array, 2:string}> */
    private array $responses = array();

    private function connector(array $config = array()): MailmanConnector
    {
        return new MailmanConnector(
            $config + array('base_url' => 'https://lists.example/3.1', 'username' => 'restadmin', 'mail_host' => 'lists.example.org'),
            array('password' => 'restpass'),
            false,
            function (string $method, string $url, array $headers, ?string $body): array {
                parse_str((string) $body, $form);
                $this->requests[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $form);
                return array_shift($this->responses) ?? array(204, array(), '');
            }
        );
    }

    private function respond(int $status, array $body = array()): void
    {
        $this->responses[] = array($status, array(), json_encode($body));
    }

    public function testMembersAreEmailAddressesAndThereAreNoAccounts(): void
    {
        $connector = $this->connector();
        $capabilities = $connector->getCapabilities();

        $this->assertFalse($capabilities['accounts']);
        $this->assertSame(Connector::MEMBER_KEY_EMAIL, $capabilities['memberKey']);
        $this->assertFalse($capabilities['deleteGroups'], 'Lists are kept unless the target says otherwise.');
        $this->assertTrue($this->connector(array('delete_lists' => true))->getCapabilities()['deleteGroups']);
        $this->assertSame('EMAIL', MailmanConnector::getDefaultAccountNameSource());
        $this->assertSame('jane@example.org', $connector->getMemberKey(null, new Account('k', 'n', ' Jane@Example.org ', true)));
    }

    public function testAListIsFoundByItsAddress(): void
    {
        $this->respond(200, array('list_id' => 'board.lists.example.org', 'fqdn_listname' => 'board@lists.example.org'));

        $this->assertSame('board.lists.example.org', $this->connector()->findGroup(new GroupState('g', 'Board')));
        $this->assertSame('https://lists.example/3.1/lists/board%40lists.example.org', $this->requests[0]['url']);
        $this->assertContains('Authorization: Basic ' . base64_encode('restadmin:restpass'), $this->requests[0]['headers']);
    }

    public function testAMissingListIsOnlyCreatedIfAllowed(): void
    {
        try {
            $this->connector()->createGroup(new GroupState('g', 'board'));
            $this->fail('A list was created without permission.');
        } catch (ConnectorError $error) {
            $this->assertSame(ConnectorError::INVALID, $error->kind);
            $this->assertSame(array(), $this->requests);
        }

        $this->respond(201);
        $this->assertSame('board.lists.example.org', $this->connector(array('create_lists' => true))->createGroup(new GroupState('g', 'board')));
        $this->assertSame(array('fqdn_listname' => 'board@lists.example.org'), $this->requests[0]['body']);
    }

    public function testSubscriptionsArePreApprovedAndAnExistingOneIsAccepted(): void
    {
        $this->respond(201);
        $this->respond(409, array('description' => 'Member already subscribed'));

        $this->connector()->changeMembers('board.lists.example.org', new GroupState('g', 'board'), array('a@example.org', 'b@example.org'), array('c@example.org'));

        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://lists.example/3.1/members', $this->requests[0]['url']);
        $this->assertSame('a@example.org', $this->requests[0]['body']['subscriber']);
        $this->assertSame('true', $this->requests[0]['body']['pre_approved']);
        $this->assertSame('false', $this->requests[0]['body']['send_welcome_message']);
        $this->assertSame('DELETE', $this->requests[2]['method']);
        $this->assertSame('https://lists.example/3.1/lists/board.lists.example.org/member/c%40example.org', $this->requests[2]['url']);
    }

    public function testTheRosterIsReadPageByPage(): void
    {
        $first = array_map(static fn(int $i): array => array('email' => 'm' . $i . '@example.org'), range(1, 200));
        $this->respond(200, array('total_size' => 201, 'entries' => $first));
        $this->respond(200, array('total_size' => 201, 'entries' => array(array('email' => 'Last@Example.org'))));

        $members = $this->connector()->readGroupMembers('board.lists.example.org');

        $this->assertCount(201, $members);
        $this->assertSame('last@example.org', $members[200]);
        $this->assertStringContainsString('page=2', $this->requests[1]['url']);
    }

    public function testAGroupNameWithoutDomainNeedsTheMailHost(): void
    {
        $this->expectException(ConnectorError::class);
        $this->connector(array('mail_host' => ''))->findGroup(new GroupState('g', 'board'));
    }
}
