<?php
defined('PU_PATH') or die('Hacking attempt!');

include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
include_once(PHPWG_ROOT_PATH.'admin/include/tabsheet.class.php');
check_status(ACCESS_ADMINISTRATOR);

load_language('plugin.lang', PU_PATH);

// ---------------------------------------------------------------- settings
if (isset($_POST['pu_save']))
{
  check_pwg_token();
  $d = pu_default_config();
  pu_save_config(array(
    'chunk_size_mb' => max(1, min(95, (float)$_POST['chunk_size_mb'])),
    'concurrency'   => max(1, min(16, (int)$_POST['concurrency'])),
    'max_retries'   => max(0, min(20, (int)$_POST['max_retries'])),
    'verify_sha256' => !empty($_POST['verify_sha256']),
    'stale_hours'   => max(1, min(720, (int)$_POST['stale_hours'])),
    'replace_web_form' => !empty($_POST['replace_web_form']),
  ));
  $page['infos'][] = l10n('Settings saved');
}

if (isset($_POST['pu_purge']))
{
  check_pwg_token();
  $before = pu_buffer_usage();
  pu_rrmdir(pu_buffer_dir());
  $page['infos'][] = sprintf(l10n('%d partial uploads removed'), $before[0]);
}

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

// ---------------------------------------------------------------- limits / diagnostics
list($server_max, $server_max_why) = pu_server_max_chunk();
$chunk = pu_effective_chunk_size();
list($buf_count, $buf_bytes) = pu_buffer_usage();

$warnings = array();
if ($chunk < (int)round($cfg['chunk_size_mb'] * 1000000))
{
  $warnings[] = sprintf(
    l10n('Chunks are capped at %s by %s (you asked for %s MB). Raise upload_max_filesize and post_max_size to about %d M in PHP, and client_max_body_size in nginx, to use bigger chunks.'),
    round($chunk / 1000000, 1).' MB', $server_max_why, $cfg['chunk_size_mb'], ceil($cfg['chunk_size_mb']) + 4
  );
}

$template->assign(array(
  'PU_CFG'          => $cfg,
  'PU_CHUNK'        => $chunk,
  'PU_SERVER_MAX'   => $server_max,
  'PU_SERVER_WHY'   => $server_max_why,
  'PU_WARNINGS'     => $warnings,
  'PU_EXTS'         => implode(',', pu_allowed_exts()),
  'PU_MAX_FILE_MB'  => (int)$conf['upload_form_max_file_size'],
  'PU_LEVELS'       => get_privacy_level_options(),
  'PU_TOKEN'        => get_pwg_token(),
  'PU_PATH'         => get_root_url().'plugins/'.PU_ID.'/',
  'PU_ADMIN'        => PU_ADMIN,
  'PU_BUF_COUNT'    => $buf_count,
  'PU_BUF_BYTES'    => $buf_bytes,
  'PU_INI'          => array(
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size'       => ini_get('post_max_size'),
    'max_execution_time'  => ini_get('max_execution_time'),
    'memory_limit'        => ini_get('memory_limit'),
  ),
  'ADMIN_PAGE_TITLE' => l10n('Upload Photos'),
));

$template->set_filename('plugin_admin_content', realpath(PU_PATH.'template/admin.tpl'));
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
