<?php
/**
 ***********************************************************************************************
 * Provisioning plugin - targets (landing page): list, edit, status and actions.
 *
 * Parameters:
 * mode      : list (default) | edit | save | status | delete | deprovision | test | sync | verify | retry
 * uuid      : UUID of the target
 * connector : connector type of a new target (edit, save)
 * step      : start | continue (sync, verify)
 * item      : ID of one queue item (retry)
 *
 * @copyright Reinhold Kainhofer
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Plugins\PluginRegistry;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Provisioning\Presenter\TargetPresenter;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use AdmidioPlugin\Provisioning\Service\Queue;
use AdmidioPlugin\Provisioning\Service\RequestRunner;
use AdmidioPlugin\Provisioning\Service\Scheduler;
use AdmidioPlugin\Provisioning\Service\TargetService;
use AdmidioPlugin\Provisioning\Service\TargetStatus;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'list',
        'validValues' => array('list', 'edit', 'save', 'status', 'delete', 'deprovision', 'test', 'sync', 'verify', 'retry')
    ));
    $getUuid = admFuncVariableIsValid($_GET, 'uuid', 'uuid');
    $getConnector = admFuncVariableIsValid($_GET, 'connector', 'string');
    $getStep = admFuncVariableIsValid($_GET, 'step', 'string', array('defaultValue' => 'start', 'validValues' => array('start', 'continue')));
    $getItem = admFuncVariableIsValid($_GET, 'item', 'int');

    if (!ProvisioningPlugin::isAdministrator()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $service = new TargetService($gDb);

    // every mode except the pages changes something and must come with the CSRF token
    if (!in_array($getMode, array('list', 'edit', 'status', 'save'), true)) {
        SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
    }

    switch ($getMode) {
        case 'list':
            $page = new TargetPresenter();
            $page->createList();
            $gNavigation->addStartUrl(CURRENT_URL, $page->getHeadline(), 'bi-diagram-3');
            $page->show();
            break;

        case 'edit':
            $page = new TargetPresenter($getUuid);
            $page->createEditForm($getUuid, $getConnector);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'status':
            $page = new TargetPresenter($getUuid);
            $page->createStatus($getUuid);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'save':
            $target = $service->getTarget($getUuid);
            $form = $gCurrentSession->getFormObject($_POST['adm_csrf_token']);
            $formValues = $form->validate($_POST);
            // the connector of a new target comes from the page, not from a field the browser could change
            $formValues['pvt_connector'] = $getConnector;
            $service->save($target, $formValues);

            $gNavigation->deleteLastUrl();
            echo json_encode(array(
                'status' => 'success',
                'url' => SecurityUtils::encodeUrl(
                    PluginRegistry::requireEnabled(ProvisioningPlugin::ID)->getUrl('index.php'),
                    array('mode' => 'status', 'uuid' => (string) $target->getValue('pvt_uuid'))
                )
            ));
            break;

        case 'delete':
            $service->disconnect($service->getTarget($getUuid));
            echo json_encode(array('status' => 'success'));
            break;

        case 'deprovision':
            $service->deprovision($service->getTarget($getUuid));
            echo json_encode(array('status' => 'success', 'message' => $gL10n->get('PLG_PROVISIONING_DEPROVISION_STARTED')));
            break;

        case 'test':
            $details = $service->test($service->getTarget($getUuid));
            $lines = array();
            foreach ($details as $name => $value) {
                $lines[] = SecurityUtils::encodeHTML($name . ': ' . $value);
            }
            echo json_encode(array(
                'status' => 'success',
                'message' => $gL10n->get('PLG_PROVISIONING_TEST_SUCCESS') . '<br>' . implode('<br>', $lines)
            ));
            break;

        case 'sync':
        case 'verify':
            // this request processes the queue itself, in slices that the page asks for one by one
            RequestRunner::skip();
            $target = $service->getTarget($getUuid);
            $start = $getStep === 'start';
            if ($start) {
                (new TargetStatus($gDb))->resume($target->getId());
                if ($getMode === 'verify') {
                    set_time_limit(300);
                }
            }

            $result = (new Scheduler($gDb))->run(array(
                'target' => $target,
                'budget' => 5.0,
                'full' => $start,
                'verify' => $start && $getMode === 'verify'
            ))[0];

            $remaining = (new Queue($gDb))->dueCount($target->getId());
            $message = $gL10n->get('PLG_PROVISIONING_SYNC_PROGRESS', array(
                (string) $result['processed'], (string) $result['failed'], (string) $remaining
            ));
            if ((string) $result['message'] !== '' && $result['message'] !== 'busy') {
                $message .= '<br>' . SecurityUtils::encodeHTML(Language::translateIfTranslationStrId((string) $result['message']));
            }

            echo json_encode(array(
                'status' => 'success',
                'message' => $message,
                'remaining' => $remaining,
                // a target that another process is working on is left to that process
                'progress' => $result['processed'] > 0 || $result['queued'] > 0
            ));
            break;

        case 'retry':
            $target = $service->getTarget($getUuid);
            $count = (new Queue($gDb))->retryAttention($target->getId(), $getItem);
            RequestRunner::markQueued();
            echo json_encode(array(
                'status' => 'success',
                'message' => $gL10n->get('PLG_PROVISIONING_RETRY_QUEUED', array((string) $count))
            ));
            break;
    }
} catch (Throwable $e) {
    handleException($e, in_array($getMode ?? '', array('save', 'delete', 'deprovision', 'test', 'sync', 'verify', 'retry'), true));
}
