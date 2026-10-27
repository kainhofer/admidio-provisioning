<?php
namespace AdmidioPlugin\Provisioning\Service;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Language;
use Admidio\SSO\Entity\OIDCClient;
use Admidio\SSO\Entity\SAMLClient;
use Admidio\SSO\Entity\SSOClient;
use AdmidioPlugin\Provisioning\Connector\Account;
use AdmidioPlugin\Provisioning\Connector\Connectors;
use AdmidioPlugin\Provisioning\Connector\GroupState;
use AdmidioPlugin\Provisioning\Entity\Group;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use AdmidioPlugin\Provisioning\ProvisioningTables;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Calculates from the Admidio database which accounts and groups a target should have, and which
 * of them differ from what was last sent.
 *
 * Users of a target:
 *
 *     valid users with a valid membership in the organization
 *       AND (no role filter, or member of one of the selected roles,
 *            or - if linked - allowed to sign in to the SSO client)
 *     PLUS every user who belongs to one of the target's groups
 *
 * minus the users without an account name, who cannot be provisioned.
 *
 * Planning only reads Admidio and the stored state of the target (hashes of what was last sent); it
 * never contacts the target. The result is a list of queue items.
 */
class Planner
{
    private int $organizationId;
    private ?SSOClient $ssoClient = null;
    private bool $ssoClientLoaded = false;

    /** @var array<int,array<string,mixed>> Users by usr_id, as far as they were loaded. */
    private array $users = array();
    /** @var array<int,array<string,string>> Field values by usr_id and field name. */
    private array $values = array();
    /** @var array<string,array{id:int,type:string,options:array<int,string>}>|null */
    private ?array $fieldDefinitions = null;
    private int $skippedUsers = 0;
    private ?bool $groupsSupported = null;
    private ?string $accountNameSource = null;

    public function __construct(private readonly Database $db, private readonly Target $target)
    {
        $this->organizationId = (int) $target->getValue('pvt_org_id');
    }

    /**
     * Everything that differs. Also maintains the groups that come from the SSO role mapping and
     * from the catch-all option.
     *
     * @return array<int,array{type: string, key: string}>
     */
    public function planAll(): array
    {
        return $this->plan(null, null);
    }

    /**
     * The accounts of these users, and the groups they are or were a member of.
     *
     * @param array<int,int> $userIds
     * @return array<int,array{type: string, key: string}>
     */
    public function planUsers(array $userIds): array
    {
        $userIds = self::ids($userIds);
        if ($userIds === array()) {
            return array();
        }
        return $this->plan($userIds, $this->groupsOfUsers($userIds));
    }

    /**
     * The groups that use these roles, and the accounts of everybody who is or was a member.
     *
     * @param array<int,int> $roleIds
     * @return array<int,array{type: string, key: string}>
     */
    public function planRoles(array $roleIds): array
    {
        $roleIds = self::ids($roleIds);
        if ($roleIds === array()) {
            return array();
        }

        $this->syncGroupDefinitions();
        [$in, $params] = self::in($roleIds);
        $userIds = array_map('intval', $this->db->queryPrepared(
            'SELECT DISTINCT mem_usr_id FROM ' . TBL_MEMBERS . ' WHERE mem_rol_id IN (' . $in . ')',
            $params
        )->fetchAll(\PDO::FETCH_COLUMN));

        $groupIds = array_unique(array_merge($this->groupsOfRoles($roleIds), $this->groupsOfUsers($userIds)));
        return $this->plan($userIds, $groupIds);
    }

    /**
     * The number of users of the last full plan that could not be provisioned because their
     * account name is empty.
     */
    public function getSkippedUsers(): int
    {
        return $this->skippedUsers;
    }

