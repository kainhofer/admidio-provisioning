<?php
namespace AdmidioPlugin\Provisioning\Entity;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Entity\Entity;
use AdmidioPlugin\Provisioning\ProvisioningTables;

/**
 * One group of a target: a name and the roles whose members or leaders belong to it.
 *
 * Groups come from three sources. **manual** groups are configured by an administrator. **sso**
 * groups are taken from the role mapping of the linked SSO client and keyed by the client role
 * name. **catchall** groups stand for one role each that no other group maps, keyed by the role ID.
 * Only manual groups are edited through this entity; the planner maintains the other two with plain
 * SQL, so that the automatic changes do not fill the change history.
 *
 * The sync columns (remote ID, hash, last sync, last error) are written by the worker with plain SQL
 * for the same reason and are not part of the logged configuration.
 */
class Group extends Entity
{
    use OrganizationScope;

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SSO = 'sso';
    public const SOURCE_CATCHALL = 'catchall';

    public const STATE_ACTIVE = 'active';
    public const STATE_DELETE = 'delete';

    public function __construct(Database $database, int|string $id = '')
    {
        parent::__construct($database, ProvisioningTables::groups(), 'pvg', $id);
    }

    protected static function organizationScopeCondition(): string
    {
        return 'pvg_pvt_id IN (SELECT pvt_id FROM ' . ProvisioningTables::targets() . ' WHERE pvt_org_id = ?)';
    }

    public function getHookId(): ?string
    {
        return 'provisioning_group';
    }

    public function getIgnoredLogColumns(): array
    {
        return array_merge(
            parent::getIgnoredLogColumns(),
            array('pvg_key', 'pvg_remote_id', 'pvg_hash', 'pvg_last_sync', 'pvg_last_error')
        );
    }

    public function readableName(): string
    {
        return $this->filterReadableName((string) ($this->dbColumns['pvg_name'] ?? ''));
    }

    public function getFieldTitle(string $field): string
    {
        return match ($field) {
            'pvg_name' => 'SYS_NAME',
            'pvg_roles' => 'SYS_ROLES',
            'pvg_source' => 'PLG_PROVISIONING_GROUP_SOURCE',
            'pvg_state' => 'PLG_PROVISIONING_STATE',
            default => parent::getFieldTitle($field)
        };
    }

    /**
     * @return array<int,int> Role IDs; a negative ID stands for the leaders of that role.
     */
    public function getRoleIds(): array
    {
        return self::decodeRoleIds((string) $this->getValue('pvg_roles', 'database'));
    }

    public function setRoleIds(array $roleIds): void
    {
        $this->setValue('pvg_roles', self::encodeRoleIds($roleIds), false);
    }

    /**
     * @return array<int,int>
     */
    public static function decodeRoleIds(string $json): array
    {
        $ids = json_decode($json, true);
        return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : array();
    }

    public static function encodeRoleIds(array $roleIds): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $roleIds))));
        sort($ids);
        return json_encode($ids);
    }
}
