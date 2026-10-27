<?php
namespace AdmidioPlugin\Provisioning;

/**
 * Names of the plugin tables. They are functions and not constants because TABLE_PREFIX is only
 * known once the configuration has been read.
 */
final class ProvisioningTables
{
    /** Tables written by the synchronization itself; their changes are not logged. */
    public const OPERATIONAL = array(
        'provisioning_accounts', 'provisioning_members', 'provisioning_queue', 'provisioning_status'
    );

    public static function targets(): string
    {
        return TABLE_PREFIX . '_provisioning_targets';
    }

    public static function groups(): string
    {
        return TABLE_PREFIX . '_provisioning_groups';
    }

    public static function accounts(): string
    {
        return TABLE_PREFIX . '_provisioning_accounts';
    }

    public static function members(): string
    {
        return TABLE_PREFIX . '_provisioning_members';
    }

    public static function queue(): string
    {
        return TABLE_PREFIX . '_provisioning_queue';
    }

    public static function status(): string
    {
        return TABLE_PREFIX . '_provisioning_status';
    }

    private function __construct()
    {
    }
}
