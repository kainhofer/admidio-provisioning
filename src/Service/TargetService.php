<?php
namespace AdmidioPlugin\Provisioning\Service;

use Admidio\Infrastructure\Database;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Language;
use AdmidioPlugin\Provisioning\Connector\Connectors;
use AdmidioPlugin\Provisioning\Entity\Group;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use AdmidioPlugin\Provisioning\ProvisioningTables;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Saves and deletes targets and their manually configured groups.
 *
 * Form fields of the connector section are named **cfg_<field>**; the attribute mapping uses
 * **map_<n>** for the attributes the connector offers and **map_custom_path_<n>** /
 * **map_custom_source_<n>** for additional ones.
 */
class TargetService
{
    public const CUSTOM_MAPPING_ROWS = 3;

    /** The sources of attributes besides the profile fields. */
    public const USER_SOURCES = array('usr_id', 'usr_uuid', 'usr_login_name', 'fullname');

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Read a target of the current organization by its UUID, or a new one for an empty UUID.
     *
     * @throws Exception
     */
    public function getTarget(string $uuid): Target
    {
        $target = new Target($this->db);
        if ($uuid !== '' && (!$target->readDataByUuid($uuid) || $target->isNewRecord())) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        return $target;
    }

    /**
     * Read a group of a target by its UUID, or a new one for an empty UUID.
     *
     * @throws Exception
     */
    public function getGroup(Target $target, string $uuid): Group
    {
        $group = new Group($this->db);
        if ($uuid !== '') {
            if (!$group->readDataByUuid($uuid) || (int) $group->getValue('pvg_pvt_id') !== $target->getId()) {
                throw new Exception('SYS_INVALID_PAGE_VIEW');
            }
        }
        return $group;
    }

    /**
     * @param array<string,mixed> $values The validated form values.
     * @throws Exception
     */
    public function save(Target $target, array $values): Target
    {
        global $gCurrentOrgId;

        self::requireAdministrator();
        $isNew = $target->isNewRecord();

        if ($isNew) {
            $connector = (string) ($values['pvt_connector'] ?? '');
            if (!array_key_exists($connector, Connectors::all())) {
                throw new Exception('SYS_FIELD_EMPTY', array('PLG_PROVISIONING_CONNECTOR'));
            }
            $target->setValue('pvt_org_id', (int) $gCurrentOrgId);
            $target->setValue('pvt_connector', $connector);
        } elseif ((int) $target->getValue('pvt_org_id') !== (int) $gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        } else {
            $this->refuseWhileDeprovisioning($target);
        }

        $name = trim((string) ($values['pvt_name'] ?? ''));
        if ($name === '') {
            throw new Exception('SYS_FIELD_EMPTY', array('SYS_NAME'));
        }
        $target->setValue('pvt_name', $name);
        $target->setValue('pvt_enabled', !empty($values['pvt_enabled']));

        $this->applyConnectorConfig($target, $values);
        $this->applySsoLink($target, (string) ($values['pvt_sso_link'] ?? ''));

        $target->setUserRoleIds($this->validRoleIds((array) ($values['pvt_user_roles'] ?? array())));

        $sources = $this->allowedSources();
        $nameSource = (string) ($values['pvt_account_name_source'] ?? '');
        $target->setValue('pvt_account_name_source', in_array($nameSource, $sources, true) ? $nameSource : '');
        $target->setAttributeMapping($this->attributeMapping($target, $values, $sources));

        $target->setValue('pvt_group_catchall', !empty($values['pvt_group_catchall']));
        $interval = (int) ($values['pvt_verify_interval'] ?? 0);
        $target->setValue('pvt_verify_interval', in_array($interval, self::verifyIntervals(), true) ? $interval : 0);

        $this->db->startTransaction();
        try {
            $target->save();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }

        // The configuration may change who belongs to the target and what is sent: reconcile, and
        // end a pause that a wrong configuration caused.
        $status = new TargetStatus($this->db);
        $status->requestReconciliation($target->getId());
        $status->resume($target->getId());
        ChangeCollector::reset();

        return $target;
    }

    /**
     * Disconnect: remove the target with its mappings and queue. Nothing is changed on the target
     * system; its accounts and groups remain.
     *
     * @throws Exception
     */
    public function disconnect(Target $target): void
    {
        $this->requireOwnTarget($target);
        $this->removeTarget($target);
    }

