<?php
namespace AdmidioPlugin\Provisioning\Tests\Unit;

use Admidio\Infrastructure\Cli\CliTaskRegistry;
use Admidio\Tests\Support\AdmidioTestCase;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;

require_once dirname(__DIR__) . '/Support/autoload.php';

/**
 * The commands of cli.php: that they exist, and that every one of them requires administering the
 * plugin. All of them read or change the provisioning of the whole organization.
 */
final class ProvisioningCliRegistrationTest extends AdmidioTestCase
{
    private const COMMANDS = array('provisioning:sync', 'provisioning:status', 'provisioning:test', 'provisioning:queue', 'provisioning:deprovision');

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // The registry is static and has no reset, so it is filled once, in the plugin context the
        // native CLI uses when it loads plugins/<id>/cli.php.
        if (CliTaskRegistry::get('provisioning:sync') === null) {
            CliTaskRegistry::setPluginContext(ProvisioningPlugin::ID);
            try {
                require dirname(__DIR__, 2) . '/cli.php';
            } finally {
                CliTaskRegistry::setPluginContext(null);
            }
        }
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function commands(): array
    {
        $cases = array();
        foreach (self::COMMANDS as $name) {
            $cases[$name] = array($name);
        }
        return $cases;
    }

    /**
     * @dataProvider commands
     */
    public function testTheCommandIsRegisteredForAdministratorsOnly(string $name): void
    {
        $task = CliTaskRegistry::get($name);

        $this->assertNotNull($task, $name . ' should be registered.');
        $this->assertTrue(is_callable($task['callback']));
        $this->assertSame('provisioning', $task['component']);
        $this->assertSame(CliTaskRegistry::ACCESS_ADMINISTRABLE, $task['componentAccess']);
        $this->assertStringStartsWith($name, (string) $task['usage']);
    }

    public function testOnlySyncSupportsADryRun(): void
    {
        $this->assertTrue(CliTaskRegistry::get('provisioning:sync')['supportsDryRun']);
        $this->assertFalse(CliTaskRegistry::get('provisioning:queue')['supportsDryRun']);
    }

    public function testTheSynchronizationIsAMaintenanceJobWhereCoreOffersThem(): void
    {
        if (!method_exists(CliTaskRegistry::class, 'getMaintenanceJobs')) {
            $this->markTestSkipped('This Admidio version has no maintenance job registry.');
        }
        $this->assertArrayHasKey('provisioning:sync', CliTaskRegistry::getMaintenanceJobs());
    }
}
