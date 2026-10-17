{combine_css path=$PU_PATH|cat:'css/parallel_upload.css' order=100}

<div class="pu{if $themeconf.colorscheme == 'dark'} pu-dark{/if}">
  <div class="pu-settings-head">
    <h2><span class="icon-cog"></span> {'Parallel Upload settings'|translate}</h2>
    <a class="buttonLike" href="{$PU_UPLOAD_URL}"><span class="icon-upload"></span> {'Open the uploader'|translate}</a>
  </div>

  {foreach from=$PU_WARNINGS item=w}
    <div class="pu-warn"><span class="icon-attention"></span> {$w}</div>
  {/foreach}

  <form method="post" action="{$PU_SETTINGS_URL}" class="pu-card pu-settings">
      <input type="hidden" name="pwg_token" value="{$PU_TOKEN}">
    <input type="hidden" name="pu_form" value="2">
      <div class="pu-grid">
        <label>{'Proxy request limit'|translate} (MB)
          <input type="number" name="proxy_limit_mb" min="10" max="5000" step="1" value="{$PU_CFG.proxy_limit_mb}">
          <small>{'Largest request the proxy in front of Piwigo accepts. Cloudflare: 100 on Free/Pro, 200 Business, 500 Enterprise. Chunks stay 5% under it.'|translate}</small>
        </label>
        <label>{'Max chunk size'|translate} (MB)
          <input type="number" name="chunk_size_mb" min="1" max="{$PU_PROXY_MAX_MB}" step="1" value="{$PU_CFG.chunk_size_mb}">
          <small>{'Used as-is when no upload speed is entered, and as the ceiling when one is. At most %d with the current proxy limit.'|translate|sprintf:$PU_PROXY_MAX_MB}</small>
        </label>
        <label class="pu-span2">{'Max parallel connections'|translate}
          <input type="number" name="concurrency" min="1" max="{$PU_CONC.input_max}" value="{$PU_CFG.concurrency}">
          <small>{'Each connection carries roughly 60 Mbps through a tunnel: 4 ≈ 250 Mbps, 8 ≈ 500 Mbps. Going higher than the upload link needs does not help.'|translate}</small>
          {if $PU_CONC.state == 'ok'}
            <small class="pu-hint-ok">{$PU_CONC.label} ({$PU_CONC.setting} = {$PU_CONC.limit}) {'allows up to'|translate} {if $PU_CONC.max >= 16}16{else}{$PU_CONC.max}{/if} {'here, so it is not a limit at this setting. Your upload speed is.'|translate}</small>
          {elseif $PU_CONC.state == 'tight'}
            <small class="pu-hint-tight">{$PU_CONC.label}: {$PU_CONC.setting} = {$PU_CONC.limit}, {'so up to'|translate} <b>{$PU_CONC.max}</b> {'here (two workers stay free for page loads and finishing uploads). Raise'|translate} {$PU_CONC.setting} {'to go further.'|translate}</small>
          {else}
            <small>{'Every connection also takes one PHP worker; keep this at least 2 under the server\'s limit:'|translate}</small>
          {/if}
          <details class="pu-limits"{if $PU_CONC.state == 'unknown'} open{/if}>
            <summary>{'Server limits by setup'|translate}</summary>
            <table>
              <tr{if $PU_CONC.type == 'apache'} class="pu-this"{/if}><th>Apache + mod_php</th><td>MaxRequestWorkers</td><td>{'Debian default'|translate} 150</td><td>/etc/apache2/mods-enabled/mpm_prefork.conf</td></tr>
              <tr{if $PU_CONC.type == 'fpm'} class="pu-this"{/if}><th>PHP-FPM</th><td>pm.max_children</td><td>{'Debian default'|translate} 5</td><td>/etc/php/8.x/fpm/pool.d/www.conf</td></tr>
              <tr><th>{'Proxy / tunnel'|translate}</th><td>—</td><td>{'no connection limit'|translate}</td><td>{'limits request size, see above'|translate}</td></tr>
            </table>
            {if $PU_CONC.type == 'fpm' || $PU_CONC.type == 'apache'}<small>{'Highlighted: this server.'|translate}</small>{/if}
          </details>
        </label>
        <label>{'Retries per chunk'|translate}
          <input type="number" name="max_retries" min="0" max="20" value="{$PU_CFG.max_retries}">
          <small>{'Back-off doubles each time (1 s, 2 s, 4 s…).'|translate}</small>
        </label>
        <label>{'Speed target'|translate} (%)
          <input type="number" name="speed_target_pct" min="10" max="100" value="{$PU_CFG.speed_target_pct}">
          <small>{'Uploaders can enter their connection speed; the plan aims at this share of it and paces itself so the link stays usable.'|translate}</small>
        </label>
        <label>{'Purge abandoned uploads after'|translate} (h)
          <input type="number" name="stale_hours" min="1" max="720" value="{$PU_CFG.stale_hours}">
        </label>
        <label class="pu-check"><input type="checkbox" name="replace_web_form" value="1" {if $PU_CFG.replace_web_form}checked{/if}>
          {'Replace the Web Form uploader'|translate}
          <small>{'Hides the core Web Form tab and makes Photos › Add (and the "add photos" buttons on albums) open Parallel Upload. Untick to restore the Web Form.'|translate}</small>
        </label>
        <label class="pu-check"><input type="checkbox" name="verify_sha256" value="1" {if $PU_CFG.verify_sha256}checked{/if}>
          {'Verify every chunk with SHA-256'|translate}
          <small>{'Browser-side hashing works on HTTPS only (it is skipped automatically on plain-HTTP LAN access).'|translate}</small>
        </label>
      </div>

      <table class="pu-ini">
        <tr><th>upload_max_filesize</th><td>{$PU_INI.upload_max_filesize}</td>
            <th>post_max_size</th><td>{$PU_INI.post_max_size}</td></tr>
        <tr><th>max_execution_time</th><td>{$PU_INI.max_execution_time}</td>
            <th>memory_limit</th><td>{$PU_INI.memory_limit}</td></tr>
        <tr><th>{'Effective chunk'|translate}</th><td colspan="3">{($PU_CHUNK/1000000)|string_format:"%.1f"} MB ({'tightest limit'|translate}: {$PU_SERVER_WHY})</td></tr>
        <tr><th>{'Buffer'|translate}</th><td colspan="3">{$PU_BUF_COUNT} {'partial uploads'|translate}, {($PU_BUF_BYTES/1048576)|string_format:"%.0f"} MB {'reserved'|translate}</td></tr>
      </table>

      <p class="pu-form-actions">
        <button type="submit" name="pu_save" class="buttonLike">{'Save Settings'|translate}</button>
        <button type="submit" name="pu_purge" class="buttonLike pu-secondary" onclick="return confirm('Delete all partial uploads?');">{'Purge buffer'|translate}</button>
      </p>
  </form>
</div>