    /**
     * The desired state of one account, for the worker.
     *
     * @return array{state: string, account: Account|null, usr_id: int}
     *         state is **active**, **inactive** (exists, but does not belong to the target) or
     *         **deleted** (the user no longer exists).
     */
    public function desiredAccount(string $userUuid): array
    {
        $row = $this->db->queryPrepared(
            'SELECT usr_id FROM ' . TBL_USERS . ' WHERE usr_uuid = ?',
            array($userUuid)
        )->fetch();

        if ($row === false) {
            return array('state' => 'deleted', 'account' => null, 'usr_id' => 0);
        }

        $userId = (int) $row['usr_id'];
        $this->loadUsers(array($userId));
        $member = in_array($userId, $this->populationUserIds(array($userId)), true);
        $account = $this->buildAccount($userId, $member);

        if ($member && $account->name !== '') {
            return array('state' => 'active', 'account' => $account, 'usr_id' => $userId);
        }
        return array('state' => 'inactive', 'account' => $account->withActive(false), 'usr_id' => $userId);
    }

    /**
     * The desired state of one group, for the worker: its name and the users who belong to it,
     * as usr_id => usr_uuid. Users without an account name are left out.
     *
     * @param array<string,mixed> $group A row of provisioning_groups.
     * @return array{group: GroupState, members: array<int,string>}
     */
    public function desiredGroup(array $group): array
    {
        $members = $this->provisionable($this->groupMemberIds($group, null));

        return array(
            'group' => new GroupState((string) $group['pvg_uuid'], (string) $group['pvg_name']),
            'members' => $members
        );
    }

    /**
     * The groups of the target, all or the given ones.
     *
     * @param array<int,int>|null $groupIds
     * @return array<int,array<string,mixed>>
     */
    public function groups(?array $groupIds = null): array
    {
        if (!$this->groupsSupported()) {
            return array();
        }
        $sql = 'SELECT * FROM ' . ProvisioningTables::groups() . ' WHERE pvg_pvt_id = ?';
        $params = array($this->target->getId());
        if ($groupIds !== null) {
            if ($groupIds === array()) {
                return array();
            }
            [$in, $inParams] = self::in($groupIds);
            $sql .= ' AND pvg_id IN (' . $in . ')';
            $params = array_merge($params, $inParams);
        }
        return $this->db->getArrayFromSql($sql . ' ORDER BY pvg_name, pvg_id', $params);
    }

    /**
     * Bring the groups from the SSO role mapping and the catch-all groups in line with their
     * sources. Groups that disappear are set to **delete**; the worker removes them on the target
     * and then here. A group that was never created on the target is removed at once.
     */
    public function syncGroupDefinitions(): void
    {
        if (!$this->groupsSupported()) {
            return;
        }
        $existing = array();
        foreach ($this->groups() as $row) {
            $existing[(string) $row['pvg_source']][(string) $row['pvg_key']] = $row;
        }

        // groups from the SSO role mapping
        $client = $this->ssoClient();
        $ssoGroups = array();
        if ($client !== null) {
            foreach ($client->getRoleMapping() as $name => $roleIds) {
                $ssoGroups[(string) $name] = array('name' => (string) $name, 'roles' => $roleIds);
            }
        }
        $this->syncSource(Group::SOURCE_SSO, $ssoGroups, $existing[Group::SOURCE_SSO] ?? array());

        // catch-all groups: one per role that no other group maps
        $catchAll = $client !== null ? $client->getRoleMappingCatchall() : (bool) $this->target->getValue('pvt_group_catchall');
        $catchAllGroups = array();
        if ($catchAll) {
            $catchAllGroups = $this->catchAllGroups(
                array_merge(
                    array_values($existing[Group::SOURCE_MANUAL] ?? array()),
                    array_map(static fn(array $group): array => array(
                        'pvg_state' => Group::STATE_ACTIVE,
                        'pvg_roles' => json_encode($group['roles'])
                    ), $ssoGroups)
                ),
                array_keys($existing[Group::SOURCE_CATCHALL] ?? array())
            );
        }
        $this->syncSource(Group::SOURCE_CATCHALL, $catchAllGroups, $existing[Group::SOURCE_CATCHALL] ?? array());
    }

