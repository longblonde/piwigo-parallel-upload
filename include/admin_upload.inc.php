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
$query = 'SELECT id, name, uppercats, global_rank FROM '.CATEGORIES_TABLE.';';
$selected = array();
if (isset($_GET['album']) && is_numeric($_GET['album'])) $selected[] = (int)$_GET['album'];
display_select_cat_wrapper($query, $selected, 'category_options');

$template->assign(array(
  'PU_CFG'           => $cfg,
  'PU_CHUNK'         => pu_effective_chunk_size(),
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
