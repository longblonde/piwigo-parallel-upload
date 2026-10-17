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
      <div class="pu-grid">
        <label>{'Chunk size'|translate} (MB)
          <input type="number" name="chunk_size_mb" min="1" max="95" step="1" value="{$PU_CFG.chunk_size_mb}">
          <small>{'Each request carries one chunk. Cloudflare rejects request bodies over 100 MB; 95 is the ceiling.'|translate}</small>
        </label>
        <label>{'Parallel connections'|translate}
          <input type="number" name="concurrency" min="1" max="16" value="{$PU_CFG.concurrency}">
          <small>{'Chunks in flight at once, across all files. 3–6 usually saturates a home uplink.'|translate}</small>
        </label>
        <label>{'Retries per chunk'|translate}
          <input type="number" name="max_retries" min="0" max="20" value="{$PU_CFG.max_retries}">
          <small>{'Back-off doubles each time (1 s, 2 s, 4 s…).'|translate}</small>
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