    /**
     * @param array<int,int>|null $userIds null for all users, an empty array for none
     * @param array<int,int>|null $groupIds null for all groups
     * @return array<int,array{type: string, key: string}>
     */
    private function plan(?array $userIds, ?array $groupIds): array
    {
        $this->syncGroupDefinitions();

        $items = array();
        if ($userIds !== array()) {
            $items = $this->planAccounts($userIds);
        }

        foreach ($this->groups($groupIds) as $group) {
            if ($group['pvg_state'] !== Group::STATE_ACTIVE) {
                $items[] = array('type' => Queue::TYPE_GROUP, 'key' => (string) $group['pvg_uuid']);
                continue;
            }

            $desired = $this->desiredGroup($group);
            $hash = GroupState::hashOf((string) $group['pvg_name'], array_values($desired['members']));
            if ((string) $group['pvg_remote_id'] === '' || $hash !== (string) $group['pvg_hash']) {
                $items[] = array('type' => Queue::TYPE_GROUP, 'key' => (string) $group['pvg_uuid']);
            }
        }

        return $items;
    }

    /**
     * @param array<int,int>|null $userIds
     * @return array<int,array{type: string, key: string}>
     */
    private function planAccounts(?array $userIds): array
    {
        $population = $this->populationUserIds($userIds);

        $sql = 'SELECT pva_usr_id, pva_usr_uuid, pva_hash, pva_active, usr_id
                  FROM ' . ProvisioningTables::accounts() . '
                  LEFT JOIN ' . TBL_USERS . ' ON usr_uuid = pva_usr_uuid
                 WHERE pva_pvt_id = ?';
        $params = array($this->target->getId());
        if ($userIds !== null) {
            [$in, $inParams] = self::in($userIds);
            $sql .= ' AND pva_usr_id IN (' . $in . ')';
            $params = array_merge($params, $inParams);
        }
        $mappings = array();
        $items = array();
        foreach ($this->db->getArrayFromSql($sql, $params) as $mapping) {
            if ($mapping['usr_id'] === null) {
                // the user was deleted in Admidio
                $items[] = array('type' => Queue::TYPE_ACCOUNT, 'key' => (string) $mapping['pva_usr_uuid']);
                continue;
            }
            $mappings[(int) $mapping['usr_id']] = $mapping;
        }

        $candidates = array_values(array_unique(array_merge($population, array_keys($mappings))));
        $this->loadUsers($candidates);
        $populationSet = array_flip($population);
        $skipped = 0;

        foreach ($candidates as $userId) {
            if (!isset($this->users[$userId])) {
                continue;
            }
            $member = isset($populationSet[$userId]);
            $account = $this->buildAccount($userId, $member);
            $active = $member && $account->name !== '';
            if ($member && !$active) {
                ++$skipped;
            }
            $account = $account->withActive($active);
            $mapping = $mappings[$userId] ?? null;

            if ($active && ($mapping === null || $account->hash() !== (string) $mapping['pva_hash'])) {
                $items[] = array('type' => Queue::TYPE_ACCOUNT, 'key' => (string) $this->users[$userId]['usr_uuid']);
            } elseif (!$active && $mapping !== null && ((bool) $mapping['pva_active'] || $account->hash() !== (string) $mapping['pva_hash'])) {
                $items[] = array('type' => Queue::TYPE_ACCOUNT, 'key' => (string) $this->users[$userId]['usr_uuid']);
            }
        }

        if ($userIds === null) {
            $this->skippedUsers = $skipped;
        }
        return $items;
    }

