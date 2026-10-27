<?php
namespace AdmidioPlugin\Provisioning\Tests\Integration;

use AdmidioPlugin\Provisioning\Connector\Scim\ScimConnector;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\Service\Planner;
use AdmidioPlugin\Provisioning\Service\Queue;
use AdmidioPlugin\Provisioning\Service\TargetService;
use AdmidioPlugin\Provisioning\Service\Worker;
use AdmidioPlugin\Provisioning\Tests\Support\ProvisioningDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/autoload.php';

/**
 * The SCIM connector over real HTTP: cURL, the outbound URL check, authentication, JSON and the
 * request sequence, against the SCIM mock service in tests/Support run by PHP's built-in server.
 */
final class ScimInteropTest extends ProvisioningDatabaseTestCase
{
    private const TOKEN = 'interop-token';

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $store = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$store = (string) tempnam(sys_get_temp_dir(), 'scim');
        self::$port = random_int(20000, 29999);
        self::$server = proc_open(
            array(PHP_BINARY, '-S', '127.0.0.1:' . self::$port, dirname(__DIR__) . '/Support/scim-mock-server.php'),
            array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
            $pipes,
            null,
            array('SCIM_MOCK_STORE' => self::$store, 'SCIM_MOCK_TOKEN' => self::TOKEN)
        );

        // wait until the server accepts connections
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(self::$store);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_resource(self::$server) || @fsockopen('127.0.0.1', self::$port) === false) {
            $this->markTestSkipped('The SCIM mock server could not be started.');
        }
        file_put_contents(self::$store, '');
    }

    private function config(): array
    {
        return array('base_url' => 'http://127.0.0.1:' . self::$port . '/scim/v2', 'auth_type' => 'bearer');
    }

    private function connector(string $token = self::TOKEN): ScimConnector
    {
        // the mock runs on the loopback interface, which only a private-network target may use
        return new ScimConnector($this->config(), array('token' => $token), true);
    }

    private function provision(Target $target, ?ScimConnector $connector = null): array
    {
        (new Queue($this->getDatabase()))->enqueueAll($target->getId(), (new Planner($this->getDatabase(), $target))->planAll());
        return (new Worker($this->getDatabase(), $target, $connector ?? $this->connector()))->run();
    }

    /**
     * @return array{Users: array, Groups: array}
     */
    private static function remote(): array
    {
        $data = json_decode((string) file_get_contents(self::$store), true);
        return is_array($data) ? $data : array('Users' => array(), 'Groups' => array());
    }

    private static function remoteUser(string $externalId): ?array
    {
        foreach (self::remote()['Users'] as $user) {
            if (($user['externalId'] ?? '') === $externalId) {
                return $user;
            }
        }
        return null;
    }

    public function testTheWholeLifecycleOverHttp(): void
    {
        [$role, $user] = $this->member('scim');
        $target = $this->createTarget(array($role['rol_id']), false, null, 'scim', $this->config(), array('token' => self::TOKEN));
        $target->setAttributeMapping(array(array('path' => 'displayName', 'source' => 'usr_login_name')));
        $target->save();
        $this->createGroup($target, 'SCIM members', array($role['rol_id']));

        // provisioning
        $result = $this->provision($target);
        $this->assertSame(0, $result['failed'], (string) $result['message']);
        $remoteUser = self::remoteUser($user['usr_uuid']);
        $this->assertNotNull($remoteUser);
        $this->assertSame($user['usr_login_name'], $remoteUser['userName']);
        $this->assertSame($user['usr_login_name'], $remoteUser['displayName']);
        $this->assertTrue($remoteUser['active']);
        $group = array_values(self::remote()['Groups'])[0];
        $this->assertSame('SCIM members', $group['displayName']);
        $this->assertSame(array(array('value' => $remoteUser['id'])), $group['members']);

        // the membership ends: deactivated and out of the group
        $this->getDatabase()->queryPrepared(
            'UPDATE ' . TBL_MEMBERS . ' SET mem_end = ? WHERE mem_usr_id = ?',
            array(DATE_NOW, $user['usr_id'])
        );
        $this->provision($target);
        $this->assertFalse(self::remoteUser($user['usr_uuid'])['active']);
        $this->assertSame(array(), array_values(self::remote()['Groups'])[0]['members']);

        // deprovisioning removes everything Admidio created
        (new TargetService($this->getDatabase()))->deprovision($target);
        (new Worker($this->getDatabase(), $target, $this->connector()))->run();
        $this->assertSame(array(), self::remote()['Users']);
        $this->assertSame(array(), self::remote()['Groups']);
    }

    public function testAnExistingAccountIsTakenOverInsteadOfDuplicated(): void
    {
        [$role, $user] = $this->member('scim-existing');
        $target = $this->createTarget(array($role['rol_id']), false, null, 'scim', $this->config(), array('token' => self::TOKEN));
        $existing = $this->connector()->createAccount(new \AdmidioPlugin\Provisioning\Connector\Account(
            $user['usr_uuid'], $user['usr_login_name'], '', true
        ));

        $this->provision($target);

        $this->assertCount(1, self::remote()['Users']);
        $this->assertSame($existing, self::remoteUser($user['usr_uuid'])['id']);
    }

    public function testWrongCredentialsPauseTheTarget(): void
    {
        [$role] = $this->member('scim-wrong');
        $target = $this->createTarget(array($role['rol_id']), false, null, 'scim', $this->config(), array('token' => 'wrong'));

        $result = $this->provision($target, $this->connector('wrong'));

        $this->assertSame('paused', $result['state']);
        $this->assertStringContainsString('401', $result['message']);
    }

    public function testThePublicNetworkPolicyRefusesTheLoopbackTarget(): void
    {
        $connector = new ScimConnector($this->config(), array('token' => self::TOKEN), false);

        $this->expectException(\AdmidioPlugin\Provisioning\Connector\ConnectorError::class);
        $connector->test();
    }

    public function testTheConnectionTestReportsTheCapabilities(): void
    {
        $details = $this->connector()->test();

        $this->assertSame('yes', $details['PATCH']);
        $this->assertSame('oauthbearertoken', $details['authentication']);
    }
}
