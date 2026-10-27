<?php
namespace AdmidioPlugin\Provisioning\Connector;

use Admidio\Hooks\Hooks;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use InvalidArgumentException;

/**
 * The registry of connector types. The plugin registers its own connectors through the same filter
 * that other plugins can use, see Connector.
 */
final class Connectors
{
    public const HOOK = 'provisioning_connectors';

    /**
     * @return array<string,class-string<Connector>> Connector type => class, sorted by type.
     */
    public static function all(): array
    {
        $connectors = array();
        foreach (Hooks::applyFilters(self::HOOK, array()) as $type => $class) {
            if (is_string($type) && is_string($class) && is_subclass_of($class, Connector::class)) {
                $connectors[$type] = $class;
            }
        }
        ksort($connectors);
        return $connectors;
    }

    /**
     * @return class-string<Connector>
     */
    public static function getClass(string $type): string
    {
        $connectors = self::all();
        if (!isset($connectors[$type])) {
            throw new InvalidArgumentException('Unknown provisioning connector "' . $type . '".');
        }
        return $connectors[$type];
    }

    /**
     * The connector of a target, configured with the target's settings.
     */
    public static function forTarget(Target $target): Connector
    {
        $class = self::getClass((string) $target->getValue('pvt_connector', 'database'));

        return new $class(
            $target->getConfig(),
            $target->getSecrets(),
            (bool) ProvisioningPlugin::setting(ProvisioningPlugin::SETTING_ALLOW_PRIVATE_NETWORK)
        );
    }

    private function __construct()
    {
    }
}