    /**
     * The users of the target (section "Users of a target" above), restricted to the given ones.
     *
     * @param array<int,int>|null $userIds
     * @return array<int,int>
     */
    public function populationUserIds(?array $userIds): array
    {
        $users = $this->baseUserIds($userIds);
        foreach ($this->groups() as $group) {
            if ($group['pvg_state'] === Group::STATE_ACTIVE) {
                $users = array_merge($users, $this->groupMemberIds($group, $userIds));
            }
        }
        return self::ids($users);
    }

    /**
     * @param array<int,int>|null $userIds
     * @return array<int,int>
     */
    private function baseUserIds(?array $userIds): array
    {
        $members = $this->organizationMemberIds($userIds);
        $client = $this->ssoClient();

        if ($client !== null) {
            // the same rule as SSOClient::hasAccessRight(): without access roles everybody may sign
            // in, otherwise the members of the access roles and the administrators
            $accessRoles = $client->getAccessRolesIds();
            if ($accessRoles === array()) {
                return $members;
            }
            $allowed = array_merge(
                $this->roleMemberIds($accessRoles, $userIds),
                $this->roleMemberIds($this->administratorRoleIds(), $userIds)
            );
            return array_values(array_intersect($members, $allowed));
        }

        $filterRoles = $this->target->getUserRoleIds();
        if ($filterRoles === array()) {
            return $members;
        }
        return array_values(array_intersect($members, $this->roleMemberIds($filterRoles, $userIds)));
    }

