<?php
namespace AdmidioPlugin\Provisioning\Service;

use Admidio\Infrastructure\Database;
use AdmidioPlugin\Provisioning\Connector\ConnectorError;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use AdmidioPlugin\Provisioning\ProvisioningTables;

/**
 * The persistent work queue. An item names one account (key = usr_uuid) or one group
 * (key = pvg_uuid) of a target, never a payload: the worker always calculates the desired state
 * from the current database, so an item cannot become out of date.
 *
 * An item that exists already is not added twice. Queuing it again makes it due at once and breaks
 * the lease of a worker that is processing it right now - that worker read the state before the
 * new change, so its result must not remove the item.
 */
class Queue
{
    public const TYPE_ACCOUNT = 'account';
    public const TYPE_GROUP = 'group';

    public const STATE_PENDING = 'pending';
    public const STATE_ATTENTION = 'attention';

    /** An item that failed this often, or is older than a day, needs an administrator. */
    public const MAX_ATTEMPTS = 10;
    public const MAX_AGE_SECONDS = 86400;

    /** Waiting times in minutes after the 1st, 2nd ... failure; the last one repeats. */
    private const BACKOFF_MINUTES = array(1, 5, 30, 120, 720);

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<int,array{type: string, key: string}> $items
     */
    public function enqueueAll(int $targetId, array $items): int
    {
        foreach ($items as $item) {
            $this->enqueue($targetId, $item['type'], $item['key']);
        }
        return count($items);
    }

    public function enqueue(int $targetId, string $type, string $key): void
    {
        if ($this->reset($targetId, $type, $key)) {
            return;
        }

        $statement = $this->db->queryPrepared(
            'INSERT INTO ' . ProvisioningTables::queue() . '
                    (pvq_pvt_id, pvq_type, pvq_key, pvq_state, pvq_not_before, pvq_attempts)
             VALUES (?, ?, ?, ?, ?, 0)',
            array($targetId, $type, $key, self::STATE_PENDING, ProvisioningPlugin::now()),
            false
        );

        // Another request inserted the same item in the meantime; the unique index refused ours.
        if ($statement === false) {
            $this->reset($targetId, $type, $key);
        }
    }

    /**
     * Queue an item only if it is not queued yet, whatever its state.
     */
    public function enqueueIfAbsent(int $targetId, string $type, string $key): void
    {
        $exists = $this->db->queryPrepared(
            'SELECT 1 FROM ' . ProvisioningTables::queue() . ' WHERE pvq_pvt_id = ? AND pvq_type = ? AND pvq_key = ?',
            array($targetId, $type, $key)
        )->fetchColumn();
        if ($exists === false) {
            $this->enqueue($targetId, $type, $key);
        }
    }

    /**
     * Make an existing item due now. Returns false if there is no such item.
     */
    private function reset(int $targetId, string $type, string $key): bool
    {
        $statement = $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::queue() . '
                SET pvq_state = ?, pvq_not_before = ?, pvq_attempts = 0, pvq_lease_token = NULL, pvq_lease_expires = NULL
              WHERE pvq_pvt_id = ? AND pvq_type = ? AND pvq_key = ?',
            array(self::STATE_PENDING, ProvisioningPlugin::now(), $targetId, $type, $key)
        );

        if ($statement->rowCount() > 0) {
            return true;
        }

