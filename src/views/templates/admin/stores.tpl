{**
 * Monokulo for PrestaShop
 *
 * @author    Monokulo
 * @copyright Monokulo
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 *}
<div class="panel" id="monokulo-stores">
  <div class="panel-heading"><i class="icon-link"></i> {$monokulo_text.stores|escape:'html':'UTF-8'}</div>
  <p class="help-block">{$monokulo_text.stores_help|escape:'html':'UTF-8'}</p>
  {if !$monokulo_can_connect}
    <p class="alert alert-warning" id="monokulo-address-first">{$monokulo_text.address_first|escape:'html':'UTF-8'}</p>
  {/if}
  <table class="table">
    <tbody>
      {foreach from=$monokulo_stores item=store}
        <tr id="monokulo-store-{$store.id|intval}">
          <td><strong>{$store.name|escape:'html':'UTF-8'}</strong><br><span class="text-muted">{$store.url|escape:'html':'UTF-8'}</span></td>
          <td>
            {if $store.connected}
              {$store.connected|escape:'html':'UTF-8'}
              {if $store.reconnect}<div class="text-danger">{$monokulo_text.reconnect_needed|escape:'html':'UTF-8'}</div>{/if}
            {else}
              {$monokulo_text.not_connected|escape:'html':'UTF-8'}
            {/if}
          </td>
          <td class="text-right">
            {if $monokulo_can_connect}
              <a href="{$store.connect|escape:'html':'UTF-8'}" class="btn {if $store.connected && !$store.reconnect}btn-default{else}btn-primary{/if} monokulo-connect">{if $store.connected}{$monokulo_text.reconnect|escape:'html':'UTF-8'}{else}{$monokulo_text.connect|escape:'html':'UTF-8'}{/if}</a>
            {/if}
          </td>
        </tr>
      {/foreach}
    </tbody>
  </table>
</div>
