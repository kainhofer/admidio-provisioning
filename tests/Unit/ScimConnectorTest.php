<?php
namespace AdmidioPlugin\Provisioning\Tests\Unit;

use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\Provisioning\Connector\Account;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\Connector\GroupState;
use AdmidioPlugin\Provisioning\Connector\Scim\ScimConnector;

require_once dirname(__DIR__) . '/Support/autoload.php';

/**
 * The SCIM requests the connector builds, against a fake transport that records them and answers
 * from a list of prepared responses.
 */
final class ScimConnectorTest extends AdmidioTestCase
{
    /** @var array<int,array{method: string, url: string, headers: array<int,string>, body: array|null}> */
    private array $requests = array();
    /** @var array<int,array{0:int, 1:array, 2:string}> */
    private array $responses = array();
    /** @var array{patch: bool, filter: bool} */
    private array $serviceProvider = array('patch' => true, 'filter' => true);

    private function connector(array $config = array(), array $secrets = array('token' => 'secret-token')): ScimConnector
    {
        $config += array('base_url' => 'https://scim.example/v2/', 'auth_type' => 'bearer');

        return new ScimConnector($config, $secrets, false, function (string $method, string $url, array $headers, ?string $body): array {
            if (str_ends_with($url, '/ServiceProviderConfig')) {
                return array(200, array(), json_encode(array(
                    'patch' => array('supported' => $this->serviceProvider['patch']),
                    'filter' => array('supported' => $this->serviceProvider['filter']),
                    'bulk' => array('supported' => false),
                    'authenticationSchemes' => array(array('type' => 'oauthbearertoken'))
                )));
            }
            $this->requests[] = array(
                'method' => $method,
                'url' => $url,
                'headers' => $headers,
                'body' => $body === null ? null : json_decode($body, true)
            );
            return array_shift($this->responses) ?? array(200, array(), '{}');
        });
    }

    private function respond(int $status, array $body = array(), array $headers = array()): void
    {
        $this->responses[] = array($status, $headers, json_encode($body));
    }

    private function account(bool $active = true): Account
    {
        return new Account('uuid-1', 'jdoe', 'jdoe@example.org', $active, array(
            'name.givenName' => 'Jane',
            'name.familyName' => 'Doe',
            'emails' => 'jdoe@example.org',
            ScimConnector::SCHEMA_ENTERPRISE . ':department' => 'Board'
        ));
    }

    public function testUserResourceUsesScimStructures(): void
    {
        $resource = $this->connector()->userResource($this->account());

        $this->assertSame(array(ScimConnector::SCHEMA_USER, ScimConnector::SCHEMA_ENTERPRISE), $resource['schemas']);
        $this->assertSame('uuid-1', $resource['externalId']);
        $this->assertSame('jdoe', $resource['userName']);
        $this->assertTrue($resource['active']);
        $this->assertSame(array('givenName' => 'Jane', 'familyName' => 'Doe'), $resource['name']);
        $this->assertSame(array(array('value' => 'jdoe@example.org', 'type' => 'work', 'primary' => true)), $resource['emails']);
        $this->assertSame(array('department' => 'Board'), $resource[ScimConnector::SCHEMA_ENTERPRISE]);
    }

    public function testRequestsCarryTheBearerTokenAndTheScimMediaType(): void
    {
        $this->respond(201, array('id' => 'remote-1'));
        $this->connector()->createAccount($this->account());

        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://scim.example/v2/Users', $this->requests[0]['url']);
        $this->assertContains('Authorization: Bearer secret-token', $this->requests[0]['headers']);
        $this->assertContains('Content-Type: application/scim+json', $this->requests[0]['headers']);
    }

    public function testFindAccountByExternalId(): void
    {
        $this->respond(200, array('totalResults' => 1, 'Resources' => array(array('id' => 'remote-1', 'externalId' => 'uuid-1'))));

        $this->assertSame('remote-1', $this->connector()->findAccount($this->account()));
        $this->assertStringContainsString('filter=externalId%20eq%20%22uuid-1%22', $this->requests[0]['url']);
    }

    public function testAnAccountOfSomebodyElseWithTheSameNameIsAConflict(): void
    {
        $this->respond(200, array('totalResults' => 0, 'Resources' => array()));
        $this->respond(200, array('totalResults' => 1, 'Resources' => array(array('id' => 'remote-9', 'externalId' => 'other'))));

        $this->expectException(ConnectorError::class);
        try {
            $this->connector()->findAccount($this->account());
        } catch (ConnectorError $error) {
            $this->assertSame(ConnectorError::CONFLICT, $error->kind);
            throw $error;
        }
    }

    public function testAnAccountWithoutExternalIdIsTakenOverByName(): void
    {
        $this->respond(200, array('totalResults' => 0, 'Resources' => array()));
        $this->respond(200, array('totalResults' => 1, 'Resources' => array(array('id' => 'remote-7'))));

        $this->assertSame('remote-7', $this->connector()->findAccount($this->account()));
    }

    public function testDuplicateOnCreateTakesTheExistingAccountOver(): void
    {
        $this->respond(409, array('scimType' => 'uniqueness'));
        $this->respond(200, array('totalResults' => 1, 'Resources' => array(array('id' => 'remote-3', 'externalId' => 'uuid-1'))));

        $this->assertSame('remote-3', $this->connector()->createAccount($this->account()));
    }