        // rowCount() of MySQL counts changed rows only: an item that already has exactly these
        // values exists as well.
        $exists = $this->db->queryPrepared(
            'SELECT 1 FROM ' . ProvisioningTables::queue() . ' WHERE pvq_pvt_id = ? AND pvq_type = ? AND pvq_key = ?',
            array($targetId, $type, $key)
        );
        return $exists->fetchColumn() !== false;
    }

    /**
     * Due items of a target, oldest first: accounts before groups, so that new members exist
     * before they are added - or groups before accounts when a target is deprovisioned, so that
     * nothing is left in a group that is about to be deleted anyway.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueItems(int $targetId, int $limit = 50, bool $groupsFirst = false): array
    {
        $now = ProvisioningPlugin::now();
        return $this->db->getArrayFromSql(
            'SELECT pvq_id, pvq_type, pvq_key, pvq_attempts, pvq_timestamp_create
               FROM ' . ProvisioningTables::queue() . '
              WHERE pvq_pvt_id = ?
                AND pvq_state = ?
                AND pvq_not_before <= ?
                AND (pvq_lease_token IS NULL OR pvq_lease_expires < ?)
              ORDER BY CASE WHEN pvq_type = ? THEN 0 ELSE 1 END, pvq_id
              LIMIT ' . max(1, $limit),
            array($targetId, self::STATE_PENDING, $now, $now, $groupsFirst ? self::TYPE_GROUP : self::TYPE_ACCOUNT)
        );
    }

    public function claim(int $itemId, string $token, int $leaseSeconds = 300): bool
    {
        $now = ProvisioningPlugin::now();
        $statement = $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::queue() . '
                SET pvq_lease_token = ?, pvq_lease_expires = ?
              WHERE pvq_id = ? AND pvq_state = ?
                AND (pvq_lease_token IS NULL OR pvq_lease_expires < ?)',
            array($token, ProvisioningPlugin::now($leaseSeconds), $itemId, self::STATE_PENDING, $now)
        );
        return $statement->rowCount() === 1;
    }

    /**
     * Remove a processed item - unless it was queued again while it was processed.
     */
    public function complete(int $itemId, string $token): void
    {
        $this->db->queryPrepared(
            'DELETE FROM ' . ProvisioningTables::queue() . ' WHERE pvq_id = ? AND pvq_lease_token = ?',
            array($itemId, $token)
        );
    }

    /**
     * Put an item back to be processed later in this or a later run, without counting a failure.
     */
    public function defer(int $itemId, string $token, int $seconds = 0): void
    {
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::queue() . '
                SET pvq_lease_token = NULL, pvq_lease_expires = NULL, pvq_not_before = ?
              WHERE pvq_id = ? AND pvq_lease_token = ?',
            array(ProvisioningPlugin::now($seconds), $itemId, $token)
        );
    }

    /**
     * Record a failure. Conflicts and rejected data, and items that failed too often or too long,
     * wait for an administrator; everything else is retried after the backoff.
     *
     * @param array<string,mixed> $item The row of the item as dueItems() returned it.
     * @return bool Whether the item now needs attention.
     */
    public function fail(array $item, string $token, ConnectorError $error): bool
    {
        $attempts = (int) $item['pvq_attempts'] + 1;
        $age = time() - (int) strtotime((string) $item['pvq_timestamp_create']);

        $attention = in_array($error->kind, array(ConnectorError::CONFLICT, ConnectorError::INVALID), true)
            || $attempts >= self::MAX_ATTEMPTS
            || $age > self::MAX_AGE_SECONDS;

        $wait = max(self::backoffSeconds($attempts), $error->retryAfter);

        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::queue() . '
                SET pvq_state = ?, pvq_attempts = ?, pvq_not_before = ?, pvq_last_error = ?,
                    pvq_lease_token = NULL, pvq_lease_expires = NULL
              WHERE pvq_id = ? AND pvq_lease_token = ?',
            array(
                $attention ? self::STATE_ATTENTION : self::STATE_PENDING,
                $attempts,
                ProvisioningPlugin::now($wait),
                mb_substr($error->getMessage(), 0, 1000),
                (int) $item['pvq_id'],
                $token
            )
        );

        return $attention;
    }

    public static function backoffSeconds(int $attempts): int
    {
        $index = min(max($attempts, 1), count(self::BACKOFF_MINUTES)) - 1;
        return self::BACKOFF_MINUTES[$index] * 60;
    }

    /**
     * @return array{pending: int, attention: int}
     */
    public function counts(int $targetId): array
    {
        $rows = $this->db->getArrayFromSql(
            'SELECT pvq_state, COUNT(*) AS cnt FROM ' . ProvisioningTables::queue() . '
              WHERE pvq_pvt_id = ? GROUP BY pvq_state',
            array($targetId)
        );
        $counts = array(self::STATE_PENDING => 0, self::STATE_ATTENTION => 0);
        foreach ($rows as $row) {
            $counts[(string) $row['pvq_state']] = (int) $row['cnt'];
        }
        return $counts;
    }

    /**
     * The number of items that are due now.
     */
    public function dueCount(int $targetId): int
    {
        return (int) $this->db->queryPrepared(
            'SELECT COUNT(*) FROM ' . ProvisioningTables::queue() . '
              WHERE pvq_pvt_id = ? AND pvq_state = ? AND pvq_not_before <= ?',
            array($targetId, self::STATE_PENDING, ProvisioningPlugin::now())
        )->fetchColumn();
    }

    /**
     * Items that wait for an administrator, with the name of the user or group they stand for.
     *
     * @return array<int,array<string,mixed>>
     */
    public function attentionItems(int $targetId): array
    {
        return $this->db->getArrayFromSql(
            'SELECT pvq_id, pvq_type, pvq_key, pvq_attempts, pvq_last_error, pvq_timestamp_create,
                    usr_id, pvg_name
               FROM ' . ProvisioningTables::queue() . '
               LEFT JOIN ' . TBL_USERS . ' ON pvq_type = ? AND usr_uuid = pvq_key
               LEFT JOIN ' . ProvisioningTables::groups() . ' ON pvq_type = ? AND pvg_uuid = pvq_key
              WHERE pvq_pvt_id = ? AND pvq_state = ?
              ORDER BY pvq_id',
            array(self::TYPE_ACCOUNT, self::TYPE_GROUP, $targetId, self::STATE_ATTENTION)
        );
    }

    /**
     * Make items that need attention due again, all of a target or one of them.
     */
    public function retryAttention(int $targetId, int $itemId = 0): int
    {
        $sql = 'UPDATE ' . ProvisioningTables::queue() . '
                   SET pvq_state = ?, pvq_attempts = 0, pvq_not_before = ?
                 WHERE pvq_pvt_id = ? AND pvq_state = ?';
        $params = array(self::STATE_PENDING, ProvisioningPlugin::now(), $targetId, self::STATE_ATTENTION);
        if ($itemId > 0) {
            $sql .= ' AND pvq_id = ?';
            $params[] = $itemId;
        }
        return $this->db->queryPrepared($sql, $params)->rowCount();
    }

    /**
     * Make every item of a target due now, including those waiting for a retry.
     */
    public function retryAll(int $targetId): int
    {
        return $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::queue() . '
                SET pvq_state = ?, pvq_not_before = ?
              WHERE pvq_pvt_id = ?',
            array(self::STATE_PENDING, ProvisioningPlugin::now(), $targetId)
        )->rowCount();
    }

    public function clear(int $targetId): int
    {
        return $this->db->queryPrepared(
            'DELETE FROM ' . ProvisioningTables::queue() . ' WHERE pvq_pvt_id = ?',
            array($targetId)
        )->rowCount();
    }
}
