<?php
/*
Plugin Name: Parallel Upload
Version: 1.5.1
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
