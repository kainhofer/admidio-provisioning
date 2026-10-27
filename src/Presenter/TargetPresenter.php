<?php
namespace AdmidioPlugin\Provisioning\Presenter;

use Admidio\Changelog\Service\ChangelogService;
use Admidio\Infrastructure\Exception;
use Admidio\Infrastructure\Language;
use Admidio\Infrastructure\Utils\SecurityUtils;
use Admidio\UI\Presenter\FormPresenter;
use AdmidioPlugin\Provisioning\Connector\Connectors;
use AdmidioPlugin\Provisioning\Entity\Target;
use AdmidioPlugin\Provisioning\ProvisioningTables;
use AdmidioPlugin\Provisioning\Service\Planner;
use AdmidioPlugin\Provisioning\Service\Queue;
use AdmidioPlugin\Provisioning\Service\TargetService;
use AdmidioPlugin\Provisioning\Service\TargetStatus;
use Throwable;

/**
 * List of the targets, their edit form and their status page.
 */
class TargetPresenter extends ProvisioningPresenter
{
    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createList(): void
    {
        global $gDb, $gL10n, $gCurrentOrgId;

        $this->setHtmlID('adm_provisioning_targets');
        $this->setHeadline($gL10n->get('PLG_PROVISIONING_TARGETS'));

        foreach (Connectors::all() as $type => $class) {
            $this->addPageFunctionsMenuItem(
                'menu_item_provisioning_add_' . $type,
                $gL10n->get('SYS_CREATE_VAR', array($gL10n->get($class::getLabel()))),
                $this->pageUrl('index.php', array('mode' => 'edit', 'connector' => $type)),
                'bi-plus-circle-fill'
            );
        }
        ChangelogService::displayHistoryButton($this, 'provisioning', array('provisioning_targets', 'provisioning_groups'));

        $queue = new Queue($gDb);
        $status = new TargetStatus($gDb);
        $service = new TargetService($gDb);
        $ssoClients = $service->ssoClients();
        $connectors = Connectors::all();
        $cronWarning = false;
        $rows = array();

        foreach ($gDb->queryPrepared(
            'SELECT pvt_id FROM ' . ProvisioningTables::targets() . ' WHERE pvt_org_id = ? ORDER BY pvt_name',
            array((int) $gCurrentOrgId)
        )->fetchAll(\PDO::FETCH_COLUMN) as $targetId) {
            $target = new Target($gDb, (int) $targetId);
            $uuid = (string) $target->getValue('pvt_uuid');
            $state = $status->get($target->getId());
            $counts = $queue->counts($target->getId());
            $connector = (string) $target->getValue('pvt_connector', 'database');
            $link = $target->getSsoLink();
            $rowId = 'adm_provisioning_target_' . $uuid;

            if ($target->isEnabled() && !self::cronSeen((string) ($state['pvs_last_cli_run'] ?? ''))) {
                $cronWarning = true;
            }

            $stateText = $status->isPaused($target->getId())
                ? '<span class="text-danger">' . $gL10n->get('PLG_PROVISIONING_STATE_PAUSED') . '</span>'
                : $gL10n->get('PLG_PROVISIONING_STATE_' . strtoupper((string) ($state['pvs_state'] ?? 'ok')));
            if ($counts[Queue::STATE_ATTENTION] > 0) {
                $stateText .= ' <span class="badge bg-warning text-dark">' . $counts[Queue::STATE_ATTENTION] . '</span>';
            }

            $rows[] = array(
                'id' => $rowId,
                'uuid' => $uuid,
                'data' => array(
                    '<a href="' . $this->pageUrl('index.php', array('mode' => 'status', 'uuid' => $uuid)) . '">'
                        . SecurityUtils::encodeHTML($target->readableName()) . '</a>',
                    isset($connectors[$connector]) ? $gL10n->get($connectors[$connector]::getLabel()) : SecurityUtils::encodeHTML($connector),
                    $target->isEnabled() ? $gL10n->get('SYS_YES') : $gL10n->get('SYS_NO'),
                    $link === null ? '-' : SecurityUtils::encodeHTML($ssoClients[$link[0] . ':' . $link[1]] ?? '?'),
                    (string) self::countRows(ProvisioningTables::accounts(), 'pva_pvt_id', $target->getId()),
                    (string) self::countRows(ProvisioningTables::groups(), 'pvg_pvt_id', $target->getId()),
                    (string) $counts[Queue::STATE_PENDING],
                    $stateText,
                    self::formatTimestamp($state['pvs_last_run'] ?? null)
                ),
                'actions' => array(
                    array('url' => $this->pageUrl('index.php', array('mode' => 'status', 'uuid' => $uuid)),
                        'icon' => 'bi bi-activity', 'tooltip' => $gL10n->get('PLG_PROVISIONING_STATUS')),
                    array('url' => $this->pageUrl('index.php', array('mode' => 'edit', 'uuid' => $uuid)),
                        'icon' => 'bi bi-pencil-square', 'tooltip' => $gL10n->get('SYS_EDIT')),
                    array('url' => $this->pageUrl('groups.php', array('target_uuid' => $uuid)),
                        'icon' => 'bi bi-people', 'tooltip' => $gL10n->get('PLG_PROVISIONING_GROUPS')),
                    $this->deleteAction(
                        $rowId,
                        $this->pageUrl('index.php', array('mode' => 'delete', 'uuid' => $uuid)),
                        $gL10n->get('PLG_PROVISIONING_DISCONNECT_CONFIRM', array($target->readableName()))
                    )
                )
            );
        }

        $this->assignSmartyVariable('headers', array(
            $gL10n->get('SYS_NAME'),
            $gL10n->get('PLG_PROVISIONING_CONNECTOR'),
            $gL10n->get('SYS_ENABLED'),
            $gL10n->get('PLG_PROVISIONING_SSO_LINK'),
            $gL10n->get('PLG_PROVISIONING_ACCOUNTS'),
            $gL10n->get('PLG_PROVISIONING_GROUPS'),
            $gL10n->get('PLG_PROVISIONING_QUEUE'),
            $gL10n->get('PLG_PROVISIONING_STATE'),
            $gL10n->get('PLG_PROVISIONING_LAST_RUN'),
            ''
        ));
        $this->assignSmartyVariable('rows', $rows);
        $this->assignSmartyVariable('messageNoData', $gL10n->get('PLG_PROVISIONING_NO_TARGETS'));
        $this->assignSmartyVariable('alert', $cronWarning ? $gL10n->get('PLG_PROVISIONING_NO_CRON_WARNING') : '');
        $this->addHtmlByTemplate('provisioning.list.tpl');
    }

