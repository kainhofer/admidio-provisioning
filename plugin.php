<?php
/**
 ***********************************************************************************************
 * Provisioning - plugin entry file.
 *
 * Runtime registration only: connectors, entity hooks, processing at the end of the request,
 * change history and menu visibility. The work itself is done by the services after the commit of
 * a change, at the end of the request, or by the CLI command provisioning:sync.
 *
 * @copyright Reinhold Kainhofer
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Changelog\Service\ChangelogService;
use Admidio\Hooks\Hooks;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Provisioning\Connector\Connectors;
use AdmidioPlugin\Provisioning\Connector\Mailman\MailmanConnector;
use AdmidioPlugin\Provisioning\Connector\Nextcloud\NextcloudConnector;
use AdmidioPlugin\Provisioning\Connector\Scim\ScimConnector;
use AdmidioPlugin\Provisioning\Entity\Group;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use AdmidioPlugin\Provisioning\ProvisioningTables;
use AdmidioPlugin\Provisioning\Service\ChangeCollector;
use AdmidioPlugin\Provisioning\Service\RequestRunner;
use AdmidioPlugin\Provisioning\Service\TargetService;

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit('This page may not be called directly!');
}

// The plugin's own connectors are registered like those of any other plugin.
Hooks::addFilter(Connectors::HOOK, static function (array $connectors): array {
    $connectors['scim'] = ScimConnector::class;
    $connectors['nextcloud'] = NextcloudConnector::class;
    $connectors['mailman'] = MailmanConnector::class;
    return $connectors;
}, 10, 1, 'provisioning-connectors');

foreach (ChangeCollector::HOOKS as $hook) {
    Hooks::addAction($hook, array(ChangeCollector::class, 'onEntityChange'), 50, 2, 'provisioning-' . $hook);
}

RequestRunner::register();

// Core 6.0 additions, used where the core offers them (the actions and filters simply never fire
// in a core without them): the daily report of memberships whose date was reached, and the warning
// in the plugin manager before the plugin is removed.
Hooks::addAction('membership_period_changed', array(ChangeCollector::class, 'onMembershipPeriodChanged'), 50, 2, 'provisioning-membership-period');
Hooks::addFilter('plugin_removal_warnings', static function (array $warnings, Plugin $plugin): array {
    global $gDb;

    return $plugin->id === ProvisioningPlugin::ID
        ? array_merge($warnings, (new TargetService($gDb))->removalWarnings())
        : $warnings;
}, 10, 2, 'provisioning-removal-warnings');

// Only administrators can use the plugin, so only they see its menu entry.
Hooks::addFilter('menu_item_visible', static function (bool $visible, int $menuId, string $componentName): bool {
    return $componentName === ProvisioningPlugin::ID ? $visible && ProvisioningPlugin::isAdministrator() : $visible;
}, 10, 3, 'provisioning-menu');

// Change history: the configuration is logged, the run state is not.
foreach (ProvisioningTables::OPERATIONAL as $table) {
    if (!in_array($table, ChangelogService::$noLogTables, true)) {
        ChangelogService::$noLogTables[] = $table;
    }
}

ChangelogService::registerCallback('getTableLabelArray', '', array(
    'provisioning_targets' => 'PLG_PROVISIONING_TARGETS',
    'provisioning_groups' => 'PLG_PROVISIONING_GROUPS'
));

ChangelogService::registerCallback('getFieldTranslations', '', array(
    'pvt_connector' => 'PLG_PROVISIONING_CONNECTOR',
    'pvt_config' => 'PLG_PROVISIONING_CONNECTION',
    'pvt_sso_type' => 'PLG_PROVISIONING_SSO_LINK',
    'pvt_sso_client_id' => 'PLG_PROVISIONING_SSO_LINK',
    'pvt_user_roles' => 'PLG_PROVISIONING_USER_ROLES',
    'pvt_account_name_source' => 'PLG_PROVISIONING_ACCOUNT_NAME',
    'pvt_attribute_mapping' => 'PLG_PROVISIONING_ATTRIBUTES',
    'pvt_group_catchall' => 'PLG_PROVISIONING_GROUP_CATCHALL',
    'pvt_verify_interval' => 'PLG_PROVISIONING_VERIFY_INTERVAL',
    'pvg_roles' => 'SYS_ROLES',
    'pvg_source' => 'PLG_PROVISIONING_GROUP_SOURCE'
));

ChangelogService::registerCallback('getObjectForTable', 'provisioning_targets', static function (): Target {
    global $gDb;
    return new Target($gDb);
});
ChangelogService::registerCallback('getObjectForTable', 'provisioning_groups', static function (): Group {
    global $gDb;
    return new Group($gDb);
});

ChangelogService::registerCallback(
    'createLink',
    'provisioning_targets',
    static function (string $text, string $module, int|string $id, string $uuid = ''): string {
        $plugin = PluginRegistry::get(ProvisioningPlugin::ID);
        return $plugin === null || $uuid === '' ? '' : SecurityUtils::encodeUrl(
            $plugin->getUrl('index.php'),
            array('mode' => 'edit', 'uuid' => $uuid)
        );
    }
);
