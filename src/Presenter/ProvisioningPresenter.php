<?php
namespace AdmidioPlugin\Provisioning\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Plugins\Plugin;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\PagePresenter;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;

/**
 * Shared base of the plugin pages: resolves the plugin, registers its template directory and
 * requires administrator rights.
 */
abstract class ProvisioningPresenter extends PagePresenter
{
    protected Plugin $plugin;

    /**
     * @throws Exception
     */
    public function __construct(string $objectUUID = '')
    {
        $this->plugin = PluginRegistry::requireEnabled(ProvisioningPlugin::ID);

        parent::__construct($objectUUID);

        $templates = $this->plugin->getDirectory(Plugin::DIR_TEMPLATES);
        if ($templates !== null) {
            $this->addTemplateFolder($templates);
        }

        if (!ProvisioningPlugin::isAdministrator()) {
            throw new Exception('SYS_NO_RIGHTS');
        }
    }

    protected function pageUrl(string $page, array $parameters = array()): string
    {
        return SecurityUtils::encodeUrl($this->plugin->getUrl($page), $parameters);
    }

    /**
     * An action of sys-template-parts/list.functions.tpl that asks for confirmation, posts with the
     * CSRF token and hides the row on success.
     */
    protected function deleteAction(string $rowHtmlId, string $url, string $message): array
    {
        global $gL10n, $gCurrentSession;

        return array(
            'dataHref' => 'callUrlHideElement(\'' . $rowHtmlId . '\', \'' . $url . '\', \'' . $gCurrentSession->getCsrfToken() . '\')',
            'dataMessage' => $message,
            'icon' => 'bi bi-trash',
            'tooltip' => $gL10n->get('SYS_DELETE')
        );
    }

    /**
     * A date and time from the database in the format of the organization, or "-".
     */
    protected static function formatTimestamp(?string $value, bool $withTime = true): string
    {
        global $gSettingsManager;

        if ($value === null || $value === '') {
            return '-';
        }
        $time = strtotime($value);
        if ($time === false) {
            return '-';
        }
        $format = $gSettingsManager->getString('system_date') . ($withTime ? ' ' . $gSettingsManager->getString('system_time') : '');
        return date($format, $time);
    }
}
