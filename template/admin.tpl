{combine_css path=$PU_PATH|cat:'css/parallel_upload.css' order=100}
{combine_script id='pu.uploader' load='footer' path=$PU_PATH|cat:'js/parallel_upload.js'}

<div id="pu-app" class="pu{if $themeconf.colorscheme == 'dark'} pu-dark{/if}"
  data-token="{$PU_TOKEN}"
  data-chunk="{$PU_CHUNK}"
  data-concurrency="{$PU_CFG.concurrency}"
  data-retries="{$PU_CFG.max_retries}"
  data-sha="{if $PU_CFG.verify_sha256}1{else}0{/if}"
  data-exts="{$PU_EXTS}"
  data-maxfile="{$PU_MAX_FILE_MB}"
  data-batch-url="admin.php?page=photos_add&amp;section=direct&amp;batch="
  data-str-leave="{'Upload in progress'|translate|escape:html}">

  {foreach from=$PU_WARNINGS item=w}
    <div class="pu-warn"><span class="icon-attention"></span> {$w}</div>
  {/foreach}

  {* ------------------------------------------------------------ destination *}
  <section class="pu-card pu-setup">
    <div class="pu-field">
      <label for="pu-album">{'Album'|translate}</label>
      <select id="pu-album">
        <option value="">— {'Select an album'|translate} —</option>
        {html_options options=$category_options selected=$category_options_selected}
      </select>
    </div>
    <div class="pu-field pu-field-sm">
      <label for="pu-level">{'Who can see these photos?'|translate}</label>
      <select id="pu-level">{html_options options=$PU_LEVELS selected=0}</select>
    </div>
    <div class="pu-field pu-field-sm">
      <label class="pu-check"><input type="checkbox" id="pu-update"> {'Update mode'|translate}</label>
      <small>{'Replace photos with the same file name in this album'|translate}</small>
    </div>
    <div class="pu-field pu-field-sm pu-plan">
      <label>{'Transfer plan'|translate}</label>
      <div><b id="pu-plan-chunk"></b> {'chunks'|translate} · <b>{$PU_CFG.concurrency}</b> {'parallel'|translate}</div>
      <small><a href="#pu-settings" class="pu-toggle-settings">{'Settings'|translate}</a></small>
    </div>
  </section>

  {* ------------------------------------------------------------ drop zone *}
  <section class="pu-drop" id="pu-drop" tabindex="0">
    <input type="file" id="pu-input" multiple hidden>
    <span class="icon-upload pu-drop-ico"></span>
    <div class="pu-drop-txt">
      <div class="pu-drop-line"><b>{'Drop files here'|translate}</b> <span>{'or'|translate}</span> <button type="button" class="buttonLike" id="pu-browse">{'Select files'|translate}</button></div>
      <small>{'Allowed'|translate}: <span id="pu-exts"></span> · max {$PU_MAX_FILE_MB} MB {'per file'|translate}</small>
    </div>
  </section>

  {* ------------------------------------------------------------ dashboard *}
  <section class="pu-card pu-dash" id="pu-dash" hidden>
    <div class="pu-dash-head">
      <div class="pu-eta">
        <span class="pu-eta-label" id="pu-state-label">{'Ready'|translate}</span>
        <span class="pu-eta-val" id="pu-eta">—</span>
        <span class="pu-eta-sub" id="pu-eta-sub">{'time remaining'|translate}</span>
      </div>
      <div class="pu-actions">
        <button type="button" class="buttonLike" id="pu-start" disabled><span class="icon-upload"></span> {'Start upload'|translate}</button>
        <button type="button" class="buttonLike pu-secondary" id="pu-pause" hidden><span class="icon-pause"></span> {'Pause'|translate}</button>
        <button type="button" class="buttonLike pu-secondary" id="pu-cancel" hidden><span class="icon-cancel"></span> {'Cancel'|translate}</button>
        <button type="button" class="buttonLike pu-secondary" id="pu-clear">{'Clear list'|translate}</button>
      </div>
    </div>

    <div class="pu-total">
      <div class="pu-total-bar"><div class="pu-total-fill" id="pu-total-fill"></div></div>
      <div class="pu-total-pct" id="pu-total-pct">0%</div>
    </div>

    <dl class="pu-stats">
      <div><dt>{'Transferred'|translate}</dt><dd id="pu-s-bytes">—</dd></div>
      <div><dt>{'Speed'|translate}</dt><dd id="pu-s-speed">—</dd></div>
      <div><dt>{'Elapsed'|translate}</dt><dd id="pu-s-elapsed">—</dd></div>
      <div><dt>{'Connections'|translate}</dt><dd id="pu-s-conn">—</dd></div>
      <div><dt>{'Chunks'|translate}</dt><dd id="pu-s-chunks">—</dd></div>
      <div><dt>{'Files'|translate}</dt><dd id="pu-s-files">—</dd></div>
    </dl>

    <div class="pu-legend">
      <span><i class="pu-sw s-queued"></i>{'queued'|translate}</span>
      <span><i class="pu-sw s-hashing"></i>{'checksumming'|translate}</span>
      <span><i class="pu-sw s-sending"></i>{'sending'|translate}</span>
      <span><i class="pu-sw s-done"></i>{'stored'|translate}</span>
      <span><i class="pu-sw s-retry"></i>{'retrying'|translate}</span>
      <span><i class="pu-sw s-error"></i>{'failed'|translate}</span>
    </div>

    <ol class="pu-files" id="pu-files"></ol>
  </section>

  {* ------------------------------------------------------------ results *}
  <section class="pu-card pu-results" id="pu-results" hidden>
    <h3 id="pu-results-title"></h3>
    <div class="pu-thumbs" id="pu-thumbs"></div>
    <p><a class="buttonLike" id="pu-batch" href="#">{'Manage this set of photos'|translate}</a></p>
  </section>

  {* ------------------------------------------------------------ settings *}
  <details class="pu-card pu-settings" id="pu-settings">
    <summary><span class="icon-cog"></span> {'Parallel Upload settings'|translate}</summary>
    <form method="post" action="{$PU_ADMIN}">
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
  </details>
</div>