    /**
     * Deprovision: delete the groups and then the accounts that Admidio created on the target
     * system, and remove the target afterwards. The work goes through the queue, so it survives
     * interruptions and failures of the target; the target is disabled, so that nothing new is
     * planned for it in the meantime.
     *
     * @throws Exception
     */
    public function deprovision(Target $target): void
    {
        $this->requireOwnTarget($target);
        $targetId = $target->getId();
        $queue = new Queue($this->db);

        $target->setValue('pvt_enabled', false);
        $target->save();

        $queue->clear($targetId);
        (new TargetStatus($this->db))->startDeprovisioning($targetId);

        // groups that were never created on the target can go at once
        $this->db->queryPrepared(
            'DELETE FROM ' . ProvisioningTables::groups() . ' WHERE pvg_pvt_id = ? AND pvg_remote_id IS NULL',
            array($targetId)
        );
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::groups() . ' SET pvg_state = ? WHERE pvg_pvt_id = ?',
            array(Group::STATE_DELETE, $targetId)
        );
        foreach ($this->db->queryPrepared(
            'SELECT pvg_uuid FROM ' . ProvisioningTables::groups() . ' WHERE pvg_pvt_id = ?',
            array($targetId)
        )->fetchAll(\PDO::FETCH_COLUMN) as $groupUuid) {
            $queue->enqueue($targetId, Queue::TYPE_GROUP, (string) $groupUuid);
        }
        foreach ($this->db->queryPrepared(
            'SELECT pva_usr_uuid FROM ' . ProvisioningTables::accounts() . ' WHERE pva_pvt_id = ?',
            array($targetId)
        )->fetchAll(\PDO::FETCH_COLUMN) as $userUuid) {
            $queue->enqueue($targetId, Queue::TYPE_ACCOUNT, (string) $userUuid);
        }

