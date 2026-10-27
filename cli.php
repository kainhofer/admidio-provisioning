<?php
/**
 ***********************************************************************************************
 * Provisioning - command-line commands.
 *
 * provisioning:sync is the command for cron. Run it hourly: a run with nothing due costs almost
 * nothing, and the daily reconciliation happens on the first run after midnight in the time zone
 * of the installation, whatever the time zone of the server is.
 *
 * @copyright Reinhold Kainhofer
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Cli\CliTaskRegistry;
use AdmidioPlugin\Provisioning\Cli\ProvisioningCli;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;

if (PHP_SAPI !== 'cli') {
    exit('This page may not be called directly!');
}

$opt = static function (
    string $name,
    string $description,
    string $value = 'VALUE',
    bool $flag = false,
    array $values = array()
): array {
    $definition = array('name' => $name, 'description' => $description, 'required' => false, 'multiple' => false, 'flag' => $flag);
    if (!$flag) {
        $definition['value'] = $value;
    }
    if ($values !== array()) {
        $definition['values'] = $values;
    }
    return $definition;
};

$format = $opt('format', 'Output format.', 'FORMAT', false, array('table', 'record', 'json', 'json-api', 'csv', 'md', 'dokuwiki'));
$target = $opt('target', 'UUID of the target; without it every enabled target of the organization.', 'UUID');

CliTaskRegistry::register(
    'provisioning:sync',
    ProvisioningPlugin::ID,
    array(ProvisioningCli::class, 'sync'),
    'Run what is due: the daily reconciliation, the verification schedule and the queue. For cron, hourly.',
    'provisioning:sync [--target=UUID] [--full] [--verify] [--budget=SECONDS] [--dry-run] [--format=FORMAT]',
    array(),
    array(
        $target,
        $opt('full', 'Reconcile even if it was done today.', '', true),
        $opt('verify', 'Compare with the target system even if the schedule is not due.', '', true),
        $opt('budget', 'Stop processing the queue after this many seconds.', 'SECONDS'),
        $format
    ),
    array('admidio provisioning:sync --organization=ADMIDIO --no-interaction --quiet'),
    CliTaskRegistry::ACCESS_ADMINISTRABLE,
    true
);

CliTaskRegistry::register(
    'provisioning:status',
    ProvisioningPlugin::ID,
    array(ProvisioningCli::class, 'status'),
    'Show the targets with their queue and last runs. Exit code 3 if a target is paused or items need attention.',
    'provisioning:status [--format=FORMAT]',
    array(),
    array($format)
);

CliTaskRegistry::register(
    'provisioning:test',
    ProvisioningPlugin::ID,
    array(ProvisioningCli::class, 'test'),
    'Test the connection of a target.',
    'provisioning:test --target=UUID [--format=FORMAT]',
    array(),
    array($target, $format)
);

CliTaskRegistry::register(
    'provisioning:queue',
    ProvisioningPlugin::ID,
    array(ProvisioningCli::class, 'queue'),
    'Show the items of a target that need attention; retry them or clear the whole queue.',
    'provisioning:queue --target=UUID [--retry] [--clear --yes] [--format=FORMAT]',
    array(),
    array(
        $target,
        $opt('retry', 'Make all items due again, including those that need attention.', '', true),
        $opt('clear', 'Remove all items of the target from the queue.', '', true),
        $format
    )
);
