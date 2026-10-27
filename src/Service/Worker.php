<?php
namespace AdmidioPlugin\Provisioning\Service;

use Admidio\Infrastructure\Database;
use AdmidioPlugin\Provisioning\Connector\Account;
use AdmidioPlugin\Provisioning\Connector\Connector;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\Connector\Connectors;
use AdmidioPlugin\Provisioning\Connector\GroupState;
use AdmidioPlugin\Provisioning\Entity\Group;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use AdmidioPlugin\Provisioning\ProvisioningTables;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Takes due items from the queue of a target, recalculates the desired state of that one account
 * or group, and asks the connector to apply it.
 *
 * Only one worker processes a target at a time (lease on the status row). Accounts are processed
 * before groups, so new members exist before they are added to a group.
 */
class Worker
{
    /** After this many temporary errors in one run the target is paused. */
    private const MAX_RETRY_ERRORS_PER_RUN = 3;
    private const PAUSE_AFTER_RETRY_ERRORS = 1800;
    private const PAUSE_AFTER_TARGET_ERROR = 3600;

    private Queue $queue;
    private TargetStatus $status;
    private Planner $planner;
    private Connector $connector;
    private string $token;

    /** @var array<int,bool> Group items put back in this run because members were missing. */
    private array $deferredGroups = array();

    /** Whether the target is being deprovisioned: everything is deleted, groups first. */
    private bool $deprovisioning = false;

    public function __construct(private readonly Database $db, private readonly Target $target, ?Connector $connector = null)
    {
        $this->queue = new Queue($db);
        $this->status = new TargetStatus($db);
        $this->planner = new Planner($db, $target);
        $this->connector = $connector ?? Connectors::forTarget($target);
    }

