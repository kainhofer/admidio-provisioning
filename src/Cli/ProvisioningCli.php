<?php
namespace AdmidioPlugin\Provisioning\Cli;

use Admidio\Infrastructure\Cli\CliApplication;
use Admidio\Infrastructure\Language;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\ProvisioningTables;
use AdmidioPlugin\Provisioning\Service\Queue;
use AdmidioPlugin\Provisioning\Service\RequestRunner;
use AdmidioPlugin\Provisioning\Service\Scheduler;
use AdmidioPlugin\Provisioning\Service\TargetService;
use AdmidioPlugin\Provisioning\Service\TargetStatus;
use InvalidArgumentException;

/**
 * Callbacks of the provisioning:* commands.
 */
final class ProvisioningCli
{
    public static function sync(array $arguments, array $options): int
    {
        global $gDb;

        // the command processes the queue itself
        RequestRunner::skip();

        $targetUuid = CliApplication::optionString($options, 'target');
        $budget = CliApplication::optionInt($options, 'budget');

        $results = (new Scheduler($gDb))->run(array(
            'target' => $targetUuid === '' ? null : (new TargetService($gDb))->getTarget($targetUuid),
            'budget' => $budget === null ? null : (float) $budget,
            'cli' => true,
            'full' => CliApplication::optionExists($options, 'full'),
            'verify' => CliApplication::optionExists($options, 'verify'),
            'dryRun' => CliApplication::optionExists($options, 'dry-run')
        ));

        $rows = array();
        $failed = false;
        foreach ($results as $result) {
            $failed = $failed || $result['state'] !== TargetStatus::STATE_OK;
            $rows[] = array(
                'target' => $result['target'],
                'reconciled' => $result['reconciled'],
                'verified' => $result['verified'],
                'queued' => $result['queued'],
                'processed' => $result['processed'],
                'failed' => $result['failed'],
                'attention' => $result['attention'],
                'state' => $result['state'],
                'message' => Language::translateIfTranslationStrId((string) $result['message'])
            );
        }

        CliApplication::writeRows($rows, CliApplication::optionString($options, 'format', 'table'), $options);
        return $failed ? CliApplication::EXIT_FAILED : CliApplication::EXIT_SUCCESS;
    }

    public static function status(array $arguments, array $options): int
    {
        global $gDb, $gCurrentOrgId;

        $queue = new Queue($gDb);
        $status = new TargetStatus($gDb);
        $rows = array();
        $notOk = false;

        foreach ($gDb->queryPrepared(
            'SELECT pvt_id FROM ' . ProvisioningTables::targets() . ' WHERE pvt_org_id = ? ORDER BY pvt_name',
            array((int) $gCurrentOrgId)
        )->fetchAll(\PDO::FETCH_COLUMN) as $targetId) {
            $target = new Target($gDb, (int) $targetId);
            $state = $status->get($target->getId());
            $counts = $queue->counts($target->getId());
            $paused = $status->isPaused($target->getId());
            $notOk = $notOk || ($target->isEnabled() && ($paused || $counts[Queue::STATE_ATTENTION] > 0));

            $rows[] = array(
                'target' => $target->readableName(),
                'uuid' => (string) $target->getValue('pvt_uuid'),
                'connector' => (string) $target->getValue('pvt_connector'),
                'enabled' => $target->isEnabled(),
                'accounts' => self::count($gDb, ProvisioningTables::accounts(), 'pva_pvt_id', $target->getId()),
                'groups' => self::count($gDb, ProvisioningTables::groups(), 'pvg_pvt_id', $target->getId()),
                'pending' => $counts[Queue::STATE_PENDING],
                'attention' => $counts[Queue::STATE_ATTENTION],
                'state' => $paused ? TargetStatus::STATE_PAUSED : (string) ($state['pvs_state'] ?? ''),
                'last_run' => (string) ($state['pvs_last_run'] ?? ''),
                'last_cli_run' => (string) ($state['pvs_last_cli_run'] ?? ''),
                'last_reconciliation' => (string) ($state['pvs_last_reconcile_date'] ?? ''),
                'message' => Language::translateIfTranslationStrId((string) ($state['pvs_message'] ?? ''))
            );
        }

        CliApplication::writeRows($rows, CliApplication::optionString($options, 'format', 'table'), $options);
        return $notOk ? CliApplication::EXIT_STATE_NOT_OK : CliApplication::EXIT_SUCCESS;
    }

    public static function test(array $arguments, array $options): int
    {
        global $gDb;

        $service = new TargetService($gDb);
        $details = $service->test(self::requireTarget($service, $options));

        $rows = array();
        foreach ($details as $name => $value) {
            $rows[] = array('property' => $name, 'value' => $value);
        }
        CliApplication::writeRows($rows, CliApplication::optionString($options, 'format', 'table'), $options);
        return CliApplication::EXIT_SUCCESS;
    }

    public static function queue(array $arguments, array $options): int
    {
        global $gDb;

        $target = self::requireTarget(new TargetService($gDb), $options);
        $queue = new Queue($gDb);

        if (CliApplication::optionExists($options, 'clear')) {
            CliApplication::confirm('Remove all queue items of the target "' . $target->readableName() . '"?', $options);
            CliApplication::writeSuccess($queue->clear($target->getId()) . ' items removed.', $options);
            return CliApplication::EXIT_SUCCESS;
        }
        if (CliApplication::optionExists($options, 'retry')) {
            $queue->retryAttention($target->getId());
            CliApplication::writeSuccess($queue->retryAll($target->getId()) . ' items are due again.', $options);
            return CliApplication::EXIT_SUCCESS;
        }

        $rows = array();
        foreach ($queue->attentionItems($target->getId()) as $item) {
            $rows[] = array(
                'type' => (string) $item['pvq_type'],
                'key' => (string) $item['pvq_key'],
                'name' => (string) ($item['pvg_name'] ?? ''),
                'attempts' => (int) $item['pvq_attempts'],
                'error' => (string) $item['pvq_last_error']
            );
        }
        CliApplication::writeRows($rows, CliApplication::optionString($options, 'format', 'table'), $options);
        return CliApplication::EXIT_SUCCESS;
    }

    private static function requireTarget(TargetService $service, array $options): Target
    {
        $uuid = CliApplication::optionString($options, 'target');
        if ($uuid === '') {
            throw new InvalidArgumentException('--target=UUID is required.');
        }
        return $service->getTarget($uuid);
    }

    private static function count(\Admidio\Infrastructure\Database $db, string $table, string $column, int $targetId): int
    {
        return (int) $db->queryPrepared('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = ?', array($targetId))->fetchColumn();
    }
}
