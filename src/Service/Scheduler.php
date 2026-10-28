<?php
namespace AdmidioPlugin\Provisioning\Service;

use Admidio\Infrastructure\Database;
use AdmidioPlugin\Provisioning\Connector\Connector;
use AdmidioPlugin\Provisioning\Connector\Connectors;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\ProvisioningTables;
use Throwable;

/**
 * Decides what is due for the targets of the current organization and runs it: the daily
 * reconciliation, the verification schedule and the processing of the queue.
 *
 * Reconciliation runs at most once per day and target, on the first run after midnight in the time
 * zone of the installation. It works on Admidio's side only: the planner compares the desired state
 * with what was last sent and queues the differences. This is what puts membership start and end
 * dates into effect.
 */
class Scheduler
{
    private TargetStatus $status;
    private Queue $queue;

    public function __construct(private readonly Database $db)
    {
        $this->status = new TargetStatus($db);
        $this->queue = new Queue($db);
    }

    /**
     * The enabled targets of the current organization.
     *
     * @return array<int,Target>
     */
    public function enabledTargets(): array
    {
        global $gCurrentOrgId;

        $targets = array();
        foreach ($this->db->queryPrepared(
            'SELECT pvt_id FROM ' . ProvisioningTables::targets() . ' WHERE pvt_org_id = ? AND pvt_enabled = true ORDER BY pvt_name',
            array((int) $gCurrentOrgId)
        )->fetchAll(\PDO::FETCH_COLUMN) as $targetId) {
            $targets[] = new Target($this->db, (int) $targetId);
        }
        return $targets;
    }

    /**
     * The targets of the current organization that runs process: the enabled ones and those that
     * are being deprovisioned (which are disabled, so that nothing new is planned for them).
     *
     * @return array<int,Target>
     */
    public function runnableTargets(): array
    {
        global $gCurrentOrgId;

        $targets = array();
        foreach ($this->db->queryPrepared(
            'SELECT pvt_id FROM ' . ProvisioningTables::targets() . '
               LEFT JOIN ' . ProvisioningTables::status() . ' ON pvs_pvt_id = pvt_id
              WHERE pvt_org_id = ? AND (pvt_enabled = true OR pvs_state = ?)
              ORDER BY pvt_name',
            array((int) $gCurrentOrgId, TargetStatus::STATE_DEPROVISIONING)
        )->fetchAll(\PDO::FETCH_COLUMN) as $targetId) {
            $targets[] = new Target($this->db, (int) $targetId);
        }
        return $targets;
    }

    /**
     * Whether anything is due for the targets of the current organization: a reconciliation or
     * queue items. One query, cheap enough to be asked at the end of every request.
     */
    public function hasDueWork(): bool
    {
        global $gCurrentOrgId;

        $now = date('Y-m-d H:i:s');
        $row = $this->db->queryPrepared(
            'SELECT COUNT(*)
               FROM ' . ProvisioningTables::targets() . '
               LEFT JOIN ' . ProvisioningTables::status() . ' ON pvs_pvt_id = pvt_id
              WHERE pvt_org_id = ?
                AND ((pvt_enabled = true
                      AND (pvs_pvt_id IS NULL OR pvs_last_reconcile_date IS NULL OR pvs_last_reconcile_date < ?))
                     OR ((pvt_enabled = true OR pvs_state = ?)
                         AND EXISTS (SELECT 1 FROM ' . ProvisioningTables::queue() . '
                                      WHERE pvq_pvt_id = pvt_id AND pvq_state = ? AND pvq_not_before <= ?)))',
            array((int) $gCurrentOrgId, DATE_NOW, TargetStatus::STATE_DEPROVISIONING, Queue::STATE_PENDING, $now)
        )->fetchColumn();

        return (int) $row > 0;
    }

