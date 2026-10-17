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
  $cur = pu_get_config();
  // A field missing from the POST (form opened before an update added it, or
  // trimmed by a proxy) keeps its current value instead of falling to a minimum.
  $num = function ($key, $min, $max, $float = false) use ($cur) {
    if (!isset($_POST[$key]) || $_POST[$key] === '' || !is_numeric($_POST[$key])) return $cur[$key];
    $v = $float ? (float)$_POST[$key] : (int)$_POST[$key];
    return max($min, min($max, $v));
  };
  // checkboxes send nothing when unticked; only trust that on a form that knows about them
  $box = function ($key) use ($cur) {
    return isset($_POST['pu_form']) ? !empty($_POST[$key]) : (isset($_POST[$key]) ? true : $cur[$key]);
  };
  $proxy = $num('proxy_limit_mb', 10, 5000);
  pu_save_config(array(
    'proxy_limit_mb'   => $proxy,
    'chunk_size_mb'    => min(floor($proxy * 0.95), $num('chunk_size_mb', 1, 5000, true)),
    'concurrency'      => $num('concurrency', 1, 16),
    'max_retries'      => $num('max_retries', 0, 20),
    'stale_hours'      => $num('stale_hours', 1, 720),
    'speed_target_pct' => $num('speed_target_pct', 10, 100),
    'verify_sha256'    => $box('verify_sha256'),
    'replace_web_form' => $box('replace_web_form'),
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

// Helper text for "Max parallel connections", matched to what this server is.
//   ok      : the server allows at least the current setting -> not a bottleneck, say so calmly
//   tight   : the server allows fewer than the current setting -> it is the bottleneck
//   unknown : couldn't tell -> list the usual limits
$conc_max_server = $fpm['limit'] !== null ? max(1, $fpm['limit'] - 2) : null;
$conc_state = $fpm['limit'] === null ? 'unknown' : ($conc_max_server >= (int)$cfg['concurrency'] ? 'ok' : 'tight');

$template->assign(array(
  'PU_CONC'         => array(
    'state'      => $conc_state,
    'label'      => $fpm['label'],
    'setting'    => $fpm['setting'],
    'limit'      => $fpm['limit'],
    'max'        => $conc_max_server,
    'type'       => $fpm['type'],
    'input_max'  => $conc_max_server !== null ? max(1, min(16, $conc_max_server)) : 16,
  ),
  'PU_CFG'          => $cfg,
  'PU_CHUNK'        => pu_effective_chunk_size(),
  'PU_SERVER_MAX'   => $server_max,
  'PU_SERVER_WHY'   => $server_max_why,
  'PU_PROXY_MAX_MB' => floor(pu_proxy_limit_mb($cfg) * 0.95),
  'PU_WARNINGS'     => $warnings,
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
