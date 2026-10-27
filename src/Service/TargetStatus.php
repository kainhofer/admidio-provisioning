<?php
namespace AdmidioPlugin\Provisioning\Service;

use Admidio\Infrastructure\Database;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use AdmidioPlugin\Provisioning\ProvisioningTables;

/**
 * The run state of a target: its lease, when it was last reconciled, verified and run, whether it
 * is paused, and the capabilities its connector reported. One row per target, written with plain
 * SQL so that none of it reaches the change history.
 */
class TargetStatus
{
    public const STATE_OK = 'ok';
    public const STATE_PARTIAL = 'partial';
    public const STATE_PAUSED = 'paused';
    /** The target is being removed: its groups and accounts are deleted on the target system. */
    public const STATE_DEPROVISIONING = 'deprovisioning';

    public function __construct(private readonly Database $db)
    {
    }

    public function ensure(int $targetId): void
    {
        $exists = $this->db->queryPrepared(
            'SELECT 1 FROM ' . ProvisioningTables::status() . ' WHERE pvs_pvt_id = ?',
            array($targetId)
        )->fetchColumn();

        if ($exists === false) {
            // A parallel request may insert the same row; the primary key keeps one of them.
            $this->db->queryPrepared(
                'INSERT INTO ' . ProvisioningTables::status() . ' (pvs_pvt_id, pvs_state) VALUES (?, ?)',
                array($targetId, self::STATE_OK),
                false
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function get(int $targetId): array
    {
        $this->ensure($targetId);
        $row = $this->db->queryPrepared(
            'SELECT * FROM ' . ProvisioningTables::status() . ' WHERE pvs_pvt_id = ?',
            array($targetId)
        )->fetch();
        return is_array($row) ? $row : array();
    }

    /**
     * Claim the target for one worker. Only one worker talks to a target at a time, which keeps the
     * order of the requests and the load on the target under control.
     */
    public function claim(int $targetId, string $token, int $leaseSeconds = 600): bool
    {
        $this->ensure($targetId);
        return $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . '
                SET pvs_lease_token = ?, pvs_lease_expires = ?
              WHERE pvs_pvt_id = ? AND (pvs_lease_token IS NULL OR pvs_lease_expires < ?)',
            array($token, ProvisioningPlugin::now($leaseSeconds), $targetId, ProvisioningPlugin::now())
        )->rowCount() === 1;
    }

    public function renew(int $targetId, string $token, int $leaseSeconds = 600): void
    {
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . ' SET pvs_lease_expires = ?
              WHERE pvs_pvt_id = ? AND pvs_lease_token = ?',
            array(ProvisioningPlugin::now($leaseSeconds), $targetId, $token)
        );
    }

    public function release(int $targetId, string $token): void
    {
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . '
                SET pvs_lease_token = NULL, pvs_lease_expires = NULL
              WHERE pvs_pvt_id = ? AND pvs_lease_token = ?',
            array($targetId, $token)
        );
    }

