{**
 * Monokulo for PrestaShop
 *
 * @author    Monokulo
 * @copyright Monokulo
 * @license   https://www.gnu.org/licenses/gpl-3.0.html GPL-3.0-or-later
 *}
{extends file='page.tpl'}

{block name='page_title'}
  {$monokulo_title|escape:'html':'UTF-8'}
{/block}

{block name='page_content'}
  <div id="monokulo-error" class="alert alert-danger" role="alert">{$monokulo_error|escape:'html':'UTF-8'}</div>
  {if $monokulo_retry}
    <p><a id="monokulo-retry" class="btn btn-primary" href="{$monokulo_retry|escape:'html':'UTF-8'}" rel="nofollow">{$monokulo_try_again|escape:'html':'UTF-8'}</a></p>
  {/if}
{/block}
