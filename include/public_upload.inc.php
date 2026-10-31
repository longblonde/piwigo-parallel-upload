<?php
defined('PU_PATH') or die('Hacking attempt!');

/*
 * index.php?/parallel_upload : Parallel Upload for registered users whom the
 * Community plugin lets upload. Rights, quotas and moderation are Community's;
 * the upload methods check all of it again on the server.
 */

global $template, $conf, $user, $page;

include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

$kind = pu_access_kind();

if (is_a_guest())
{
  // guests never upload here; send them to log in
  redirect(get_root_url().'identification.php?redirect='.rawurlencode($_SERVER['REQUEST_URI']));
}
if ($kind === 'admin')
{
  $u = PU_UPLOAD_URL;
  if (isset($_GET['category_id']) && ctype_digit((string)$_GET['category_id'])) $u .= '&album='.$_GET['category_id'];
  redirect($u);
}
if ($kind !== 'community')
{
  redirect(make_index_url());
}

load_language('plugin.lang', PU_PATH);

$cfg   = pu_get_config();
$perms = pu_community_perms();

// ---------------------------------------------------------------- albums this user may upload to / create in
$album_names = function ($ids) {
  if (count($ids) == 0) return array();
  $cats = query2array('SELECT id, name, uppercats, global_rank FROM '.CATEGORIES_TABLE.' WHERE id IN ('.implode(',', array_map('intval', $ids)).');');
  usort($cats, 'global_rank_compare');
  $out = array();
  foreach ($cats as $c) $out[$c['id']] = strip_tags(get_cat_display_name_cache($c['uppercats'], null));
  return $out;
};
$upload_options = $album_names($perms['upload_categories']);
$parent_options = $album_names($perms['create_categories']);

$album_id = '';
foreach (array('category_id', 'album') as $k)
{
  if (isset($_GET[$k]) && ctype_digit((string)$_GET[$k]) && isset($upload_options[(int)$_GET[$k]])) { $album_id = (int)$_GET[$k]; break; }
}

// ---------------------------------------------------------------- quotas and moderation, as Community applies them
$quota = array();
$usage = community_get_user_limits($user['id']);
if ((int)$perms['nb_photos'] > 0)
{
  $quota[] = sprintf(l10n('%d of %d photos left'), max(0, $perms['nb_photos'] - $usage['nb_photos']), $perms['nb_photos']);
}
if ((int)$perms['storage'] > 0)
{
  $quota[] = sprintf(l10n('%d of %d MB left'), max(0, $perms['storage'] - $usage['storage']), $perms['storage']);
}

$unmoderated = 0;
if (count($perms['permission_ids']) > 0)
{
  list($unmoderated) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*) FROM '.COMMUNITY_PERMISSIONS_TABLE.'
  WHERE id IN ('.implode(',', array_map('intval', $perms['permission_ids'])).') AND moderated = \'false\';'));
}
$moderated_note = $unmoderated == 0
  ? l10n('An administrator reviews your photos before they appear in the gallery.')
  : '';

// ---------------------------------------------------------------- transfer plan for Community users
$max_conc = max(1, min((int)$cfg['community_concurrency'], (int)$cfg['concurrency']));
$workers = pu_server_workers();
if ($workers['limit'] !== null) $max_conc = max(1, min($max_conc, $workers['limit'] - 2));

$max_file = pu_community_max_file($cfg);

$template->set_filename('pu_public', realpath(PU_PATH.'template/upload.tpl'));
$template->assign(array(
  'PU_MODE'          => 'community',
  'PU_TABS'          => pu_community_mode($cfg) == 'tab' ? pu_public_tabs('parallel') : array(),
  'PU_MAX_OPEN'      => max(1, (int)$cfg['community_max_open']),
  'PU_MAX_CONC'      => $max_conc,
  'PU_CFG'           => $cfg,
  'PU_ALBUM_OPTIONS' => $upload_options,
  'PU_ALBUM_ID'      => $album_id,
  'PU_ALBUM_NAMES'   => (object)array(),
  'PU_NB_ALBUMS'     => count($upload_options),
  'PU_CAN_CREATE'    => !empty($perms['create_whole_gallery']) || count($parent_options) > 0,
  'PU_CREATE_TOP'    => !empty($perms['create_whole_gallery']),
  'PU_PARENT_OPTIONS'=> $parent_options,
  'PU_QUOTA'         => count($quota) ? implode(' · ', $quota) : '',
  'PU_MODERATED_NOTE'=> $moderated_note,
  'PU_CHUNK'         => pu_effective_chunk_size(),
  'PU_USER_MBPS'     => (float)userprefs_get_param('pu_upload_mbps', 0),
  'PU_WARNINGS'      => array(),
  'PU_CAN_CONFIGURE' => false,
  'PU_SETTINGS_URL'  => '',
  'PU_EXTS'          => implode(',', pu_allowed_exts()),
  'PU_MAX_FILE_MB'   => $max_file > 0 ? (int)round($max_file / 1048576) : 0,
  'PU_LEVELS'        => array(),
  'PU_TOKEN'         => get_pwg_token(),
  'PU_PATH'          => get_root_url().'plugins/'.PU_ID.'/',
));

$template->assign_var_from_handle('PLUGIN_INDEX_CONTENT_BEGIN', 'pu_public');
$template->clear_assign(array('U_MODE_POSTED', 'U_MODE_CREATED'));
$template->assign('TITLE', '<a href="'.get_gallery_home_url().'">'.l10n('Home').'</a>'.$conf['level_separator'].l10n('Upload Photos'));
$page['body_id'] = 'theParallelUploadPage';