    /**
     * @throws Exception|\Smarty\Exception
     */
    public function createEditForm(string $targetUuid, string $connectorType): void
    {
        global $gDb, $gL10n, $gCurrentSession, $gProfileFields;

        $service = new TargetService($gDb);
        $target = $service->getTarget($targetUuid);
        $connectors = Connectors::all();

        if ($target->isNewRecord()) {
            if (!isset($connectors[$connectorType])) {
                throw new Exception('SYS_INVALID_PAGE_VIEW');
            }
        } else {
            $connectorType = (string) $target->getValue('pvt_connector', 'database');
        }
        $class = $connectors[$connectorType] ?? null;
        if ($class === null) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $this->setHtmlID('adm_provisioning_target_edit');
        $this->setHeadline($gL10n->get(
            $target->isNewRecord() ? 'SYS_CREATE_VAR' : 'SYS_EDIT_VAR',
            array($gL10n->get('PLG_PROVISIONING_TARGET'))
        ));
        if (!$target->isNewRecord()) {
            ChangelogService::displayHistoryButton($this, 'provisioning', 'provisioning_targets', true,
                array('uuid' => $targetUuid));
        }

        $form = new FormPresenter(
            'adm_provisioning_target_edit_form',
            'provisioning.target.edit.tpl',
            $this->pageUrl('index.php', array('mode' => 'save', 'uuid' => $targetUuid, 'connector' => $connectorType)),
            $this
        );

        // general
        $form->addInput('pvt_name', $gL10n->get('SYS_NAME'), (string) $target->getValue('pvt_name'),
            array('maxLength' => 255, 'property' => FormPresenter::FIELD_REQUIRED));
        $form->addInput('pvt_connector_label', $gL10n->get('PLG_PROVISIONING_CONNECTOR'), $gL10n->get($class::getLabel()),
            array('property' => FormPresenter::FIELD_DISABLED));
        $form->addInput('pvt_connector', '', $connectorType, array('property' => FormPresenter::FIELD_HIDDEN));
        $form->addCheckbox('pvt_enabled', $gL10n->get('SYS_ENABLED'), (bool) $target->getValue('pvt_enabled'),
            array('helpTextId' => 'PLG_PROVISIONING_ENABLED_DESC'));

        // connection, as declared by the connector
        $config = $target->getConfig();
        $secrets = $target->getSecrets();
        $connectionFields = array();
        foreach ($class::getConfigFields() as $field => $definition) {
            $id = 'cfg_' . $field;
            $label = $gL10n->get((string) ($definition['label'] ?? $field));
            $options = array();
            if (!empty($definition['required'])) {
                $options['property'] = FormPresenter::FIELD_REQUIRED;
            }
            $value = $config[$field] ?? ($definition['default'] ?? '');
            $type = (string) ($definition['type'] ?? 'string');

            if (!empty($definition['secret'])) {
                $options['type'] = 'password';
                $options['autocomplete'] = 'new-password';
                $options['helpTextId'] = isset($secrets[$field]) && $secrets[$field] !== ''
                    ? 'PLG_PROVISIONING_SECRET_STORED' : ($definition['description'] ?? '');
                $form->addInput($id, $label, '', $options);
                $connectionFields[$id] = 'form.input.tpl';
                continue;
            }
            if (!empty($definition['description'])) {
                $options['helpTextId'] = $definition['description'];
            }

            switch ($type) {
                case 'boolean':
                    $form->addCheckbox($id, $label, (bool) $value, $options);
                    $connectionFields[$id] = 'form.checkbox.tpl';
                    break;
                case 'enum':
                    $values = array();
                    foreach ((array) ($definition['values'] ?? array()) as $key => $text) {
                        $values[$key] = $gL10n->get((string) $text);
                    }
                    $form->addSelectBox($id, $label, $values, $options + array('defaultValue' => (string) $value));
                    $connectionFields[$id] = 'form.select.tpl';
                    break;
                default:
                    $options['type'] = match ($type) {
                        'url' => 'url',
                        'integer' => 'number',
                        default => 'text'
                    };
                    $options['maxLength'] = 2000;
                    $form->addInput($id, $label, (string) $value, $options);
                    $connectionFields[$id] = 'form.input.tpl';
            }
        }

        // users
        $ssoClients = $service->ssoClients();
        $link = $target->getSsoLink();
        $form->addSelectBox('pvt_sso_link', $gL10n->get('PLG_PROVISIONING_SSO_LINK'), $ssoClients, array(
            'defaultValue' => $link === null ? '' : $link[0] . ':' . $link[1],
            'firstEntry' => $gL10n->get('PLG_PROVISIONING_SSO_LINK_NONE'),
            'helpTextId' => 'PLG_PROVISIONING_SSO_LINK_DESC'
        ));
        $roles = $service->selectableRoles();
        $form->addSelectBox('pvt_user_roles', $gL10n->get('PLG_PROVISIONING_USER_ROLES'), $roles, array(
            'defaultValue' => $target->getUserRoleIds(),
            'multiselect' => true,
            'helpTextId' => 'PLG_PROVISIONING_USER_ROLES_DESC'
        ));

        // account name and attributes
        $sources = self::sourceOptions($gProfileFields);
        $form->addSelectBox('pvt_account_name_source', $gL10n->get('PLG_PROVISIONING_ACCOUNT_NAME'), $sources, array(
            'defaultValue' => (string) $target->getValue('pvt_account_name_source', 'database'),
            'firstEntry' => $gL10n->get('PLG_PROVISIONING_ACCOUNT_NAME_DEFAULT'),
            'helpTextId' => 'PLG_PROVISIONING_ACCOUNT_NAME_DESC'
        ));

        $mapping = array();
        foreach ($target->getAttributeMapping() as $entry) {
            $mapping[$entry['path']] = $entry['source'];
        }
        if ($target->isNewRecord()) {
            $mapping = $class::getDefaultAttributeMapping();
        }

        $attributeFields = array();
        $index = 0;
        foreach ($class::getAttributeTargets() as $path => $labelId) {
            $id = 'map_' . $index++;
            $form->addSelectBox($id, $gL10n->get($labelId), $sources, array('defaultValue' => $mapping[$path] ?? ''));
            $attributeFields[$id] = 'form.select.tpl';
            unset($mapping[$path]);
        }
        // attributes the connector does not offer by name: the remaining ones, then empty rows
        $custom = array_slice($mapping, 0, TargetService::CUSTOM_MAPPING_ROWS, true);
        $customRows = array();
        for ($row = 1; $row <= TargetService::CUSTOM_MAPPING_ROWS; ++$row) {
            $path = (string) (array_keys($custom)[$row - 1] ?? '');
            $form->addInput('map_custom_path_' . $row, $gL10n->get('PLG_PROVISIONING_CUSTOM_ATTRIBUTE', array((string) $row)), $path,
                array('maxLength' => 255, 'helpTextId' => $row === 1 ? 'PLG_PROVISIONING_CUSTOM_ATTRIBUTE_DESC' : ''));
            $form->addSelectBox('map_custom_source_' . $row, $gL10n->get('PLG_PROVISIONING_CUSTOM_ATTRIBUTE_SOURCE', array((string) $row)),
                $sources, array('defaultValue' => $custom[$path] ?? ''));
            $customRows[] = array('map_custom_path_' . $row, 'map_custom_source_' . $row);
        }

        // groups and schedule
        $form->addCheckbox('pvt_group_catchall', $gL10n->get('PLG_PROVISIONING_GROUP_CATCHALL'),
            (bool) $target->getValue('pvt_group_catchall'), array('helpTextId' => 'PLG_PROVISIONING_GROUP_CATCHALL_DESC'));
        $intervals = array();
        foreach (TargetService::verifyIntervals() as $interval) {
            $intervals[$interval] = self::intervalLabel($interval);
        }
        $form->addSelectBox('pvt_verify_interval', $gL10n->get('PLG_PROVISIONING_VERIFY_INTERVAL'), $intervals, array(
            'defaultValue' => (string) $target->getVerifyInterval(),
            'property' => FormPresenter::FIELD_REQUIRED,
            'helpTextId' => 'PLG_PROVISIONING_VERIFY_INTERVAL_DESC'
        ));

        $form->addSubmitButton('adm_button_save', $gL10n->get('SYS_SAVE'), array('icon' => 'bi-check-lg'));

        $this->assignSmartyVariable('connectionFields', $connectionFields);
        $this->assignSmartyVariable('attributeFields', $attributeFields);
        $this->assignSmartyVariable('customRows', $customRows);
        $this->assignSmartyVariable('userCreatedName', $target->getNameOfCreatingUser());
        $this->assignSmartyVariable('userCreatedTimestamp', $target->getValue('pvt_timestamp_create'));
        $this->assignSmartyVariable('lastUserEditedName', $target->getNameOfLastEditingUser());
        $this->assignSmartyVariable('lastUserEditedTimestamp', $target->getValue('pvt_timestamp_change'));
        $form->addToHtmlPage();
        $gCurrentSession->addFormObject($form);
    }