    /**
     * Claim today's reconciliation. The date is written before the work starts, the same way as
     * the purge of the change history, so a second request or cron run on the same day finds it
     * done. Returns false if the target was reconciled today already.
     */
    public function claimReconciliation(int $targetId, string $today = DATE_NOW): bool
    {
        $this->ensure($targetId);
        return $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . '
                SET pvs_last_reconcile_date = ?
              WHERE pvs_pvt_id = ? AND (pvs_last_reconcile_date IS NULL OR pvs_last_reconcile_date < ?)',
            array($today, $targetId, $today)
        )->rowCount() === 1;
    }

    /**
     * Let the next run reconcile the target, for example after its configuration changed.
     */
    public function requestReconciliation(int $targetId): void
    {
        $this->ensure($targetId);
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . ' SET pvs_last_reconcile_date = NULL WHERE pvs_pvt_id = ?',
            array($targetId)
        );
    }

    /**
     * Whether the verification schedule of the target is due.
     */
    public function isVerificationDue(int $targetId, int $interval): bool
    {
        if ($interval === 0) {
            return false;
        }
        $lastVerify = (string) ($this->get($targetId)['pvs_last_verify'] ?? '');
        if ($lastVerify === '') {
            return true;
        }
        $seconds = $interval < 0 ? 3600 : $interval * 86400;
        // a few minutes of tolerance, so that an hourly cron run does not miss an hourly schedule
        return strtotime($lastVerify) <= time() - $seconds + 300;
    }

    public function markVerified(int $targetId): void
    {
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . ' SET pvs_last_verify = ? WHERE pvs_pvt_id = ?',
            array(ProvisioningPlugin::now(), $targetId)
        );
    }

    /**
     * Record a run. A deprovisioning target keeps its state, a paused one its state and message.
     */
    public function markRun(int $targetId, string $state, string $message, bool $fromCli): void
    {
        $now = ProvisioningPlugin::now();
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . '
                SET pvs_last_run = ?,
                    pvs_state = CASE WHEN pvs_state = ? THEN pvs_state ELSE ? END,
                    pvs_message = ?
              WHERE pvs_pvt_id = ? AND (pvs_paused_until IS NULL OR pvs_paused_until < ?)',
            array($now, self::STATE_DEPROVISIONING, $state, mb_substr($message, 0, 2000), $targetId, $now)
        );

        if ($fromCli) {
            // a paused target still records that cron is running
            $this->db->queryPrepared(
                'UPDATE ' . ProvisioningTables::status() . ' SET pvs_last_cli_run = ? WHERE pvs_pvt_id = ?',
                array($now, $targetId)
            );
        }
    }

    public function isDeprovisioning(int $targetId): bool
    {
        return ($this->get($targetId)['pvs_state'] ?? '') === self::STATE_DEPROVISIONING;
    }

    public function startDeprovisioning(int $targetId): void
    {
        $this->ensure($targetId);
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . '
                SET pvs_state = ?, pvs_paused_until = NULL, pvs_message = NULL
              WHERE pvs_pvt_id = ?',
            array(self::STATE_DEPROVISIONING, $targetId)
        );
    }

    public function pause(int $targetId, int $seconds, string $message): void
    {
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . '
                SET pvs_state = CASE WHEN pvs_state = ? THEN pvs_state ELSE ? END,
                    pvs_paused_until = ?, pvs_message = ?
              WHERE pvs_pvt_id = ?',
            array(self::STATE_DEPROVISIONING, self::STATE_PAUSED, ProvisioningPlugin::now($seconds), mb_substr($message, 0, 2000), $targetId)
        );
    }

    public function isPaused(int $targetId): bool
    {
        $until = (string) ($this->get($targetId)['pvs_paused_until'] ?? '');
        return $until !== '' && strtotime($until) > time();
    }

    /**
     * End a pause, for example because the configuration was changed or an administrator started
     * a synchronization.
     */
    public function resume(int $targetId): void
    {
        $this->ensure($targetId);
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . '
                SET pvs_paused_until = NULL, pvs_state = CASE WHEN pvs_state = ? THEN ? ELSE pvs_state END
              WHERE pvs_pvt_id = ?',
            array(self::STATE_PAUSED, self::STATE_OK, $targetId)
        );
    }

    public function setMessage(int $targetId, string $message): void
    {
        $this->ensure($targetId);
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . ' SET pvs_message = ? WHERE pvs_pvt_id = ?',
            array(mb_substr($message, 0, 2000), $targetId)
        );
    }

    /**
     * @param array<string,string> $capabilities
     */
    public function setCapabilities(int $targetId, array $capabilities): void
    {
        $this->ensure($targetId);
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . ' SET pvs_capabilities = ? WHERE pvs_pvt_id = ?',
            array(json_encode($capabilities), $targetId)
        );
    }

    public function setSkippedUsers(int $targetId, int $count): void
    {
        $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . ' SET pvs_skipped_users = ? WHERE pvs_pvt_id = ?',
            array($count, $targetId)
        );
    }

    /**
     * Claim today's notification about items that need attention. Returns false if the
     * administrators were told today already.
     */
    public function claimAttentionNotification(int $targetId): bool
    {
        return $this->db->queryPrepared(
            'UPDATE ' . ProvisioningTables::status() . '
                SET pvs_attention_notified = ?
              WHERE pvs_pvt_id = ? AND (pvs_attention_notified IS NULL OR pvs_attention_notified < ?)',
            array(DATE_NOW, $targetId, DATE_NOW)
        )->rowCount() === 1;
    }
}
