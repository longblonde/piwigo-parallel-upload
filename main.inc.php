<?php
/*
Plugin Name: Parallel Upload
Version: 1.6.0
Description: Upload large files in parallel chunks sized to fit under a reverse-proxy request cap (Cloudflare Tunnel: 100 MB), with a live per-file / per-chunk progress dashboard and time remaining.
Plugin URI: https://gray-think.com
Author: Gray-Think (Daniel Wald)
Has Settings: webmaster
*/

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

if (basename(dirname(__FILE__)) != 'ParallelUpload')
{
  add_event_handler('init', function () {
    global $page;
    $page['errors'][] = 'Parallel Upload: the plugin folder must be named "ParallelUpload".';
  });
  return;
}

define('PU_ID',      'ParallelUpload');
define('PU_PATH',    PHPWG_PLUGINS_PATH.PU_ID.'/');
define('PU_SETTINGS_URL', get_root_url().'admin.php?page=plugin-'.PU_ID);           // webmaster only
define('PU_UPLOAD_URL',   get_root_url().'admin.php?page=plugin-'.PU_ID.'-upload');  // any administrator

include_once(PU_PATH.'include/functions.inc.php');

// Web-service methods (pu.upload.*)
add_event_handler('ws_add_methods', 'pu_ws_add_methods', EVENT_HANDLER_PRIORITY_NEUTRAL, PU_PATH.'include/ws_functions.inc.php');

// "Parallel Upload" tab next to Web Form / Applications / FTP on Photos > Add
add_event_handler('tabsheet_before_select', 'pu_add_tab', EVENT_HANDLER_PRIORITY_NEUTRAL + 10);

function pu_add_tab($sheets, $id)
{
  if ($id == 'photos_add')
  {
    load_language('plugin.lang', PU_PATH);
    $tab = array(
      'caption' => '<span class="icon-upload"></span>'.l10n('Parallel Upload'),
      'url' => PU_UPLOAD_URL,
    );
    $cfg = pu_get_config();
    if (!empty($cfg['replace_web_form']))
    {
      // take the Web Form's place: first tab, Web Form hidden
      unset($sheets['direct']);
      $sheets = array('parallel' => $tab) + $sheets;
    }
    else
    {
      $sheets['parallel'] = $tab;
    }
  }
  return $sheets;
}

// With "replace Web Form" on, Photos > Add (and every core link to it) opens this plugin.
// Core's direct page is still used for things the plugin doesn't do:
// the post-upload batch view (&batch=) and adding formats to an existing photo (&formats).
add_event_handler('loc_begin_admin_page', 'pu_redirect_web_form');

function pu_redirect_web_form()
{
  global $page;
  if (!isset($page['page']) || $page['page'] != 'photos_add') return;
  if (isset($_GET['section']) && $_GET['section'] != 'direct') return;
  if (isset($_GET['batch']) || isset($_GET['formats'])) return;

  $cfg = pu_get_config();
  if (empty($cfg['replace_web_form'])) return;

  $url = PU_UPLOAD_URL;
  if (isset($_GET['album']) && ctype_digit((string)$_GET['album']))
  {
    $url .= '&album='.$_GET['album'];
  }
  redirect($url);
}

// ---------------------------------------------------------------------------
// Community users: public uploader at index.php?/parallel_upload
// ---------------------------------------------------------------------------

function pu_public_url($category_id = null)
{
  $u = make_index_url(array('section' => 'parallel_upload'));
  if ($category_id) $u .= (strpos($u, '?') === false ? '?' : '&').'category_id='.(int)$category_id;
  return $u;
}

/** Tabs shown on Community's upload page and on ours ("tab" mode). */
function pu_public_tabs($active)
{
  load_language('plugin.lang', PU_PATH);
  $cat = (isset($_GET['category_id']) && ctype_digit((string)$_GET['category_id'])) ? (int)$_GET['category_id'] : null;
  $community_url = make_index_url(array('section' => 'add_photos')).($cat ? '&amp;category_id='.$cat : '');
  return array(
    array('label' => l10n('Upload Photos'), 'url' => $community_url, 'active' => $active == 'community'),
    array('label' => l10n('Parallel Upload'), 'url' => str_replace('&', '&amp;', pu_public_url($cat)), 'active' => $active == 'parallel'),
  );
}

add_event_handler('loc_end_section_init', 'pu_public_section_init');
function pu_public_section_init()
{
  global $tokens, $page;
  if (isset($tokens[0]) && $tokens[0] == 'parallel_upload')
  {
    $page['section'] = 'parallel_upload';
    $page['is_homepage'] = false;
  }
}

add_event_handler('loc_end_index', 'pu_public_index');
function pu_public_index()
{
  global $page;
  if (isset($page['section']) && $page['section'] == 'parallel_upload')
  {
    if (pu_community_mode() == 'off' && !is_admin()) redirect(make_index_url());
    include(PU_PATH.'include/public_upload.inc.php');
  }
}

// Community's own upload page: a tab to ours ("tab"), or a redirect to ours ("replace").
// Runs before Community renders the page (its handler has the default priority).
add_event_handler('loc_end_index', 'pu_community_page', EVENT_HANDLER_PRIORITY_NEUTRAL - 5);
function pu_community_page()
{
  global $page, $template;
  if (!isset($page['section']) || $page['section'] != 'add_photos') return;
  $mode = pu_community_mode();
  if ($mode == 'off') return;

  $cat = (isset($_GET['category_id']) && ctype_digit((string)$_GET['category_id'])) ? (int)$_GET['category_id'] : null;

  if ($mode == 'replace')
  {
    // everyone goes to Parallel Upload, which only serves registered users:
    // in this mode guests can no longer upload through Community either
    redirect(pu_public_url($cat));
  }

  if (pu_access_kind() !== 'community') return;   // guests/admins: Community's page unchanged
  $template->assign(array(
    'PU_TABS'     => pu_public_tabs('community'),
    'PU_CSS_PATH' => get_root_url().'plugins/'.PU_ID.'/css/parallel_upload.css',
  ));
  $template->set_prefilter('add_photos', 'pu_community_tabs_prefilter');
}

function pu_community_tabs_prefilter($source, $smarty)
{
  return '{combine_css path=$PU_CSS_PATH order=100}'
    .'{if !empty($PU_TABS)}<nav class="pu-tabs">{foreach from=$PU_TABS item=t}<a href="{$t.url}"{if $t.active} class="active" aria-current="page"{/if}>{$t.label}</a>{/foreach}</nav>{/if}'
    ."\n".$source;
}