    /**
     * The status page: state, runs, queue, items needing attention and the actions test,
     * synchronize, verify and retry.
     *
     * @throws Exception|\Smarty\Exception
     */
    public function createStatus(string $targetUuid): void
    {
        global $gDb, $gL10n, $gCurrentSession;

        $target = (new TargetService($gDb))->getTarget($targetUuid);
        if ($target->isNewRecord()) {
            throw new Exception('SYS_INVALID_PAGE_VIEW');
        }

        $this->setHtmlID('adm_provisioning_target_status');
        $this->setHeadline($target->readableName());
        $this->addPageFunctionsMenuItem('menu_item_provisioning_edit', $gL10n->get('SYS_EDIT'),
            $this->pageUrl('index.php', array('mode' => 'edit', 'uuid' => $targetUuid)), 'bi-pencil-square');
        $this->addPageFunctionsMenuItem('menu_item_provisioning_groups', $gL10n->get('PLG_PROVISIONING_GROUPS'),
            $this->pageUrl('groups.php', array('target_uuid' => $targetUuid)), 'bi-people');

        $status = new TargetStatus($gDb);
        $queue = new Queue($gDb);
        $state = $status->get($target->getId());
        $counts = $queue->counts($target->getId());
        $paused = $status->isPaused($target->getId());

        $capabilities = json_decode((string) ($state['pvs_capabilities'] ?? ''), true);
        $capabilityText = array();
        foreach (is_array($capabilities) ? $capabilities : array() as $name => $value) {
            $capabilityText[] = SecurityUtils::encodeHTML($name . ': ' . $value);
        }

        $linkedClient = '';
        try {
            $client = (new Planner($gDb, $target))->ssoClient();
            $linkedClient = $client === null ? '-' : SecurityUtils::encodeHTML($client->readableName());
        } catch (Throwable) {
            $linkedClient = '<span class="text-danger">' . $gL10n->get('PLG_PROVISIONING_SSO_CLIENT_MISSING') . '</span>';
        }

        $properties = array(
            $gL10n->get('SYS_ENABLED') => $target->isEnabled() ? $gL10n->get('SYS_YES') : $gL10n->get('SYS_NO'),
            $gL10n->get('PLG_PROVISIONING_STATE') => $paused
                ? '<span class="text-danger">' . $gL10n->get('PLG_PROVISIONING_STATE_PAUSED') . '</span> ('
                    . self::formatTimestamp($state['pvs_paused_until'] ?? null) . ')'
                : $gL10n->get('PLG_PROVISIONING_STATE_' . strtoupper((string) ($state['pvs_state'] ?? 'ok'))),
            $gL10n->get('PLG_PROVISIONING_MESSAGE') => SecurityUtils::encodeHTML(
                Language::translateIfTranslationStrId((string) ($state['pvs_message'] ?? ''))
            ),
            $gL10n->get('PLG_PROVISIONING_SSO_LINK') => $linkedClient,
            $gL10n->get('PLG_PROVISIONING_ACCOUNTS') => (string) self::countRows(ProvisioningTables::accounts(), 'pva_pvt_id', $target->getId()),
            $gL10n->get('PLG_PROVISIONING_SKIPPED_USERS') => (string) (int) ($state['pvs_skipped_users'] ?? 0),
            $gL10n->get('PLG_PROVISIONING_GROUPS') => (string) self::countRows(ProvisioningTables::groups(), 'pvg_pvt_id', $target->getId()),
            $gL10n->get('PLG_PROVISIONING_QUEUE') => (string) $counts[Queue::STATE_PENDING],
            $gL10n->get('PLG_PROVISIONING_ATTENTION') => (string) $counts[Queue::STATE_ATTENTION],
            $gL10n->get('PLG_PROVISIONING_LAST_RUN') => self::formatTimestamp($state['pvs_last_run'] ?? null),
            $gL10n->get('PLG_PROVISIONING_LAST_RECONCILIATION') => self::formatTimestamp($state['pvs_last_reconcile_date'] ?? null, false),
            $gL10n->get('PLG_PROVISIONING_LAST_VERIFICATION') => self::formatTimestamp($state['pvs_last_verify'] ?? null),
            $gL10n->get('PLG_PROVISIONING_LAST_CLI_RUN') => self::formatTimestamp($state['pvs_last_cli_run'] ?? null)
                . (self::cronSeen((string) ($state['pvs_last_cli_run'] ?? '')) ? ''
                    : ' <span class="text-warning">' . $gL10n->get('PLG_PROVISIONING_NO_CRON') . '</span>'),
            $gL10n->get('PLG_PROVISIONING_CAPABILITIES') => $capabilityText === array() ? '-' : implode('<br>', $capabilityText)
        );

        $attention = array();
        foreach ($queue->attentionItems($target->getId()) as $item) {
            $rowId = 'adm_provisioning_item_' . (int) $item['pvq_id'];
            $name = $item['pvq_type'] === Queue::TYPE_GROUP
                ? SecurityUtils::encodeHTML((string) ($item['pvg_name'] ?? $item['pvq_key']))
                : self::userName((int) ($item['usr_id'] ?? 0), (string) $item['pvq_key']);
            $attention[] = array(
                'id' => $rowId,
                'data' => array(
                    $gL10n->get($item['pvq_type'] === Queue::TYPE_GROUP ? 'PLG_PROVISIONING_GROUP' : 'PLG_PROVISIONING_ACCOUNT'),
                    $name,
                    (string) (int) $item['pvq_attempts'],
                    SecurityUtils::encodeHTML((string) $item['pvq_last_error'])
                ),
                'retryUrl' => $this->pageUrl('index.php', array('mode' => 'retry', 'uuid' => $targetUuid, 'item' => (int) $item['pvq_id']))
            );
        }

        $this->assignSmartyVariable('properties', $properties);
        $this->assignSmartyVariable('attention', $attention);
        $this->assignSmartyVariable('attentionHeaders', array(
            $gL10n->get('SYS_TYPE'), $gL10n->get('SYS_NAME'), $gL10n->get('PLG_PROVISIONING_ATTEMPTS'), $gL10n->get('PLG_PROVISIONING_ERROR'), ''
        ));
        $this->assignSmartyVariable('testUrl', $this->pageUrl('index.php', array('mode' => 'test', 'uuid' => $targetUuid)));
        $this->assignSmartyVariable('syncUrl', $this->pageUrl('index.php', array('mode' => 'sync', 'uuid' => $targetUuid)));
        $this->assignSmartyVariable('verifyUrl', $this->pageUrl('index.php', array('mode' => 'verify', 'uuid' => $targetUuid)));
        $this->assignSmartyVariable('retryAllUrl', $this->pageUrl('index.php', array('mode' => 'retry', 'uuid' => $targetUuid)));
        $this->assignSmartyVariable('csrfToken', $gCurrentSession->getCsrfToken());
        $this->addHtmlByTemplate('provisioning.target.status.tpl');
    }

