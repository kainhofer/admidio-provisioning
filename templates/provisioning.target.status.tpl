<div id="adm_provisioning_actions" class="mb-3" data-csrf="{$csrfToken}">
    <button type="button" class="btn btn-primary" data-provisioning-action="sync" data-url="{$syncUrl}">
        <i class="bi bi-arrow-repeat"></i> {$l10n->get('PLG_PROVISIONING_SYNC_NOW')}</button>
    <button type="button" class="btn btn-secondary" data-provisioning-action="verify" data-url="{$verifyUrl}">
        <i class="bi bi-search"></i> {$l10n->get('PLG_PROVISIONING_VERIFY_NOW')}</button>
    <button type="button" class="btn btn-secondary" data-provisioning-action="test" data-url="{$testUrl}">
        <i class="bi bi-plug"></i> {$l10n->get('PLG_PROVISIONING_TEST_CONNECTION')}</button>
    {if $attention|@count > 0}
        <button type="button" class="btn btn-secondary" data-provisioning-action="retry" data-url="{$retryAllUrl}">
            <i class="bi bi-arrow-counterclockwise"></i> {$l10n->get('PLG_PROVISIONING_RETRY_ALL')}</button>
    {/if}
</div>
<div id="adm_provisioning_result" class="alert" role="alert" style="display: none;"></div>

<div class="card admidio-field-group">
    <div class="card-header">{$l10n->get('PLG_PROVISIONING_STATUS')}</div>
    <div class="card-body">
        <table class="table table-condensed">
            <tbody>
                {foreach $properties as $label => $value}
                    <tr><th style="width: 35%;">{$label}</th><td style="word-break: break-word;">{$value nofilter}</td></tr>
                {/foreach}
            </tbody>
        </table>
    </div>
</div>

<div class="card admidio-field-group">
    <div class="card-header">{$l10n->get('PLG_PROVISIONING_ATTENTION')}</div>
    <div class="card-body">
        {if $attention|@count eq 0}
            <p>{$l10n->get('PLG_PROVISIONING_NO_ATTENTION')}</p>
        {else}
            <div class="table-responsive">
                <table class="table table-condensed table-hover">
                    <thead><tr>{foreach $attentionHeaders as $header}<th>{$header}</th>{/foreach}</tr></thead>
                    <tbody>
                        {foreach $attention as $item}
                            <tr id="{$item.id}">
                                {foreach $item.data as $cell}<td style="word-break: break-word;">{$cell nofilter}</td>{/foreach}
                                <td class="text-end">
                                    <a class="admidio-icon-link" href="javascript:void(0);" data-provisioning-action="retry" data-url="{$item.retryUrl}">
                                        <i class="bi bi-arrow-counterclockwise" data-bs-toggle="tooltip" title="{$l10n->get('PLG_PROVISIONING_RETRY')}"></i></a>
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        {/if}
    </div>
</div>

<script>
{literal}
(function () {
    const actions = document.getElementById('adm_provisioning_actions');
    const result = document.getElementById('adm_provisioning_result');
    const token = actions.dataset.csrf;

    function post(url) {
        const body = new FormData();
        body.append('adm_csrf_token', token);
        return fetch(url, {method: 'POST', body: body, credentials: 'same-origin'}).then(function (response) {
            return response.json();
        });
    }

    function show(type, html) {
        result.className = 'alert alert-' + type;
        result.innerHTML = html;
        result.style.display = '';
    }

    function withStep(url, step) {
        return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'step=' + step;
    }

    // Processes the queue in slices of a few seconds until it is empty, so that no single request
    // runs into a time limit. Closing the page loses nothing: the queue stays where it is.
    function run(url, step) {
        return post(withStep(url, step)).then(function (answer) {
            if (answer.status !== 'success') {
                show('danger', answer.message);
                return;
            }
            show('info', answer.message);
            if (answer.remaining > 0 && answer.progress) {
                return run(url, 'continue');
            }
            window.setTimeout(function () { window.location.reload(); }, 1500);
        });
    }

    document.querySelectorAll('[data-provisioning-action]').forEach(function (element) {
        element.addEventListener('click', function () {
            const url = element.dataset.url;
            const action = element.dataset.provisioningAction;
            document.querySelectorAll('[data-provisioning-action]').forEach(function (other) { other.classList.add('disabled'); });
            show('info', '<span class="spinner-border spinner-border-sm"></span>');

            let request;
            if (action === 'sync' || action === 'verify') {
                request = run(url, 'start');
            } else {
                request = post(url).then(function (answer) {
                    show(answer.status === 'success' ? 'success' : 'danger', answer.message);
                    if (answer.status === 'success' && action === 'retry') {
                        window.setTimeout(function () { window.location.reload(); }, 1000);
                    }
                });
            }
            request.catch(function (error) {
                show('danger', String(error));
            }).finally(function () {
                document.querySelectorAll('[data-provisioning-action]').forEach(function (other) { other.classList.remove('disabled'); });
            });
        });
    });
})();
{/literal}
</script>
