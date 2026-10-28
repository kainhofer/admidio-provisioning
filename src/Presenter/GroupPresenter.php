<?php
namespace AdmidioPlugin\Provisioning\Presenter;

use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use AdmidioPlugin\Provisioning\Entity\Group;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\Service\Planner;
use AdmidioPlugin\Provisioning\Service\TargetService;

/**
 * The groups of a target: the manually configured ones, which can be edited here, and those that
 * come from the SSO role mapping and the catch-all option, which are only shown.
 */
class GroupPresenter extends ProvisioningPresenter
{
    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createList(Target $target): void
    {
        global $gDb, $gL10n;

        $targetUuid = (string) $target->getValue('pvt_uuid');
        $this->setHtmlID('adm_provisioning_groups');
        $this->setHeadline($gL10n->get('PLG_PROVISIONING_GROUPS_OF', array($target->readableName())));
        $this->addPageFunctionsMenuItem(
            'menu_item_provisioning_group_add',
            $gL10n->get('SYS_CREATE_VAR', array($gL10n->get('PLG_PROVISIONING_GROUP'))),
            $this->pageUrl('groups.php', array('mode' => 'edit', 'target_uuid' => $targetUuid)),
            'bi-plus-circle-fill'
        );

        // Show the groups as the next run will see them: the SSO and catch-all groups follow their sources.
        $planner = new Planner($gDb, $target);
        $alert = '';
        try {
            $planner->syncGroupDefinitions();
        } catch (\Throwable) {
            $alert = $gL10n->get('PLG_PROVISIONING_SSO_CLIENT_MISSING');
        }

        $roleNames = array();
        foreach ((new TargetService($gDb))->selectableRoles() as [$roleId, $roleName]) {
            $roleNames[$roleId] = $roleName;
        }

        $rows = array();
        foreach ($planner->groups() as $group) {
            $uuid = (string) $group['pvg_uuid'];
            $rowId = 'adm_provisioning_group_' . $uuid;

            $roles = array();
            foreach (Group::decodeRoleIds((string) $group['pvg_roles']) as $roleId) {
                $name = $roleNames[abs($roleId)] ?? ('#' . abs($roleId));
                $roles[] = SecurityUtils::encodeHTML($roleId < 0 ? $gL10n->get('PLG_PROVISIONING_LEADERS_OF', array($name)) : $name);
            }

            $actions = array();
            $manual = $group['pvg_source'] === Group::SOURCE_MANUAL;
            if ($manual && $group['pvg_state'] === Group::STATE_ACTIVE) {
                $actions[] = array(
                    'url' => $this->pageUrl('groups.php', array('mode' => 'edit', 'target_uuid' => $targetUuid, 'uuid' => $uuid)),
                    'icon' => 'bi bi-pencil-square',
                    'tooltip' => $gL10n->get('SYS_EDIT')
                );
                $actions[] = $this->deleteAction(
                    $rowId,
                    $this->pageUrl('groups.php', array('mode' => 'delete', 'target_uuid' => $targetUuid, 'uuid' => $uuid)),
                    $gL10n->get('SYS_WANT_DELETE_ENTRY', array((string) $group['pvg_name']))
                );
            }

            $stateText = $group['pvg_state'] === Group::STATE_DELETE
                ? $gL10n->get('PLG_PROVISIONING_GROUP_STATE_DELETE')
                : ((string) $group['pvg_remote_id'] === '' ? $gL10n->get('PLG_PROVISIONING_GROUP_STATE_NEW') : $gL10n->get('PLG_PROVISIONING_GROUP_STATE_SYNCED'));
            if ((string) $group['pvg_last_error'] !== '') {
                $stateText .= '<br><span class="text-danger">' . SecurityUtils::encodeHTML(Language::translateIfTranslationStrId((string) $group['pvg_last_error'])) . '</span>';
            }

            $rows[] = array(
                'id' => $rowId,
                'uuid' => $uuid,
                'data' => array(
                    SecurityUtils::encodeHTML((string) $group['pvg_name']),
                    $gL10n->get('PLG_PROVISIONING_GROUP_SOURCE_' . strtoupper((string) $group['pvg_source'])),
                    implode(', ', $roles),
                    $stateText,
                    self::formatTimestamp($group['pvg_last_sync'] ?? null)
                ),
                'actions' => $actions
            );
        }

        $this->assignSmartyVariable('headers', array(
            $gL10n->get('SYS_NAME'),
            $gL10n->get('PLG_PROVISIONING_GROUP_SOURCE'),
            $gL10n->get('SYS_ROLES'),
            $gL10n->get('PLG_PROVISIONING_STATE'),
            $gL10n->get('PLG_PROVISIONING_LAST_SYNC'),
            ''
        ));
        $this->assignSmartyVariable('rows', $rows);
        $this->assignSmartyVariable('messageNoData', $gL10n->get('PLG_PROVISIONING_NO_GROUPS'));
        $this->assignSmartyVariable('alert', $alert);
        $this->addHtmlByTemplate('provisioning.list.tpl');
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createEditForm(Target $target, Group $group): void
    {
        global $gDb, $gL10n, $gCurrentSession;

        if (!$group->isNewRecord() && $group->getValue('pvg_source') !== Group::SOURCE_MANUAL) {
            throw new Exception('SYS_NO_RIGHTS');
        }

        $targetUuid = (string) $target->getValue('pvt_uuid');
        $groupUuid = $group->isNewRecord() ? '' : (string) $group->getValue('pvg_uuid');
        $this->setHtmlID('adm_provisioning_group_edit');
        $this->setHeadline($gL10n->get(
            $group->isNewRecord() ? 'SYS_CREATE_VAR' : 'SYS_EDIT_VAR',
            array($gL10n->get('PLG_PROVISIONING_GROUP'))
        ));

        $members = array();
        $leaders = array();
        foreach ($group->getRoleIds() as $roleId) {
            if ($roleId > 0) {
                $members[] = $roleId;
            } else {
                $leaders[] = -$roleId;
            }
        }
        $roles = (new TargetService($gDb))->selectableRoles();

        $form = new FormPresenter(
            'adm_provisioning_group_edit_form',
            'provisioning.group.edit.tpl',
            $this->pageUrl('groups.php', array('mode' => 'save', 'target_uuid' => $targetUuid, 'uuid' => $groupUuid)),
            $this
        );
        $form->addInput('pvg_name', $gL10n->get('SYS_NAME'), (string) $group->getValue('pvg_name'),
            array('maxLength' => 255, 'property' => FormPresenter::FIELD_REQUIRED, 'helpTextId' => 'PLG_PROVISIONING_GROUP_NAME_DESC'));
        $form->addSelectBox('pvg_member_roles', $gL10n->get('PLG_PROVISIONING_GROUP_MEMBER_ROLES'), $roles,
            array('defaultValue' => $members, 'multiselect' => true, 'helpTextId' => 'PLG_PROVISIONING_GROUP_MEMBER_ROLES_DESC'));
        $form->addSelectBox('pvg_leader_roles', $gL10n->get('PLG_PROVISIONING_GROUP_LEADER_ROLES'), $roles,
            array('defaultValue' => $leaders, 'multiselect' => true, 'helpTextId' => 'PLG_PROVISIONING_GROUP_LEADER_ROLES_DESC'));
        $form->addSubmitButton('adm_button_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));

        $this->assignSmartyVariable('userCreatedName', $group->getNameOfCreatingUser());
        $this->assignSmartyVariable('userCreatedTimestamp', $group->getValue('pvg_timestamp_create'));
        $this->assignSmartyVariable('lastUserEditedName', $group->getNameOfLastEditingUser());
        $this->assignSmartyVariable('lastUserEditedTimestamp', $group->getValue('pvg_timestamp_change'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }
}
