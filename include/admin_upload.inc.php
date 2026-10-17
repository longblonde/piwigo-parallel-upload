<?php
defined('PU_PATH') or die('Hacking attempt!');

include_once(PHPWG_ROOT_PATH.'admin/include/tabsheet.class.php');

$cfg = pu_get_config();

// ---------------------------------------------------------------- tabs (shares Photos > Add tabsheet)
if (!defined('PHOTOS_ADD_BASE_URL'))
{
  define('PHOTOS_ADD_BASE_URL', get_root_url().'admin.php?page=photos_add');
}
$tabsheet = new tabsheet();
$tabsheet->set_id('photos_add');
$tabsheet->select('parallel');   // tab list comes from pu_add_tab()
$tabsheet->assign();

// highlight Photos (not Plugins) in the admin menu
$page['active_menu'] = get_active_menu('photos_add');

// ---------------------------------------------------------------- albums, levels
// full names ("Family Trips / Summer 2026") so the picker can show the
// remembered or preselected album without a round-trip
$album_names = array();
$cats = query2array('SELECT id, name, uppercats, global_rank FROM '.CATEGORIES_TABLE.';');
usort($cats, 'global_rank_compare');
foreach ($cats as $c)
{
  $album_names[$c['id']] = strip_tags(get_cat_display_name_cache($c['uppercats'], null));
}
$album_id = '';
if (isset($_GET['album']) && isset($album_names[(int)$_GET['album']])) $album_id = (int)$_GET['album'];

$template->assign(array(
  'PU_CFG'           => $cfg,
  'PU_ALBUM_NAMES'   => (object)$album_names,
  'PU_ALBUM_ID'      => $album_id,
  'PU_ALBUM_NAME'    => $album_id ? $album_names[$album_id] : '',
  'PU_NB_ALBUMS'     => count($album_names),
  'PU_CHUNK'         => pu_effective_chunk_size(),
  'PU_USER_MBPS'     => (float)userprefs_get_param('pu_upload_mbps', 0),
  // server-tuning hints are only useful to whoever can change the settings
  'PU_WARNINGS'      => is_webmaster() ? pu_limit_warnings($cfg) : array(),
  'PU_CAN_CONFIGURE' => is_webmaster(),
  'PU_SETTINGS_URL'  => PU_SETTINGS_URL,
  'PU_EXTS'          => implode(',', pu_allowed_exts()),
  'PU_MAX_FILE_MB'   => (int)$conf['upload_form_max_file_size'],
  'PU_LEVELS'        => get_privacy_level_options(),
  'PU_TOKEN'         => get_pwg_token(),
  'PU_PATH'          => get_root_url().'plugins/'.PU_ID.'/',
  'ADMIN_PAGE_TITLE' => l10n('Upload Photos'),
));

$template->set_filename('plugin_admin_content', realpath(PU_PATH.'template/upload.tpl'));
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