    /**
     * Users with a valid membership in a valid role of the organization, as in
     * User::isMemberOfOrganization().
     *
     * @param array<int,int>|null $userIds
     * @return array<int,int>
     */
    private function organizationMemberIds(?array $userIds): array
    {
        if ($userIds === array()) {
            return array();
        }
        $valid = ProvisioningPlugin::validMembershipCondition();
        $sql = 'SELECT DISTINCT mem_usr_id
                  FROM ' . TBL_MEMBERS . '
                 INNER JOIN ' . TBL_ROLES . ' ON rol_id = mem_rol_id
                 INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id
                 INNER JOIN ' . TBL_USERS . ' ON usr_id = mem_usr_id
                 WHERE rol_valid = true AND usr_valid = true AND cat_org_id = ?
                   AND ' . $valid['sql'];
        $params = array_merge(array($this->organizationId), $valid['params']);
        if ($userIds !== null) {
            [$in, $inParams] = self::in($userIds);
            $sql .= ' AND mem_usr_id IN (' . $in . ')';
            $params = array_merge($params, $inParams);
        }
        return array_map('intval', $this->db->queryPrepared($sql, $params)->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Valid users with a valid membership (or leadership) in one of the roles.
     *
     * @param array<int,int> $roleIds
     * @param array<int,int>|null $userIds
     * @return array<int,int>
     */
    private function roleMemberIds(array $roleIds, ?array $userIds, bool $leadersOnly = false): array
    {
        $roleIds = self::ids($roleIds);
        if ($roleIds === array() || $userIds === array()) {
            return array();
        }
        $valid = ProvisioningPlugin::validMembershipCondition();
        [$in, $params] = self::in($roleIds);
        $sql = 'SELECT DISTINCT mem_usr_id
                  FROM ' . TBL_MEMBERS . '
                 INNER JOIN ' . TBL_ROLES . ' ON rol_id = mem_rol_id
                 INNER JOIN ' . TBL_USERS . ' ON usr_id = mem_usr_id
                 WHERE mem_rol_id IN (' . $in . ') AND rol_valid = true AND usr_valid = true
                   AND ' . $valid['sql'];
        $params = array_merge($params, $valid['params']);
        if ($leadersOnly) {
            $sql .= ' AND mem_leader = ?';
            $params[] = true;
        }
        if ($userIds !== null) {
            [$userIn, $userParams] = self::in($userIds);
            $sql .= ' AND mem_usr_id IN (' . $userIn . ')';
            $params = array_merge($params, $userParams);
        }
        return array_map('intval', $this->db->queryPrepared($sql, $params)->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The members of a group: members of its roles, and leaders of its negative role IDs.
     *
     * @param array<string,mixed> $group
     * @param array<int,int>|null $userIds
     * @return array<int,int>
     */
    private function groupMemberIds(array $group, ?array $userIds): array
    {
        $members = array();
        $leaders = array();
        foreach (Group::decodeRoleIds((string) $group['pvg_roles']) as $roleId) {
            if ($roleId > 0) {
                $members[] = $roleId;
            } else {
                $leaders[] = -$roleId;
            }
        }
        return self::ids(array_merge(
            $this->roleMemberIds($members, $userIds),
            $this->roleMemberIds($leaders, $userIds, true)
        ));
    }

    /**
     * @return array<int,int>
     */
    private function administratorRoleIds(): array
    {
        return array_map('intval', $this->db->queryPrepared(
            'SELECT rol_id FROM ' . TBL_ROLES . '
              INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id
              WHERE rol_administrator = true AND rol_valid = true AND cat_org_id = ?',
            array($this->organizationId)
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The given users that can be provisioned (valid, with an account name), as usr_id => usr_uuid.
     *
     * @param array<int,int> $userIds
     * @return array<int,string>
     */
    private function provisionable(array $userIds): array
    {
        $this->loadUsers($userIds);
        $result = array();
        foreach ($userIds as $userId) {
            if (isset($this->users[$userId]) && (bool) $this->users[$userId]['usr_valid']
                && $this->value($userId, $this->accountNameSource()) !== '') {
                $result[$userId] = (string) $this->users[$userId]['usr_uuid'];
            }
        }
        return $result;
    }

    private function buildAccount(int $userId, bool $active): Account
    {
        $attributes = array();
        foreach ($this->target->getAttributeMapping() as $entry) {
            $value = $this->value($userId, $entry['source']);
            if ($value !== '') {
                $attributes[$entry['path']] = $value;
            }
        }

        return new Account(
            (string) $this->users[$userId]['usr_uuid'],
            $this->value($userId, $this->accountNameSource()),
            $this->value($userId, 'EMAIL'),
            $active,
            $attributes
        );
    }

    /**
     * The Admidio field that gives the account name: the target's choice, otherwise the user
     * identifier of the linked SSO client, otherwise the default of the connector.
     */
    public function accountNameSource(): string
    {
        return $this->accountNameSource ??= $this->resolveAccountNameSource();
    }

    private function resolveAccountNameSource(): string
    {
        $source = (string) $this->target->getValue('pvt_account_name_source', 'database');
        if ($source !== '') {
            return $source;
        }
        $client = $this->ssoClient();
        if ($client !== null && $client->getUserIdField() !== '') {
            return $client->getUserIdField();
        }
        try {
            return Connectors::getClass((string) $this->target->getValue('pvt_connector', 'database'))::getDefaultAccountNameSource();
        } catch (\InvalidArgumentException) {
            return 'usr_login_name';
        }
    }

    private function value(int $userId, string $source): string
    {
        $user = $this->users[$userId] ?? null;
        if ($user === null) {
            return '';
        }
        return trim(match ($source) {
            'usr_id' => (string) $user['usr_id'],
            'usr_uuid' => (string) $user['usr_uuid'],
            'usr_login_name' => (string) $user['usr_login_name'],
            'fullname' => trim(($this->values[$userId]['FIRST_NAME'] ?? '') . ' ' . ($this->values[$userId]['LAST_NAME'] ?? '')),
            default => $this->values[$userId][$source] ?? ''
        });
    }

    /**
     * Read the users and the profile values the target needs, for users not read yet. Invalid
     * users are read too: an invalid user who has an account has to be deactivated.
     *
     * @param array<int,int> $userIds
     */
    private function loadUsers(array $userIds): void
    {
        $missing = array_values(array_diff(self::ids($userIds), array_keys($this->users)));
        if ($missing === array()) {
            return;
        }

        $fields = array('EMAIL', 'FIRST_NAME', 'LAST_NAME', $this->accountNameSource());
        foreach ($this->target->getAttributeMapping() as $entry) {
            $fields[] = $entry['source'];
        }
        $definitions = array_intersect_key($this->fieldDefinitions(), array_flip($fields));

        foreach (array_chunk($missing, 500) as $chunk) {
            [$in, $params] = self::in($chunk);
            foreach ($this->db->getArrayFromSql(
                'SELECT usr_id, usr_uuid, usr_login_name, usr_valid FROM ' . TBL_USERS . ' WHERE usr_id IN (' . $in . ')',
                $params
            ) as $row) {
                $this->users[(int) $row['usr_id']] = $row;
                $this->values[(int) $row['usr_id']] = array();
            }

            if ($definitions === array()) {
                continue;
            }
            $fieldIds = array();
            foreach ($definitions as $name => $definition) {
                $fieldIds[$definition['id']] = $name;
            }
            [$fieldIn, $fieldParams] = self::in(array_keys($fieldIds));
            foreach ($this->db->getArrayFromSql(
                'SELECT usd_usr_id, usd_usf_id, usd_value FROM ' . TBL_USER_DATA . '
                  WHERE usd_usr_id IN (' . $in . ') AND usd_usf_id IN (' . $fieldIn . ')',
                array_merge($params, $fieldParams)
            ) as $row) {
                $userId = (int) $row['usd_usr_id'];
                if (!isset($this->values[$userId])) {
                    continue;
                }
                $name = $fieldIds[(int) $row['usd_usf_id']];
                $this->values[$userId][$name] = self::displayValue($definitions[$name], (string) $row['usd_value']);
            }
        }
    }

    /**
     * Option fields store the ID of the option; the target gets its text.
     *
     * @param array{id:int,type:string,options:array<int,string>} $definition
     */
    private static function displayValue(array $definition, string $value): string
    {
        if ($definition['options'] === array()) {
            return $value;
        }
        $texts = array();
        foreach (explode(',', $value) as $optionId) {
            $optionId = (int) trim($optionId);
            if (isset($definition['options'][$optionId])) {
                $texts[] = $definition['options'][$optionId];
            }
        }
        return implode(', ', $texts);
    }

    /**
     * @return array<string,array{id:int,type:string,options:array<int,string>}>
     */
    private function fieldDefinitions(): array
    {
        if ($this->fieldDefinitions !== null) {
            return $this->fieldDefinitions;
        }

        $this->fieldDefinitions = array();
        foreach ($this->db->getArrayFromSql('SELECT usf_id, usf_name_intern, usf_type FROM ' . TBL_USER_FIELDS) as $row) {
            $this->fieldDefinitions[(string) $row['usf_name_intern']] = array(
                'id' => (int) $row['usf_id'],
                'type' => (string) $row['usf_type'],
                'options' => array()
            );
        }

        $byId = array();
        foreach ($this->fieldDefinitions as $name => $definition) {
            if (in_array($definition['type'], array('DROPDOWN', 'DROPDOWN_MULTISELECT', 'RADIO_BUTTON'), true)) {
                $byId[$definition['id']] = $name;
            }
        }
        if ($byId !== array()) {
            [$in, $params] = self::in(array_keys($byId));
            foreach ($this->db->getArrayFromSql(
                'SELECT ufo_id, ufo_usf_id, ufo_value FROM ' . TBL_USER_FIELD_OPTIONS . ' WHERE ufo_usf_id IN (' . $in . ')',
                $params
            ) as $row) {
                $this->fieldDefinitions[$byId[(int) $row['ufo_usf_id']]]['options'][(int) $row['ufo_id']]
                    = Language::translateIfTranslationStrId((string) $row['ufo_value']);
            }
        }

        return $this->fieldDefinitions;
    }

    /**
     * Whether the connector of the target has groups. A target without them gets accounts only.
     */
    public function groupsSupported(): bool
    {
        if ($this->groupsSupported === null) {
            try {
                $this->groupsSupported = (bool) (Connectors::forTarget($this->target)->getCapabilities()['groups'] ?? true);
            } catch (\InvalidArgumentException) {
                // a connector that is not registered (any more): plan as if it had groups
                $this->groupsSupported = true;
            }
        }
        return $this->groupsSupported;
    }

    /**
     * The linked SSO client, or null if the target is not linked.
     *
     * @throws RuntimeException if the target is linked to a client that no longer exists. Planning
     *                          stops then instead of deactivating every account.
     */
    public function ssoClient(): ?SSOClient
    {
        if ($this->ssoClientLoaded) {
            return $this->ssoClient;
        }
        $this->ssoClientLoaded = true;

        $link = $this->target->getSsoLink();
        if ($link === null) {
            return null;
        }

        [$type, $clientId] = $link;
        $client = $type === 'saml' ? new SAMLClient($this->db, $clientId) : new OIDCClient($this->db, $clientId);
        $prefix = $type === 'saml' ? 'smc' : 'ocl';

        if ($client->isNewRecord() || (int) $client->getValue($prefix . '_org_id') !== $this->organizationId) {
            // a language key: the message is shown on the status page
            throw new RuntimeException('PLG_PROVISIONING_SSO_CLIENT_MISSING');
        }

        $this->ssoClient = $client;
        return $client;
    }

    /**
     * The catch-all groups: every valid role of the organization that no other group maps
     * explicitly, as long as it has members or already has a group. Leaders are not included, the
     * same as in the SSO role mapping.
     *
     * @param array<int,array<string,mixed>> $explicitGroups Manual and SSO groups.
     * @param array<int,string> $existingKeys Role IDs of the existing catch-all groups.
     * @return array<string,array{name: string, roles: array<int,int>}>
     */
    private function catchAllGroups(array $explicitGroups, array $existingKeys): array
    {
        $mapped = array();
        foreach ($explicitGroups as $group) {
            if (($group['pvg_state'] ?? Group::STATE_ACTIVE) !== Group::STATE_ACTIVE) {
                continue;
            }
            foreach (Group::decodeRoleIds((string) $group['pvg_roles']) as $roleId) {
                if ($roleId > 0) {
                    $mapped[$roleId] = true;
                }
            }
        }

        $valid = ProvisioningPlugin::validMembershipCondition();
        $sql = 'SELECT rol_id, rol_name
                  FROM ' . TBL_ROLES . '
                 INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id
                 WHERE rol_valid = true AND cat_org_id = ?
                   AND (EXISTS (SELECT 1 FROM ' . TBL_MEMBERS . ' WHERE mem_rol_id = rol_id AND ' . $valid['sql'] . ')';
        $params = array_merge(array($this->organizationId), $valid['params']);
        $existingIds = self::ids(array_map('intval', $existingKeys));
        if ($existingIds !== array()) {
            [$in, $inParams] = self::in($existingIds);
            $sql .= ' OR rol_id IN (' . $in . ')';
            $params = array_merge($params, $inParams);
        }
        $sql .= ')';

        $groups = array();
        foreach ($this->db->getArrayFromSql($sql, $params) as $role) {
            $roleId = (int) $role['rol_id'];
            if (!isset($mapped[$roleId])) {
                $groups[(string) $roleId] = array(
                    'name' => Language::translateIfTranslationStrId((string) $role['rol_name']),
                    'roles' => array($roleId)
                );
            }
        }
        return $groups;
    }

    /**
     * @param array<string,array{name: string, roles: array<int,int>}> $desired by key
     * @param array<string,array<string,mixed>> $existing rows by key
     */
    private function syncSource(string $source, array $desired, array $existing): void
    {
        $table = ProvisioningTables::groups();

        foreach ($desired as $key => $group) {
            $key = (string) $key;
            $roles = Group::encodeRoleIds($group['roles']);
            $row = $existing[$key] ?? null;

            if ($row === null) {
                $this->db->queryPrepared(
                    'INSERT INTO ' . $table . ' (pvg_uuid, pvg_pvt_id, pvg_source, pvg_key, pvg_name, pvg_roles, pvg_state)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    array((string) Uuid::uuid4(), $this->target->getId(), $source, $key, $group['name'], $roles, Group::STATE_ACTIVE)
                );
            } elseif ($row['pvg_name'] !== $group['name'] || $row['pvg_roles'] !== $roles || $row['pvg_state'] !== Group::STATE_ACTIVE) {
                $this->db->queryPrepared(
                    'UPDATE ' . $table . ' SET pvg_name = ?, pvg_roles = ?, pvg_state = ? WHERE pvg_id = ?',
                    array($group['name'], $roles, Group::STATE_ACTIVE, (int) $row['pvg_id'])
                );
            }
        }

        foreach ($existing as $key => $row) {
            if (isset($desired[(string) $key]) || $row['pvg_state'] === Group::STATE_DELETE) {
                continue;
            }
            if ((string) $row['pvg_remote_id'] === '') {
                $this->db->queryPrepared('DELETE FROM ' . $table . ' WHERE pvg_id = ?', array((int) $row['pvg_id']));
            } else {
                $this->db->queryPrepared(
                    'UPDATE ' . $table . ' SET pvg_state = ? WHERE pvg_id = ?',
                    array(Group::STATE_DELETE, (int) $row['pvg_id'])
                );
            }
        }
    }

    /**
     * Groups that use one of the roles, as members or as leaders.
     *
     * @param array<int,int> $roleIds
     * @return array<int,int>
     */
    private function groupsOfRoles(array $roleIds): array
    {
        $roleIds = array_flip(self::ids($roleIds));
        $groupIds = array();
        foreach ($this->groups() as $group) {
            foreach (Group::decodeRoleIds((string) $group['pvg_roles']) as $roleId) {
                if (isset($roleIds[abs($roleId)])) {
                    $groupIds[] = (int) $group['pvg_id'];
                    break;
                }
            }
        }
        return $groupIds;
    }

    /**
     * Groups that use a role the users have or had a membership in, and groups the users are
     * provisioned members of.
     *
     * @param array<int,int> $userIds
     * @return array<int,int>
     */
    private function groupsOfUsers(array $userIds): array
    {
        $userIds = self::ids($userIds);
        if ($userIds === array()) {
            return array();
        }
        [$in, $params] = self::in($userIds);

        $roleIds = array_map('intval', $this->db->queryPrepared(
            'SELECT DISTINCT mem_rol_id FROM ' . TBL_MEMBERS . ' WHERE mem_usr_id IN (' . $in . ')',
            $params
        )->fetchAll(\PDO::FETCH_COLUMN));

        $memberOf = array_map('intval', $this->db->queryPrepared(
            'SELECT DISTINCT pvm_pvg_id
               FROM ' . ProvisioningTables::members() . '
              INNER JOIN ' . ProvisioningTables::accounts() . ' ON pva_id = pvm_pva_id
              WHERE pva_pvt_id = ? AND pva_usr_id IN (' . $in . ')',
            array_merge(array($this->target->getId()), $params)
        )->fetchAll(\PDO::FETCH_COLUMN));

        return array_values(array_unique(array_merge($this->groupsOfRoles($roleIds), $memberOf)));
    }

    /**
     * Placeholders and parameters of an IN list.
     *
     * @param array<int,int|string> $values
     * @return array{0: string, 1: array<int,int|string>}
     */
    private static function in(array $values): array
    {
        $values = array_values($values);
        return array(implode(', ', array_fill(0, count($values), '?')), $values);
    }

    /**
     * @param array<int,mixed> $ids
     * @return array<int,int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }
}
