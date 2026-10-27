<form {foreach $attributes as $attribute}{$attribute@key}="{$attribute}" {/foreach}>
    <div class="admidio-form-required-notice"><span>{$l10n->get('SYS_REQUIRED_INPUT')}</span></div>

    {include 'sys-template-parts/form.input.tpl' data=$elements['adm_csrf_token']}
    {include 'sys-template-parts/form.input.tpl' data=$elements['pvt_connector']}
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('SYS_COMMON')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.input.tpl' data=$elements['pvt_name']}
            {include 'sys-template-parts/form.input.tpl' data=$elements['pvt_connector_label']}
            {include 'sys-template-parts/form.checkbox.tpl' data=$elements['pvt_enabled']}
        </div>
    </div>
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('PLG_PROVISIONING_CONNECTION')}</div>
        <div class="card-body">
            {foreach $connectionFields as $fieldId => $fieldTemplate}
                {include "sys-template-parts/$fieldTemplate" data=$elements[$fieldId]}
            {/foreach}
        </div>
    </div>
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('PLG_PROVISIONING_USERS')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.select.tpl' data=$elements['pvt_sso_link']}
            {include 'sys-template-parts/form.select.tpl' data=$elements['pvt_user_roles']}
        </div>
    </div>
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('PLG_PROVISIONING_ATTRIBUTES')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.select.tpl' data=$elements['pvt_account_name_source']}
            {foreach $attributeFields as $fieldId => $fieldTemplate}
                {include "sys-template-parts/$fieldTemplate" data=$elements[$fieldId]}
            {/foreach}
            {foreach $customRows as $customRow}
                {include 'sys-template-parts/form.input.tpl' data=$elements[$customRow[0]]}
                {include 'sys-template-parts/form.select.tpl' data=$elements[$customRow[1]]}
            {/foreach}
        </div>
    </div>
    <div class="card admidio-field-group">
        <div class="card-header">{$l10n->get('PLG_PROVISIONING_GROUPS_AND_SCHEDULE')}</div>
        <div class="card-body">
            {include 'sys-template-parts/form.checkbox.tpl' data=$elements['pvt_group_catchall']}
            {include 'sys-template-parts/form.select.tpl' data=$elements['pvt_verify_interval']}
        </div>
    </div>
    <div class="form-alert" style="display: none;">&nbsp;</div>
    {include 'sys-template-parts/form.button.tpl' data=$elements['adm_button_save']}
    {include file="sys-template-parts/system.info-create-edit.tpl"}
</form>