    /**
     * Process due items until the queue is empty or the time budget is used up.
     *
     * @param float|null $budgetSeconds null for no limit
     * @return array{processed: int, failed: int, attention: int, state: string, message: string}
     */
    public function run(?float $budgetSeconds = null): array
    {
        $result = array('processed' => 0, 'failed' => 0, 'attention' => 0, 'state' => TargetStatus::STATE_OK, 'message' => '');
        $targetId = $this->target->getId();

        if ($this->status->isPaused($targetId)) {
            $result['state'] = TargetStatus::STATE_PAUSED;
            $result['message'] = (string) ($this->status->get($targetId)['pvs_message'] ?? '');
            return $result;
        }

        $this->token = (string) Uuid::uuid4();
        if (!$this->status->claim($targetId, $this->token)) {
            $result['message'] = 'busy';
            return $result;
        }

        $this->deprovisioning = $this->status->isDeprovisioning($targetId);
        $deadline = $budgetSeconds === null ? null : microtime(true) + $budgetSeconds;
        $retryErrors = 0;
        $seen = array();

        try {
            while ($deadline === null || microtime(true) < $deadline) {
                $items = array_filter(
                    $this->queue->dueItems($targetId, 50, $this->deprovisioning),
                    static fn(array $item): bool => !isset($seen[(int) $item['pvq_id']])
                );
                if ($items === array()) {
                    break;
                }
                $this->status->renew($targetId, $this->token);

                foreach ($items as $item) {
                    if ($deadline !== null && microtime(true) >= $deadline) {
                        break 2;
                    }
                    $seen[(int) $item['pvq_id']] = true;
                    if (!$this->queue->claim((int) $item['pvq_id'], $this->token)) {
                        continue;
                    }

                    try {
                        if ($this->process($item)) {
                            ++$result['processed'];
                        } else {
                            // put back once in this run, after the accounts it is waiting for
                            unset($seen[(int) $item['pvq_id']]);
                        }
                    } catch (Throwable $exception) {
                        $error = $exception instanceof ConnectorError
                            ? $exception
                            : new ConnectorError(ConnectorError::RETRY, $exception->getMessage(), 0, $exception);
                        $this->logError($item, $exception);
                        ++$result['failed'];

                        if ($error->kind === ConnectorError::TARGET) {
                            $this->queue->defer((int) $item['pvq_id'], $this->token, 0);
                            $this->status->pause($targetId, self::PAUSE_AFTER_TARGET_ERROR, $error->getMessage());
                            $result['state'] = TargetStatus::STATE_PAUSED;
                            $result['message'] = $error->getMessage();
                            break 2;
                        }

                        if ($this->queue->fail($item, $this->token, $error)) {
                            ++$result['attention'];
                        }
                        if ($error->kind === ConnectorError::RETRY && ++$retryErrors >= self::MAX_RETRY_ERRORS_PER_RUN) {
                            $this->status->pause($targetId, self::PAUSE_AFTER_RETRY_ERRORS, $error->getMessage());
                            $result['state'] = TargetStatus::STATE_PAUSED;
                            $result['message'] = $error->getMessage();
                            break 2;
                        }
                    }
                }
            }
        } finally {
            $this->status->release($targetId, $this->token);
        }

        if ($result['state'] === TargetStatus::STATE_OK && $result['failed'] > 0) {
            $result['state'] = TargetStatus::STATE_PARTIAL;
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $item
     * @return bool false if the item was put back to be processed again later in this run
     */
    private function process(array $item): bool
    {
        if ($item['pvq_type'] === Queue::TYPE_ACCOUNT) {
            $this->processAccount((string) $item['pvq_key']);
            $this->queue->complete((int) $item['pvq_id'], $this->token);
            return true;
        }

        if ($this->processGroup((string) $item['pvq_key'], (int) $item['pvq_id'])) {
            $this->queue->complete((int) $item['pvq_id'], $this->token);
            return true;
        }
        return false;
    }

    private function processAccount(string $userUuid): void
    {
        $mapping = $this->accountMapping($userUuid);
        $capabilities = $this->connector->getCapabilities();
        $desired = $this->deprovisioning
            ? array('state' => 'deleted', 'account' => null, 'usr_id' => 0)
            : $this->planner->desiredAccount($userUuid);

        if ($desired['state'] === 'active') {
            $this->provisionAccount($desired['account'], $desired['usr_id'], $mapping, $capabilities);
            return;
        }

        if ($mapping === null) {
            return;
        }

        // Out of the target: leave every group first, then deactivate or delete.
        $this->removeFromAllGroups($mapping);
        $remoteId = (string) $mapping['pva_remote_id'];

        if ($desired['state'] === 'deleted') {
            if ($capabilities['accounts'] && $remoteId !== '') {
                $this->connector->deleteAccount($remoteId);
            }
            $this->db->queryPrepared(
                'DELETE FROM ' . ProvisioningTables::accounts() . ' WHERE pva_id = ?',
                array((int) $mapping['pva_id'])
            );
            return;
        }

        $account = $desired['account'];
        if ($capabilities['accounts'] && !$capabilities['deactivate']) {
            // A target that cannot deactivate accounts loses the account instead. Should the user
            // come back, a new account is created.
            if ($remoteId !== '') {
                $this->connector->deleteAccount($remoteId);
            }
            $this->db->queryPrepared(
                'DELETE FROM ' . ProvisioningTables::accounts() . ' WHERE pva_id = ?',
                array((int) $mapping['pva_id'])
            );
            return;
        }
        if ($capabilities['accounts'] && $remoteId !== '' && (bool) $mapping['pva_active']) {
            try {
                $this->connector->deactivateAccount($remoteId, $account);
            } catch (ConnectorError $error) {
                if ($error->kind !== ConnectorError::MISSING) {
                    throw $error;
                }
                // already gone on the target: nothing to deactivate
                $remoteId = '';
            }
        }
        $this->saveAccountMapping($mapping, $desired['usr_id'], $remoteId, (string) $mapping['pva_member_key'], $account);
    }

    /**
     * Create, take over or update an account that belongs to the target.
     *
     * @param array<string,mixed>|null $mapping
     * @param array<string,mixed> $capabilities
     */
    private function provisionAccount(Account $account, int $userId, ?array $mapping, array $capabilities): void
    {
        $remoteId = $mapping === null ? '' : (string) $mapping['pva_remote_id'];

        if ($capabilities['accounts']) {
            if ($remoteId !== '' && $mapping !== null && (string) $mapping['pva_hash'] !== $account->hash()) {
                try {
                    $this->connector->updateAccount($remoteId, $account);
                } catch (ConnectorError $error) {
                    if ($error->kind !== ConnectorError::MISSING) {
                        throw $error;
                    }
                    // deleted on the target: look it up again, or create it anew
                    $remoteId = '';
                }
            }
            if ($remoteId === '') {
                $remoteId = (string) $this->connector->findAccount($account);
                if ($remoteId === '') {
                    $remoteId = $this->connector->createAccount($account);
                } else {
                    $this->connector->updateAccount($remoteId, $account);
                }
            }
        }

        $memberKey = $this->connector->getMemberKey($remoteId === '' ? null : $remoteId, $account);
        $previousKey = $mapping === null ? '' : (string) $mapping['pva_member_key'];
        $accountId = $this->saveAccountMapping($mapping, $userId, $remoteId, $memberKey, $account);

        // Targets whose members are email addresses: a changed address changes the membership.
        if ($previousKey !== '' && $previousKey !== $memberKey) {
            foreach ($this->groupsOfAccount($accountId) as $group) {
                $state = new GroupState((string) $group['pvg_uuid'], (string) $group['pvg_name'], $this->memberKeys((int) $group['pvg_id']));
                $this->connector->changeMembers((string) $group['pvg_remote_id'], $state, array($memberKey), array($previousKey));
            }
        }
    }

    /**
     * Remove an account from all groups it was provisioned into.
     *
     * @param array<string,mixed> $mapping
     */
    private function removeFromAllGroups(array $mapping): void
    {
        $accountId = (int) $mapping['pva_id'];
        $memberKey = (string) $mapping['pva_member_key'];

        foreach ($this->groupsOfAccount($accountId) as $group) {
            if ($memberKey !== '' && (string) $group['pvg_remote_id'] !== '') {
                $remaining = array_values(array_diff($this->memberKeys((int) $group['pvg_id']), array($memberKey)));
                $state = new GroupState((string) $group['pvg_uuid'], (string) $group['pvg_name'], $remaining);
                try {
                    $this->connector->changeMembers((string) $group['pvg_remote_id'], $state, array(), array($memberKey));
                } catch (ConnectorError $error) {
                    if ($error->kind !== ConnectorError::MISSING) {
                        throw $error;
                    }
                }
            }
            $this->db->queryPrepared(
                'DELETE FROM ' . ProvisioningTables::members() . ' WHERE pvm_pvg_id = ? AND pvm_pva_id = ?',
                array((int) $group['pvg_id'], $accountId)
            );
            $this->storeAppliedHash((int) $group['pvg_id'], (string) $group['pvg_name']);
        }
    }

    /**
     * Create, rename and fill a group, or delete it. Returns false if the item was put back
     * because some of its members do not have an account on the target yet.
     */
    private function processGroup(string $groupUuid, int $itemId): bool
    {
        $group = $this->db->queryPrepared(
            'SELECT * FROM ' . ProvisioningTables::groups() . ' WHERE pvg_uuid = ? AND pvg_pvt_id = ?',
            array($groupUuid, $this->target->getId())
        )->fetch();
        if ($group === false) {
            return true;
        }

        $remoteId = (string) $group['pvg_remote_id'];

        if ($group['pvg_state'] === Group::STATE_DELETE) {
            if ($remoteId !== '') {
                $this->connector->deleteGroup($remoteId);
            }
            $this->db->queryPrepared('DELETE FROM ' . ProvisioningTables::groups() . ' WHERE pvg_id = ?', array((int) $group['pvg_id']));
            return true;
        }

        $groupId = (int) $group['pvg_id'];
        $desired = $this->planner->desiredGroup($group);
        $hash = GroupState::hashOf((string) $group['pvg_name'], array_values($desired['members']));

        // the accounts of the desired members
        $accounts = $this->accountsOfUsers(array_values($desired['members']));
        $missing = array();
        $desiredAccounts = array();
        foreach ($desired['members'] as $userUuid) {
            $account = $accounts[$userUuid] ?? null;
            if ($account === null || !(bool) $account['pva_active'] || (string) $account['pva_member_key'] === '') {
                $missing[] = $userUuid;
            } else {
                $desiredAccounts[(int) $account['pva_id']] = (string) $account['pva_member_key'];
            }
        }

        $stored = $this->storedMembers($groupId);
        $state = new GroupState($groupUuid, (string) $group['pvg_name'], array_values($desiredAccounts));

        try {
            if ($remoteId === '') {
                $remoteId = (string) $this->connector->findGroup($state);
                if ($remoteId === '') {
                    $remoteId = $this->connector->createGroup($state);
                } else {
                    $this->connector->updateGroup($remoteId, $state);
                }
                $this->db->queryPrepared(
                    'UPDATE ' . ProvisioningTables::groups() . ' SET pvg_remote_id = ? WHERE pvg_id = ?',
                    array($remoteId, $groupId)
                );
            } elseif ((string) $group['pvg_hash'] !== GroupState::hashOf((string) $group['pvg_name'], $this->storedMemberUuids($groupId))) {
                // the name differs from the one last sent (or nothing was sent successfully yet)
                $this->connector->updateGroup($remoteId, $state);
            }

            $add = array_values(array_diff_key($desiredAccounts, $stored));
            $remove = array_values(array_diff_key($stored, $desiredAccounts));
            $this->connector->changeMembers($remoteId, $state, $add, $remove);
        } catch (ConnectorError $error) {
            if ($error->kind !== ConnectorError::MISSING || isset($this->deferredGroups[$itemId])) {
                throw $error;
            }
            // deleted on the target: forget it, the next attempt looks it up or creates it again
            $this->db->queryPrepared(
                'UPDATE ' . ProvisioningTables::groups() . ' SET pvg_remote_id = NULL, pvg_hash = NULL WHERE pvg_id = ?',
                array($groupId)
            );
            $this->db->queryPrepared('DELETE FROM ' . ProvisioningTables::members() . ' WHERE pvm_pvg_id = ?', array($groupId));
            $this->deferredGroups[$itemId] = true;
            $this->queue->defer($itemId, $this->token, 0);
            return false;
        }

        foreach (array_keys(array_diff_key($desiredAccounts, $stored)) as $accountId) {
            $this->db->queryPrepared(
                'INSERT INTO ' . ProvisioningTables::members() . ' (pvm_pvg_id, pvm_pva_id) VALUES (?, ?)',
                array($groupId, $accountId)
            );
        }
        foreach (array_keys(array_diff_key($stored, $desiredAccounts)) as $accountId) {
            $this->db->queryPrepared(
                'DELETE FROM ' . ProvisioningTables::members() . ' WHERE pvm_pvg_id = ? AND pvm_pva_id = ?',
                array($groupId, $accountId)
            );
        }

        if ($missing === array()) {
            $this->setGroupHash($groupId, $hash);
            return true;
        }

        // Some members have no account yet. Queue them - unless they are queued already, an item
        // that waits for an administrator must keep waiting - and come back to the group after
        // them, but only once per run, so that an account that cannot be created does not keep
        // the group in a loop.
        foreach ($missing as $userUuid) {
            $this->queue->enqueueIfAbsent($this->target->getId(), Queue::TYPE_ACCOUNT, $userUuid);
        }
        $this->storeAppliedHash($groupId, (string) $group['pvg_name']);
        if (isset($this->deferredGroups[$itemId])) {
            // Still incomplete after the accounts were tried: keep the item for a later run. The
            // deferral clears the lease, so the complete() that follows leaves the item alone.
            $this->queue->defer($itemId, $this->token, 300);
            return true;
        }
        $this->deferredGroups[$itemId] = true;
        $this->queue->defer($itemId, $this->token, 0);
        return false;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function accountMapping(string $userUuid): ?array
    {
        $row = $this->db->queryPrepared(
            'SELECT * FROM ' . ProvisioningTables::accounts() . ' WHERE pva_pvt_id = ? AND pva_usr_uuid = ?',
            array($this->target->getId(), $userUuid)
        )->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed>|null $mapping
     * @return int The ID of the mapping.
     */
    private function saveAccountMapping(?array $mapping, int $userId, string $remoteId, string $memberKey, Account $account): int
    {
        $values = array(
            $remoteId === '' ? null : $remoteId,
            $memberKey === '' ? null : $memberKey,
            $account->hash(),
            $account->active,
            ProvisioningPlugin::now()
        );

        if ($mapping === null) {
            $this->db->queryPrepared(
                'INSERT INTO ' . ProvisioningTables::accounts() . '
                        (pva_remote_id, pva_member_key, pva_hash, pva_active, pva_last_sync, pva_pvt_id, pva_usr_id, pva_usr_uuid)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                array_merge($values, array($this->target->getId(), $userId, $account->key))
            );
            return (int) $this->db->lastInsertId();
        }

        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::accounts() . '
                SET pva_remote_id = ?, pva_member_key = ?, pva_hash = ?, pva_active = ?, pva_last_sync = ?,
                    pva_last_error = NULL, pva_usr_id = ?
              WHERE pva_id = ?',
            array_merge($values, array($userId > 0 ? $userId : (int) $mapping['pva_usr_id'], (int) $mapping['pva_id']))
        );
        return (int) $mapping['pva_id'];
    }

    /**
     * @param array<int,string> $userUuids
     * @return array<string,array<string,mixed>> Mappings by usr_uuid.
     */
    private function accountsOfUsers(array $userUuids): array
    {
        $accounts = array();
        foreach (array_chunk($userUuids, 500) as $chunk) {
            $rows = $this->db->getArrayFromSql(
                'SELECT pva_id, pva_usr_uuid, pva_member_key, pva_active FROM ' . ProvisioningTables::accounts() . '
                  WHERE pva_pvt_id = ? AND pva_usr_uuid IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                array_merge(array($this->target->getId()), $chunk)
            );
            foreach ($rows as $row) {
                $accounts[(string) $row['pva_usr_uuid']] = $row;
            }
        }
        return $accounts;
    }

    /**
     * @return array<int,string> Member keys by account ID, as last sent.
     */
    private function storedMembers(int $groupId): array
    {
        $members = array();
        foreach ($this->db->getArrayFromSql(
            'SELECT pva_id, pva_member_key FROM ' . ProvisioningTables::members() . '
              INNER JOIN ' . ProvisioningTables::accounts() . ' ON pva_id = pvm_pva_id
              WHERE pvm_pvg_id = ?',
            array($groupId)
        ) as $row) {
            $members[(int) $row['pva_id']] = (string) $row['pva_member_key'];
        }
        return $members;
    }

    /**
     * @return array<int,string>
     */
    private function storedMemberUuids(int $groupId): array
    {
        return array_map('strval', $this->db->queryPrepared(
            'SELECT pva_usr_uuid FROM ' . ProvisioningTables::members() . '
              INNER JOIN ' . ProvisioningTables::accounts() . ' ON pva_id = pvm_pva_id
              WHERE pvm_pvg_id = ?',
            array($groupId)
        )->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int,string>
     */
    private function memberKeys(int $groupId): array
    {
        return array_values(array_filter($this->storedMembers($groupId), static fn(string $key): bool => $key !== ''));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function groupsOfAccount(int $accountId): array
    {
        return $this->db->getArrayFromSql(
            'SELECT pvg_id, pvg_uuid, pvg_name, pvg_remote_id FROM ' . ProvisioningTables::groups() . '
              INNER JOIN ' . ProvisioningTables::members() . ' ON pvm_pvg_id = pvg_id
              WHERE pvm_pva_id = ?',
            array($accountId)
        );
    }

    /**
     * Store the hash of what the group holds on the target now - its name and the members that were
     * sent - while the desired state could not be reached completely. The planner then still sees
     * the difference, and a later run does not send the name again.
     */
    private function storeAppliedHash(int $groupId, string $name): void
    {
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::groups() . ' SET pvg_hash = ? WHERE pvg_id = ?',
            array(GroupState::hashOf($name, $this->storedMemberUuids($groupId)), $groupId)
        );
    }

    private function setGroupHash(int $groupId, ?string $hash): void
    {
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::groups() . '
                SET pvg_hash = ?, pvg_last_error = NULL' . ($hash === null ? '' : ', pvg_last_sync = ?') . '
              WHERE pvg_id = ?',
            $hash === null ? array(null, $groupId) : array($hash, ProvisioningPlugin::now(), $groupId)
        );
    }

    /**
     * @param array<string,mixed> $item
     */
    private function logError(array $item, Throwable $exception): void
    {
        global $gLogger;

        $message = mb_substr($exception->getMessage(), 0, 1000);
        if ($item['pvq_type'] === Queue::TYPE_ACCOUNT) {
            $this->db->queryPrepared(
                'UPDATE ' . ProvisioningTables::accounts() . ' SET pva_last_error = ? WHERE pva_pvt_id = ? AND pva_usr_uuid = ?',
                array($message, $this->target->getId(), (string) $item['pvq_key'])
            );
        } else {
            $this->db->queryPrepared(
                'UPDATE ' . ProvisioningTables::groups() . ' SET pvg_last_error = ? WHERE pvg_pvt_id = ? AND pvg_uuid = ?',
                array($message, $this->target->getId(), (string) $item['pvq_key'])
            );
        }

        if (isset($gLogger) && !($exception instanceof ConnectorError)) {
            $gLogger->warning('Provisioning: unexpected error for target "' . $this->target->readableName() . '".', array(
                'exception' => get_class($exception),
                'message' => $message
            ));
        }
    }
}
