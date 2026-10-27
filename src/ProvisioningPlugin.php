<?php
namespace AdmidioPlugin\Provisioning;

use Admidio\Components\Entity\Component;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Roles\Entity\Membership;

/**
 * Identity, setting names and small shared helpers of the provisioning plugin.
 */
final class ProvisioningPlugin
{
    public const ID = 'provisioning';

    public const SETTING_REQUEST_BUDGET = 'provisioning_request_budget';
    public const SETTING_ALLOW_PRIVATE_NETWORK = 'provisioning_allow_private_network';
    public const SETTING_NOTIFY_ADMINS = 'provisioning_notify_admins';

    /**
     * Whether the current user may administer the plugin. By default this is an organization
     * administrator (Component::checkAdministrable() for plugin components).
     */
    public static function isAdministrator(): bool
    {
        global $gValidLogin;

        return !empty($gValidLogin) && Component::isAdministrable(self::ID);
    }

    /**
     * The value of one of the plugin settings in the current organization.
     */
    public static function setting(string $name): mixed
    {
        $plugin = PluginRegistry::get(self::ID);
        if ($plugin === null) {
            return null;
        }

        $settings = $plugin->getSettingValues();
        return $settings[$name] ?? null;
    }

    /**
     * The SQL condition of a membership that is valid on the given day, with its parameters.
     *
     * A membership is valid from 00:00 of mem_begin up to, but not including, 00:00 of mem_end, the
     * same rule as User::isMemberOfOrganization(). Core versions that define it in
     * Membership::getSqlValidCondition() are asked; older ones get the same condition from here.
     *
     * @return array{sql: string, params: array<int,string>}
     */
    public static function validMembershipCondition(?string $date = null, string $tableAlias = ''): array
    {
        if (method_exists(Membership::class, 'getSqlValidCondition')) {
            return Membership::getSqlValidCondition($date, $tableAlias);
        }

        $date ??= DATE_NOW;
        $prefix = $tableAlias === '' ? '' : $tableAlias . '.';

        return array(
            'sql' => $prefix . 'mem_begin <= ? AND ' . $prefix . 'mem_end > ?',
            'params' => array($date, $date)
        );
    }

    /**
     * The current time as a database value. DATETIME_NOW is fixed at the start of the request,
     * which is not good enough for leases and backoff in a command that runs for minutes.
     */
    public static function now(int $offsetSeconds = 0): string
    {
        return date('Y-m-d H:i:s', time() + $offsetSeconds);
    }

    private function __construct()
    {
    }
}