        ChangeCollector::reset();
        RequestRunner::markQueued();
    }

    /**
     * Remove a target with everything that belongs to it, without asking for rights: disconnect()
     * and the end of a deprovisioning, which may happen in a cron run or at the end of the request
     * of any user, after the administrator started it.
     */
    public function removeTarget(Target $target): void
    {
        // The foreign keys remove the dependent rows. They are deleted explicitly anyway, because
        // MySQL with foreign key checks switched off would leave them behind.
        $targetId = $target->getId();
        $this->db->startTransaction();
        try {
            $this->db->queryPrepared(
                'DELETE FROM ' . ProvisioningTables::members() . ' WHERE pvm_pvg_id IN
                    (SELECT pvg_id FROM ' . ProvisioningTables::groups() . ' WHERE pvg_pvt_id = ?)',
                array($targetId)
            );
            foreach (array(ProvisioningTables::queue() => 'pvq', ProvisioningTables::status() => 'pvs',
                           ProvisioningTables::accounts() => 'pva', ProvisioningTables::groups() => 'pvg') as $table => $prefix) {
                $this->db->queryPrepared('DELETE FROM ' . $table . ' WHERE ' . $prefix . '_pvt_id = ?', array($targetId));
            }
            $target->delete();
            $this->db->endTransaction();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw $exception;
        }
        ChangeCollector::reset();
    }

    public function enable(Target $target, bool $enabled): void
    {
        $this->requireOwnTarget($target);
        $this->refuseWhileDeprovisioning($target);
        $target->setValue('pvt_enabled', $enabled);
        $target->save();
        if ($enabled) {
            (new TargetStatus($this->db))->requestReconciliation($target->getId());
        }
        ChangeCollector::reset();
    }

    /**
     * Test the connection of a saved target and store the capabilities its connector reports.
     *
     * @return array<string,string>
     */
    public function test(Target $target): array
    {
        self::requireAdministrator();
        $details = Connectors::forTarget($target)->test();
        (new TargetStatus($this->db))->setCapabilities($target->getId(), $details);
        return $details;
    }

    /**
     * @param array<string,mixed> $values
     * @throws Exception
     */
    public function saveGroup(Target $target, Group $group, array $values): Group
    {
        self::requireAdministrator();

        if ($group->isNewRecord()) {
            // a manual group keeps its UUID as key, so it can be renamed without being created again
            $uuid = (string) Uuid::uuid4();
            $group->setValue('pvg_uuid', $uuid);
            $group->setValue('pvg_key', $uuid);
            $group->setValue('pvg_pvt_id', $target->getId());
            $group->setValue('pvg_source', Group::SOURCE_MANUAL);
            $group->setValue('pvg_state', Group::STATE_ACTIVE);
        } elseif ($group->getValue('pvg_source') !== Group::SOURCE_MANUAL) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $name = trim((string) ($values['pvg_name'] ?? ''));
        if ($name === '') {
            throw new Exception('SYS_FIELD_EMPTY', array('SYS_NAME'));
        }

        $members = $this->validRoleIds((array) ($values['pvg_member_roles'] ?? array()));
        $leaders = $this->validRoleIds((array) ($values['pvg_leader_roles'] ?? array()));
        if ($members === array() && $leaders === array()) {
            throw new Exception('SYS_FIELD_EMPTY', array('SYS_ROLES'));
        }

        $group->setValue('pvg_name', $name);
        $group->setRoleIds(array_merge($members, array_map(static fn(int $id): int => -$id, $leaders)));
        $group->save();

        (new TargetStatus($this->db))->requestReconciliation($target->getId());
        return $group;
    }

    /**
     * Delete a manual group. If it exists on the target it is deleted there first, by the worker.
     *
     * @throws Exception
     */
    public function deleteGroup(Target $target, Group $group): void
    {
        self::requireAdministrator();
        if ($group->getValue('pvg_source') !== Group::SOURCE_MANUAL) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        if ((string) $group->getValue('pvg_remote_id', 'database') === '') {
            $group->delete();
            return;
        }

        $group->setValue('pvg_state', Group::STATE_DELETE);
        $group->save();
        (new Queue($this->db))->enqueue($target->getId(), Queue::TYPE_GROUP, (string) $group->getValue('pvg_uuid'));
        RequestRunner::markQueued();
    }

    /**
     * The sources an attribute or the account name may come from.
     *
     * @return array<int,string>
     */
    public function allowedSources(): array
    {
        $sources = self::USER_SOURCES;
        foreach ($this->db->queryPrepared('SELECT usf_name_intern FROM ' . TBL_USER_FIELDS)->fetchAll(\PDO::FETCH_COLUMN) as $name) {
            $sources[] = (string) $name;
        }
        return $sources;
    }

    /**
     * Roles that may be used by a target of the current organization.
     *
     * @return array<int,array{0:int,1:string,2:string}> rol_id, name, category name - for select boxes
     */
    public function selectableRoles(): array
    {
        global $gCurrentOrgId;

        $roles = array();
        foreach ($this->db->getArrayFromSql(
            'SELECT rol_id, rol_name, cat_name
               FROM ' . TBL_ROLES . '
              INNER JOIN ' . TBL_CATEGORIES . ' ON cat_id = rol_cat_id
              WHERE rol_valid = true AND (cat_org_id = ? OR cat_org_id IS NULL)
              ORDER BY cat_sequence, rol_name',
            array((int) $gCurrentOrgId)
        ) as $row) {
            $roles[] = array(
                (int) $row['rol_id'],
                Language::translateIfTranslationStrId((string) $row['rol_name']),
                Language::translateIfTranslationStrId((string) $row['cat_name'])
            );
        }
        return $roles;
    }

    /**
     * The SSO clients of the current organization, as "saml:<id>" / "oidc:<id>" => name.
     *
     * @return array<string,string>
     */
    public function ssoClients(): array
    {
        global $gCurrentOrgId;

        $clients = array();
        foreach (array('saml' => array(TBL_SAML_CLIENTS, 'smc'), 'oidc' => array(TBL_OIDC_CLIENTS, 'ocl')) as $type => [$table, $prefix]) {
            foreach ($this->db->getArrayFromSql(
                'SELECT ' . $prefix . '_id AS id, ' . $prefix . '_client_name AS name FROM ' . $table . '
                  WHERE ' . $prefix . '_org_id = ? ORDER BY ' . $prefix . '_client_name',
                array((int) $gCurrentOrgId)
            ) as $row) {
                $clients[$type . ':' . $row['id']] = strtoupper($type) . ': ' . $row['name'];
            }
        }
        return $clients;
    }

    /**
     * @return array<int,int> Verification intervals: 0 manual, -1 hourly, otherwise days.
     */
    public static function verifyIntervals(): array
    {
        return array(Target::VERIFY_MANUAL, Target::VERIFY_HOURLY, 1, 7, 30);
    }

    /**
     * @param array<string,mixed> $values
     */
    private function applyConnectorConfig(Target $target, array $values): void
    {
        $class = Connectors::getClass((string) $target->getValue('pvt_connector', 'database'));
        $config = $target->getConfig();
        $secrets = $target->getSecrets();

        foreach ($class::getConfigFields() as $field => $definition) {
            $value = $values['cfg_' . $field] ?? null;
            $type = (string) ($definition['type'] ?? 'string');

            if (!empty($definition['secret'])) {
                // an empty secret field keeps the stored value
                if (is_string($value) && $value !== '') {
                    $secrets[$field] = $value;
                }
                continue;
            }

            $config[$field] = match ($type) {
                'boolean' => !empty($value),
                'integer' => (int) $value,
                default => trim((string) $value)
            };
            if ($type === 'enum' && !array_key_exists($config[$field], $definition['values'] ?? array())) {
                $config[$field] = (string) ($definition['default'] ?? '');
            }
            if (!empty($definition['required']) && $config[$field] === '') {
                throw new Exception('SYS_FIELD_EMPTY', array($definition['label'] ?? $field));
            }
        }

        $target->setConfig($config);
        $target->setSecrets($secrets);
    }

    /**
     * @throws Exception
     */
    private function applySsoLink(Target $target, string $link): void
    {
        if ($link === '') {
            $target->setValue('pvt_sso_type', '');
            $target->setValue('pvt_sso_client_id', '');
            return;
        }
        if (!array_key_exists($link, $this->ssoClients())) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }
        [$type, $clientId] = explode(':', $link, 2);
        $target->setValue('pvt_sso_type', $type);
        $target->setValue('pvt_sso_client_id', (int) $clientId);
    }

    /**
     * @param array<string,mixed> $values
     * @param array<int,string> $sources
     * @return array<int,array{path: string, source: string}>
     */
    private function attributeMapping(Target $target, array $values, array $sources): array
    {
        $class = Connectors::getClass((string) $target->getValue('pvt_connector', 'database'));
        $mapping = array();

        $index = 0;
        foreach (array_keys($class::getAttributeTargets()) as $path) {
            $source = (string) ($values['map_' . $index++] ?? '');
            if (in_array($source, $sources, true)) {
                $mapping[] = array('path' => $path, 'source' => $source);
            }
        }

        for ($row = 1; $row <= self::CUSTOM_MAPPING_ROWS; ++$row) {
            $path = trim((string) ($values['map_custom_path_' . $row] ?? ''));
            $source = (string) ($values['map_custom_source_' . $row] ?? '');
            if ($path !== '' && in_array($source, $sources, true) && preg_match('/^[A-Za-z0-9:._-]+$/', $path)) {
                $mapping[] = array('path' => $path, 'source' => $source);
            }
        }

        return $mapping;
    }

    /**
     * @param array<int,mixed> $roleIds
     * @return array<int,int>
     */
    private function validRoleIds(array $roleIds): array
    {
        $allowed = array_column($this->selectableRoles(), 0);
        return array_values(array_intersect(array_map('intval', $roleIds), $allowed));
    }

    /**
     * @throws Exception
     */
    private function requireOwnTarget(Target $target): void
    {
        global $gCurrentOrgId;

        self::requireAdministrator();
        if ($target->isNewRecord() || (int) $target->getValue('pvt_org_id') !== (int) $gCurrentOrgId) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    }

    /**
     * @throws Exception
     */
    private function refuseWhileDeprovisioning(Target $target): void
    {
        if ((new TargetStatus($this->db))->isDeprovisioning($target->getId())) {
            throw new Exception('PLG_PROVISIONING_DEPROVISIONING_LOCKED');
        }
    }

    /**
     * @throws Exception
     */
    private static function requireAdministrator(): void
    {
        if (!ProvisioningPlugin::isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    }
}
