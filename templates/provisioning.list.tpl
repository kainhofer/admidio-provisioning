{if $alert neq ''}
    <div class="alert alert-warning" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i> {$alert}
    </div>
{/if}
{if $rows|@count eq 0}
    <div class="alert alert-info" role="alert">
        <i class="bi bi-info-circle-fill"></i> {$messageNoData}
    </div>
{else}
    <div class="table-responsive">
        <table class="table table-condensed table-hover" style="max-width: 100%;">
            <thead>
                <tr>
                    {foreach $headers as $header}
                        <th>{$header}</th>
                    {/foreach}
                </tr>
            </thead>
            <tbody>
                {foreach $rows as $row}
                    <tr id="{$row.id}">
                        {foreach $row.data as $cell}
                            <td style="word-break: break-word;">{$cell nofilter}</td>
                        {/foreach}
                        <td class="text-end">
                            {include 'sys-template-parts/list.functions.tpl' data=$row}
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
{/if}