    public function testUpdateIsAPatchReplace(): void
    {
        $this->connector()->updateAccount('remote-1', $this->account());

        $request = $this->requests[0];
        $this->assertSame('PATCH', $request['method']);
        $this->assertSame('https://scim.example/v2/Users/remote-1', $request['url']);
        $this->assertSame(array(ScimConnector::SCHEMA_PATCH), $request['body']['schemas']);
        $this->assertSame('replace', $request['body']['Operations'][0]['op']);
        $this->assertSame('jdoe', $request['body']['Operations'][0]['value']['userName']);
        $this->assertArrayNotHasKey('schemas', $request['body']['Operations'][0]['value']);
    }

    public function testDeactivationSendsActiveFalse(): void
    {
        $this->connector()->deactivateAccount('remote-1', $this->account());

        $this->assertFalse($this->requests[0]['body']['Operations'][0]['value']['active']);
    }

    public function testWithoutPatchSupportTheFullResourceIsPut(): void
    {
        $this->serviceProvider['patch'] = false;
        $this->connector()->updateAccount('remote-1', $this->account());

        $this->assertSame('PUT', $this->requests[0]['method']);
        $this->assertSame('remote-1', $this->requests[0]['body']['id']);
        $this->assertSame('jdoe', $this->requests[0]['body']['userName']);
    }

    public function testMembersAreChangedInPortions(): void
    {
        $add = array_map(static fn(int $i): string => 'm' . $i, range(1, 250));
        $this->connector()->changeMembers('group-1', new GroupState('g-uuid', 'Board', $add), $add, array('old-1', 'old-2'));

        $this->assertCount(4, $this->requests);
        $this->assertCount(100, $this->requests[0]['body']['Operations'][0]['value']);
        $this->assertCount(100, $this->requests[1]['body']['Operations'][0]['value']);
        $this->assertCount(50, $this->requests[2]['body']['Operations'][0]['value']);
        $this->assertSame('add', $this->requests[0]['body']['Operations'][0]['op']);
        $this->assertSame(
            array(
                array('op' => 'remove', 'path' => 'members[value eq "old-1"]'),
                array('op' => 'remove', 'path' => 'members[value eq "old-2"]')
            ),
            $this->requests[3]['body']['Operations']
        );
    }

    public function testWithPutTheGroupIsReplacedWithAllMembers(): void
    {
        $this->connector(array('use_put' => true))->changeMembers('group-1', new GroupState('g-uuid', 'Board', array('a', 'b')), array('b'), array('c'));

        $this->assertCount(1, $this->requests);
        $this->assertSame('PUT', $this->requests[0]['method']);
        $this->assertSame(array(array('value' => 'a'), array('value' => 'b')), $this->requests[0]['body']['members']);
    }

    public function testNothingIsSentWithoutMemberChanges(): void
    {
        $this->connector()->changeMembers('group-1', new GroupState('g-uuid', 'Board'), array(), array());

        $this->assertSame(array(), $this->requests);
    }

    /**
     * @return array<string,array{0:int,1:string}>
     */
    public static function errorProvider(): array
    {
        return array(
            'unauthorized' => array(401, ConnectorError::TARGET),
            'forbidden' => array(403, ConnectorError::TARGET),
            'server error' => array(503, ConnectorError::RETRY),
            'rate limit' => array(429, ConnectorError::RETRY),
            'rejected data' => array(400, ConnectorError::INVALID),
            'gone' => array(404, ConnectorError::MISSING)
        );
    }

    /**
     * @dataProvider errorProvider
     */
    public function testErrorsAreClassified(int $status, string $kind): void
    {
        $this->respond($status, array('detail' => 'nope'), array('retry-after' => '120'));

        try {
            $this->connector()->updateAccount('remote-1', $this->account());
            $this->fail('No error was raised.');
        } catch (ConnectorError $error) {
            $this->assertSame($kind, $error->kind);
            if ($status === 429) {
                $this->assertSame(120, $error->retryAfter);
            }
            $this->assertStringNotContainsString('secret-token', $error->getMessage());
        }
    }

    public function testDeletingAMissingAccountIsNoError(): void
    {
        $this->respond(404);
        $this->connector()->deleteAccount('remote-1');

        $this->assertSame('DELETE', $this->requests[0]['method']);
    }

    public function testTestReportsTheCapabilities(): void
    {
        $details = $this->connector()->test();

        $this->assertSame('yes', $details['PATCH']);
        $this->assertSame('yes', $details['filter']);
        $this->assertSame('oauthbearertoken', $details['authentication']);
    }

    public function testOauthTokenIsRequestedOnceAndUsed(): void
    {
        $this->respond(200, array('access_token' => 'oauth-token', 'token_type' => 'Bearer'));
        $this->respond(201, array('id' => 'remote-1'));
        $this->respond(201, array('id' => 'remote-2'));

        $connector = $this->connector(
            array('auth_type' => 'oauth', 'oauth_token_url' => 'https://idp.example/token', 'oauth_client_id' => 'admidio'),
            array('oauth_client_secret' => 's3cret')
        );
        $connector->createAccount($this->account());
        $connector->createAccount($this->account());

        $this->assertSame('https://idp.example/token', $this->requests[0]['url']);
        $this->assertContains('Authorization: Bearer oauth-token', $this->requests[1]['headers']);
        $this->assertContains('Authorization: Bearer oauth-token', $this->requests[2]['headers']);
        $this->assertCount(3, $this->requests);
    }

    public function testBasicAuthentication(): void
    {
        $this->respond(201, array('id' => 'remote-1'));
        $this->connector(array('auth_type' => 'basic', 'username' => 'admidio'), array('password' => 'pw'))->createAccount($this->account());

        $this->assertContains('Authorization: Basic ' . base64_encode('admidio:pw'), $this->requests[0]['headers']);
    }
}
