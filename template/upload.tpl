{combine_css path=$PU_PATH|cat:'css/parallel_upload.css' order=100}
{* core album picker (same modal as Photos > Add > Web Form) *}
{include file='include/album_selector.inc.tpl'}
{combine_script id='pu.uploader' load='footer' require='albumSelector' path=$PU_PATH|cat:'js/parallel_upload.js'}
{footer_script}
const pu_album_names = {$PU_ALBUM_NAMES|json_encode};
const str_drop_album_ab = '{'Drop into album'|@translate|escape:javascript}';
{/footer_script}

<div id="pu-app" class="pu{if $themeconf.colorscheme == 'dark'} pu-dark{/if}"
  data-token="{$PU_TOKEN}"
  data-chunk="{$PU_CHUNK}"
  data-concurrency="{$PU_MAX_CONC}"
  data-mbps="{$PU_USER_MBPS}"
  data-target-pct="{$PU_CFG.speed_target_pct}"
  data-can-configure="{if $PU_CAN_CONFIGURE}1{else}0{/if}"
  data-settings-url="{$PU_SETTINGS_URL}"
  data-retries="{$PU_CFG.max_retries}"
  data-sha="{if $PU_CFG.verify_sha256}1{else}0{/if}"
  data-exts="{$PU_EXTS}"
  data-maxfile="{$PU_MAX_FILE_MB}"
  data-batch-url="admin.php?page=photos_add&amp;section=direct&amp;batch="
  data-str-leave="{'Upload in progress'|translate|escape:html}">

  {foreach from=$PU_WARNINGS item=w}
    <div class="pu-warn"><span class="icon-attention"></span> {$w}</div>
  {/foreach}

  {* ------------------------------------------------------------ no album yet: same welcome as the core Web Form *}
  <div class="pu-welcome" id="pu-welcome"{if $PU_NB_ALBUMS > 0} hidden{/if}>
    <div class="addAlbumEmptyTitle">{'Welcome!'|translate}</div>
    <p class="addAlbumEmptyInfos">{'Piwigo requires an album to add photos.'|translate}</p>
    <a class="buttonLike" id="btnFirstAlbum">{'Create a first album'|translate}</a>
  </div>

  <div id="pu-main"{if $PU_NB_ALBUMS == 0} hidden{/if}>
  {* ------------------------------------------------------------ destination *}
  <section class="pu-card pu-setup">
    <div class="pu-field">
      <label>{'Drop into album'|translate}</label>
      <input type="hidden" id="pu-album" value="{$PU_ALBUM_ID}">
      <div class="selectedAlbum pu-selected-album" id="selectedAlbum"{if !$PU_ALBUM_ID} hidden{/if}>
        <span class="icon-sitemap" id="selectedAlbumName">{$PU_ALBUM_NAME}</span>
        <a class="icon-pencil" id="selectedAlbumEdit" title="{'Change album'|translate}"></a>
      </div>
      <div class="selectAlbumSelector pu-select-album" id="addPhotosAS"{if $PU_ALBUM_ID} hidden{/if}>
        <p class="head-button-1 icon-folder-open" id="btnPhotosAS">{"Select or create an album"|translate}</p>
      </div>
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
      <label for="pu-mbps">{'Your upload speed'|translate}</label>
      <div class="pu-speed"><input type="number" id="pu-mbps" min="1" max="100000" step="any" placeholder="{'auto'|translate}"> <span>Mbps</span></div>
      <div class="pu-plan-text" id="pu-plan-text"></div>
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

  </div>{* pu-main *}

  {* ------------------------------------------------------------ first album modal (core markup) *}
  <div class="bg-modal" id="addFirstAlbum">
    <div class="new-album-modal-content">
      <a class="icon-cancel close-modal" id="closeFirstAlbum"></a>
      <div class="AddIconContainer"><span class="AddIcon icon-blue icon-add-album"></span></div>
      <div class="AddIconTitle"><span>{'Create your first album'|translate}</span></div>
      <div class="AddAlbumInputContainer">
        <label class="user-property-label AddAlbumLabelUsername">{'Album name'|translate}
          <input class="user-property-input" id="inputFirstAlbum">
        </label>
      </div>
      <a class="buttonLike icon-plus" id="btnAddFirstAlbum">{'Create and select'|translate}</a>
    </div>
  </div>
</div>