    /**
     * Run what is due.
     *
     * @param array{target?: Target|null, budget?: float|null, cli?: bool, full?: bool, verify?: bool, dryRun?: bool} $options
     *        **target** only this target (also when it is disabled), **budget** time budget in seconds
     *        for all targets together (null = none), **cli** the run comes from the command line,
     *        **full** reconcile even if it was done today, **verify** verify even if not due,
     *        **dryRun** only plan and report, change nothing.
     * @return array<int,array<string,mixed>> One summary per target.
     */
    public function run(array $options = array()): array
    {
        $targets = isset($options['target']) ? array($options['target']) : $this->runnableTargets();
        $deadline = ($options['budget'] ?? null) === null ? null : microtime(true) + (float) $options['budget'];
        $fromCli = (bool) ($options['cli'] ?? false);
        $results = array();

        foreach ($targets as $target) {
            $remaining = $deadline === null ? null : $deadline - microtime(true);
            if ($remaining !== null && $remaining <= 0) {
                break;
            }
            $results[] = $this->runTarget($target, $remaining, $fromCli, $options);
        }

        return $results;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function runTarget(Target $target, ?float $budget, bool $fromCli, array $options): array
    {
        $targetId = $target->getId();
        $summary = array(
            'target' => $target->readableName(),
            'uuid' => (string) $target->getValue('pvt_uuid'),
            'reconciled' => false,
            'verified' => false,
            'queued' => 0,
            'processed' => 0,
            'failed' => 0,
            'attention' => 0,
            'state' => TargetStatus::STATE_OK,
            'message' => ''
        );
        $dryRun = (bool) ($options['dryRun'] ?? false);

        try {
            $planner = new Planner($this->db, $target);

            if ($dryRun) {
                $items = $planner->planAll();
                $summary['queued'] = count($items);
                $summary['message'] = self::describeItems($items);
                return $summary;
            }

            $this->status->ensure($targetId);
            $connector = null;

            if ($this->status->isDeprovisioning($targetId)) {
                return $this->runDeprovisioning($target, $budget, $fromCli, $summary);
            }

            if (!empty($options['verify'])
                || ($fromCli && $this->status->isVerificationDue($targetId, $target->getVerifyInterval()))) {
                $connector = Connectors::forTarget($target);
                $this->verify($target, $connector);
                $summary['verified'] = true;
                $this->status->requestReconciliation($targetId);
            }

            if (!empty($options['full'])) {
                $this->status->requestReconciliation($targetId);
            }
            if ($this->status->claimReconciliation($targetId)) {
                $items = $planner->planAll();
                $summary['queued'] = $this->queue->enqueueAll($targetId, $items);
                $summary['reconciled'] = true;
                $this->status->setSkippedUsers($targetId, $planner->getSkippedUsers());
            }

            $worker = new Worker($this->db, $target, $connector);
            $result = $worker->run($budget);
            $summary = array_merge($summary, $result);
        } catch (Throwable $exception) {
            $summary['state'] = TargetStatus::STATE_PARTIAL;
            $summary['message'] = $exception->getMessage();
            $this->log($target, $exception);
        }

        if (!$dryRun) {
            if ($summary['message'] !== 'busy') {
                $this->status->markRun($targetId, (string) $summary['state'], (string) $summary['message'], $fromCli);
            }
            (new AttentionNotifier($this->db))->notifyIfNeeded($target);
        }

        return $summary;
    }

    /**
     * Process the deletions of a target that is being deprovisioned, and remove the target once
     * nothing is left on the target system. Items that need attention keep the target until an
     * administrator retries them or disconnects the target.
     *
     * @param array<string,mixed> $summary
     * @return array<string,mixed>
     */
    private function runDeprovisioning(Target $target, ?float $budget, bool $fromCli, array $summary): array
    {
        $targetId = $target->getId();
        $summary = array_merge($summary, (new Worker($this->db, $target))->run($budget));
        $summary['state'] = TargetStatus::STATE_DEPROVISIONING;

        $counts = $this->queue->counts($targetId);
        if ($counts[Queue::STATE_PENDING] === 0 && $counts[Queue::STATE_ATTENTION] === 0) {
            (new TargetService($this->db))->removeTarget($target);
            $summary['message'] = 'PLG_PROVISIONING_DEPROVISIONED';
            return $summary;
        }

        $this->status->markRun($targetId, TargetStatus::STATE_DEPROVISIONING, (string) $summary['message'], $fromCli);
        return $summary;
    }

    /**
     * Compare the target with the stored state and forget whatever no longer matches, so that the
     * following reconciliation sends it again. Only accounts and groups the plugin manages are
     * looked at; other members of a remote group are left alone.
     */
    public function verify(Target $target, Connector $connector): void
    {
        $targetId = $target->getId();
        $capabilities = $connector->getCapabilities();
        $planner = new Planner($this->db, $target);

        if ($capabilities['accounts'] && $capabilities['verify']) {
            foreach ($this->db->getArrayFromSql(
                'SELECT pva_id, pva_usr_uuid, pva_remote_id FROM ' . ProvisioningTables::accounts() . '
                  WHERE pva_pvt_id = ? AND pva_remote_id IS NOT NULL',
                array($targetId)
            ) as $mapping) {
                $desired = $planner->desiredAccount((string) $mapping['pva_usr_uuid']);
                if ($desired['account'] === null) {
                    continue;
                }
                if (!$connector->verifyAccount((string) $mapping['pva_remote_id'], $desired['account'])) {
                    $this->db->queryPrepared(
                        'UPDATE ' . ProvisioningTables::accounts() . ' SET pva_remote_id = NULL, pva_hash = NULL WHERE pva_id = ?',
                        array((int) $mapping['pva_id'])
                    );
                }
            }
        }

        // the member keys of the accounts of this target
        $accountsByKey = array();
        foreach ($this->db->getArrayFromSql(
            'SELECT pva_id, pva_member_key FROM ' . ProvisioningTables::accounts() . ' WHERE pva_pvt_id = ? AND pva_member_key IS NOT NULL',
            array($targetId)
        ) as $row) {
            $accountsByKey[(string) $row['pva_member_key']] = (int) $row['pva_id'];
        }

        foreach ($this->db->getArrayFromSql(
            'SELECT pvg_id, pvg_remote_id FROM ' . ProvisioningTables::groups() . '
              WHERE pvg_pvt_id = ? AND pvg_state = ? AND pvg_remote_id IS NOT NULL',
            array($targetId, 'active')
        ) as $group) {
            $remoteKeys = $connector->readGroupMembers((string) $group['pvg_remote_id']);
            if ($remoteKeys === null) {
                continue;
            }
            $groupId = (int) $group['pvg_id'];

            // the remote members that are accounts of this target, as the new stored state
            $remoteAccounts = array();
            foreach ($remoteKeys as $key) {
                if (isset($accountsByKey[$key])) {
                    $remoteAccounts[$accountsByKey[$key]] = true;
                }
            }
            $stored = array_map('intval', $this->db->queryPrepared(
                'SELECT pvm_pva_id FROM ' . ProvisioningTables::members() . ' WHERE pvm_pvg_id = ?',
                array($groupId)
            )->fetchAll(\PDO::FETCH_COLUMN));
            $remote = array_keys($remoteAccounts);
            sort($stored);
            sort($remote);

            if ($stored === $remote) {
                continue;
            }
            // members from before the takeover stay marked as such
            $preexisting = array_fill_keys(array_map('intval', $this->db->queryPrepared(
                'SELECT pvm_pva_id FROM ' . ProvisioningTables::members() . ' WHERE pvm_pvg_id = ? AND pvm_preexisting = ?',
                array($groupId, true)
            )->fetchAll(\PDO::FETCH_COLUMN)), true);
            $this->db->queryPrepared('DELETE FROM ' . ProvisioningTables::members() . ' WHERE pvm_pvg_id = ?', array($groupId));
            foreach (array_keys($remoteAccounts) as $accountId) {
                $this->db->queryPrepared(
                    'INSERT INTO ' . ProvisioningTables::members() . ' (pvm_pvg_id, pvm_pva_id, pvm_preexisting) VALUES (?, ?, ?)',
                    array($groupId, $accountId, isset($preexisting[$accountId]))
                );
            }
            $this->db->queryPrepared(
                'UPDATE ' . ProvisioningTables::groups() . ' SET pvg_hash = NULL WHERE pvg_id = ?',
                array($groupId)
            );
        }

        $this->status->markVerified($targetId);
    }

    /**
     * @param array<int,array{type: string, key: string}> $items
     */
    private static function describeItems(array $items): string
    {
        $counts = array(Queue::TYPE_ACCOUNT => 0, Queue::TYPE_GROUP => 0);
        foreach ($items as $item) {
            ++$counts[$item['type']];
        }
        return $counts[Queue::TYPE_ACCOUNT] . ' accounts, ' . $counts[Queue::TYPE_GROUP] . ' groups';
    }

    private function log(Target $target, Throwable $exception): void
    {
        global $gLogger;

        if (isset($gLogger)) {
            $gLogger->warning('Provisioning: run of target "' . $target->readableName() . '" failed.', array(
                'exception' => get_class($exception),
                'message' => $exception->getMessage()
            ));
        }
    }
}
