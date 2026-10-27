<?php
/**
 ***********************************************************************************************
 * Provisioning plugin - groups of a target.
 *
 * Parameters:
 * mode        : list (default) | edit | save | delete
 * target_uuid : UUID of the target
 * uuid        : UUID of the group for edit / save / delete
 *
 * @copyright Reinhold Kainhofer
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Utils\SecurityUtils;
use AdmidioPlugin\Provisioning\Presenter\GroupPresenter;
use AdmidioPlugin\Provisioning\ProvisioningPlugin;
use AdmidioPlugin\Provisioning\Service\TargetService;

try {
    require_once(__DIR__ . '/../../../system/common.php');
    require(__DIR__ . '/../../../system/login_valid.php');

    $getMode = admFuncVariableIsValid($_GET, 'mode', 'string', array(
        'defaultValue' => 'list',
        'validValues' => array('list', 'edit', 'save', 'delete')
    ));
    $getTargetUuid = admFuncVariableIsValid($_GET, 'target_uuid', 'uuid', array('requireValue' => true));
    $getUuid = admFuncVariableIsValid($_GET, 'uuid', 'uuid');

    if (!ProvisioningPlugin::isAdministrator()) {
        throw new Exception('SYS_NO_RIGHTS');
    }

    $service = new TargetService($gDb);
    $target = $service->getTarget($getTargetUuid);
    if ($target->isNewRecord()) {
        throw new Exception('SYS_INVALID_PAGE_VIEW');
    }

    switch ($getMode) {
        case 'list':
            $page = new GroupPresenter($getTargetUuid);
            $page->createList($target);
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'edit':
            $page = new GroupPresenter($getUuid);
            $page->createEditForm($target, $service->getGroup($target, $getUuid));
            $gNavigation->addUrl(CURRENT_URL, $page->getHeadline());
            $page->show();
            break;

        case 'save':
            $group = $service->getGroup($target, $getUuid);
            $form = $gCurrentSession->getFormObject($_POST['adm_csrf_token']);
            $service->saveGroup($target, $group, $form->validate($_POST));

            $gNavigation->deleteLastUrl();
            echo json_encode(array('status' => 'success', 'url' => $gNavigation->getUrl()));
            break;

        case 'delete':
            SecurityUtils::validateCsrfToken((string) ($_POST['adm_csrf_token'] ?? ''));
            $service->deleteGroup($target, $service->getGroup($target, $getUuid));
            echo json_encode(array('status' => 'success'));
            break;
    }
} catch (Throwable $e) {
    handleException($e, in_array($getMode ?? '', array('save', 'delete'), true));
}
