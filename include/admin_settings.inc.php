<?php
defined('PU_PATH') or die('Hacking attempt!');

// Settings change how every upload behaves and expose server details:
// webmasters only. Other administrators keep using the uploader.
if (!is_webmaster())
{
  redirect(PU_UPLOAD_URL);
}
check_status(ACCESS_WEBMASTER);

if (isset($_POST['pu_save']))
{
  check_pwg_token();
  pu_save_config(array(
    'chunk_size_mb'    => max(1, min(95, (float)$_POST['chunk_size_mb'])),
    'concurrency'      => max(1, min(16, (int)$_POST['concurrency'])),
    'max_retries'      => max(0, min(20, (int)$_POST['max_retries'])),
    'verify_sha256'    => !empty($_POST['verify_sha256']),
    'stale_hours'      => max(1, min(720, (int)$_POST['stale_hours'])),
    'replace_web_form' => !empty($_POST['replace_web_form']),
    'speed_target_pct' => max(10, min(100, (int)$_POST['speed_target_pct'])),
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
list($server_max, $server_max_why) = pu_server_max_chunk();
list($buf_count, $buf_bytes) = pu_buffer_usage();

$warnings = pu_limit_warnings($cfg);
$fpm = pu_server_workers();
if ($fpm['limit'] !== null && $fpm['limit'] < pu_fpm_needed($cfg))
{
  $where = $fpm['source'] && strpos($fpm['source'], '/') === 0 ? preg_replace('/:\d+$/', '', $fpm['source']) : 'its configuration';
  $warnings[] = sprintf(
    l10n('%s allows only %d PHP requests at once (%s), but %d parallel connections need about %d. Uploads will queue and other admin pages will stall. Raise %s to %d or more in %s and restart it, or lower Max parallel connections to %d.'),
    $fpm['label'], $fpm['limit'], $fpm['setting'], $cfg['concurrency'], pu_fpm_needed($cfg),
    $fpm['setting'], max(10, pu_fpm_needed($cfg)), $where, max(1, $fpm['limit'] - 2)
  );
}

$template->assign(array(
  'PU_CFG'          => $cfg,
  'PU_CHUNK'        => pu_effective_chunk_size(),
  'PU_SERVER_MAX'   => $server_max,
  'PU_SERVER_WHY'   => $server_max_why,
  'PU_WARNINGS'     => $warnings,
  'PU_FPM'          => $fpm,
  'PU_FPM_NEEDED'   => pu_fpm_needed($cfg),
  'PU_TOKEN'        => get_pwg_token(),
  'PU_PATH'         => get_root_url().'plugins/'.PU_ID.'/',
  'PU_SETTINGS_URL' => PU_SETTINGS_URL,
  'PU_UPLOAD_URL'   => PU_UPLOAD_URL,
  'PU_BUF_COUNT'    => $buf_count,
  'PU_BUF_BYTES'    => $buf_bytes,
  'PU_INI'          => array(
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size'       => ini_get('post_max_size'),
    'max_execution_time'  => ini_get('max_execution_time'),
    'memory_limit'        => ini_get('memory_limit'),
  ),
  'ADMIN_PAGE_TITLE' => l10n('Parallel Upload'),
));

$template->set_filename('plugin_admin_content', realpath(PU_PATH.'template/settings.tpl'));
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