    /**
     * Admidio fields that can be sent: basic data and the visible profile fields.
     *
     * @return array<int,array{0:string,1:string,2:string}>
     */
    public static function sourceOptions(mixed $profileFields): array
    {
        global $gL10n;

        $basic = $gL10n->get('SYS_BASIC_DATA');
        $options = array(
            array('usr_login_name', $gL10n->get('SYS_USERNAME'), $basic),
            array('usr_uuid', $gL10n->get('PLG_PROVISIONING_SOURCE_UUID'), $basic),
            array('usr_id', $gL10n->get('PLG_PROVISIONING_SOURCE_ID'), $basic),
            array('fullname', $gL10n->get('SYS_NAME'), $basic)
        );
        foreach ($profileFields->getProfileFields() as $field) {
            $options[] = array(
                (string) $field->getValue('usf_name_intern'),
                (string) $field->getValue('usf_name'),
                Language::translateIfTranslationStrId((string) $field->getValue('cat_name'))
            );
        }
        return $options;
    }

    public static function intervalLabel(int $interval): string
    {
        global $gL10n;

        return match (true) {
            $interval === Target::VERIFY_MANUAL => $gL10n->get('PLG_PROVISIONING_VERIFY_MANUAL'),
            $interval === Target::VERIFY_HOURLY => $gL10n->get('PLG_PROVISIONING_VERIFY_HOURLY'),
            $interval === 1 => $gL10n->get('PLG_PROVISIONING_VERIFY_DAILY'),
            default => $gL10n->get('PLG_PROVISIONING_VERIFY_DAYS', array((string) $interval))
        };
    }

    /**
     * Whether a cron run was seen in the last 25 hours.
     */
    private static function cronSeen(string $lastCliRun): bool
    {
        return $lastCliRun !== '' && strtotime($lastCliRun) > time() - 25 * 3600;
    }

    private static function userName(int $userId, string $uuid): string
    {
        global $gDb;

        if ($userId === 0) {
            return SecurityUtils::encodeHTML($uuid);
        }
        $user = new \Admidio\Users\Entity\User($gDb, null, $userId);
        return SecurityUtils::encodeHTML(trim($user->getValue('FIRST_NAME') . ' ' . $user->getValue('LAST_NAME')))
            ?: SecurityUtils::encodeHTML($uuid);
    }

    private static function countRows(string $table, string $column, int $targetId): int
    {
        global $gDb;

        return (int) $gDb->queryPrepared('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = ?', array($targetId))->fetchColumn();
    }
}
