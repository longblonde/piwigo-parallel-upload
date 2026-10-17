<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/** Hard ceiling for one chunk's file payload. Cloudflare's limit is 100 MB per
 *  request body (Free/Pro); we stay well clear to leave room for multipart overhead. */
define('PU_HARD_MAX_CHUNK', 95 * 1000 * 1000);

function pu_default_config()
{
  return array(
    'chunk_size_mb'   => 32,   // MB (10^6 bytes) per chunk
    'concurrency'     => 4,    // simultaneous chunk requests
    'max_retries'     => 6,    // per chunk, exponential back-off
    'verify_sha256'   => true, // per-chunk integrity check (needs HTTPS in the browser)
    'stale_hours'     => 24,   // abandoned partial uploads are purged after this
    'replace_web_form'=> false,// hide core "Web Form" tab, Photos > Add opens Parallel Upload
    'speed_target_pct'=> 80,   // a user's stated upload speed is targeted at this percentage
  );
}

function pu_get_config()
{
  global $conf;
  $cfg = array();
  if (!empty($conf['parallel_upload']))
  {
    $cfg = is_array($conf['parallel_upload'])
      ? $conf['parallel_upload']
      : json_decode($conf['parallel_upload'], true);
  }
  return array_merge(pu_default_config(), is_array($cfg) ? $cfg : array());
}

function pu_save_config($cfg)
{
  $cfg = array_intersect_key(array_merge(pu_default_config(), $cfg), pu_default_config());
  conf_update_param('parallel_upload', json_encode($cfg), true);
}

/**
 * Largest chunk (bytes) this server can actually accept, taking PHP limits into account.
 * Returns array(max_bytes, reason_string_of_tightest_limit)
 */
function pu_server_max_chunk()
{
  $limits = array('Proxy cap (95 MB)' => PU_HARD_MAX_CHUNK);

  $umf = pu_ini_bytes('upload_max_filesize');
  if ($umf > 0) $limits['PHP upload_max_filesize'] = $umf - 1024;

  $pms = pu_ini_bytes('post_max_size');
  if ($pms > 0) $limits['PHP post_max_size'] = $pms - 64 * 1024; // multipart headers + form fields

  asort($limits);
  reset($limits);
  return array((int)current($limits), key($limits));
}

function pu_ini_bytes($key)
{
  $v = trim((string)ini_get($key));
  if ($v === '' ) return 0;
  $n = (float)$v;
  switch (strtolower(substr($v, -1)))
  {
    case 'g': $n *= 1024;
    case 'm': $n *= 1024;
    case 'k': $n *= 1024;
  }
  return (int)$n; // 0 means unlimited for post_max_size
}

/** Hints shown when PHP limits force smaller chunks than configured. */
function pu_limit_warnings($cfg)
{
  list($max, $why) = pu_server_max_chunk();
  $chunk = pu_effective_chunk_size();
  if ($chunk >= (int)round($cfg['chunk_size_mb'] * 1000000)) return array();
  return array(sprintf(
    l10n('Chunks are capped at %s by %s (you asked for %s MB). Raise upload_max_filesize and post_max_size to about %d M in PHP, and client_max_body_size in nginx, to use bigger chunks.'),
    round($chunk / 1000000, 1).' MB', $why, $cfg['chunk_size_mb'], ceil($cfg['chunk_size_mb']) + 4
  ));
}

/** Chunk size the browser should use, in bytes. */
function pu_effective_chunk_size()
{
  $cfg = pu_get_config();
  list($max) = pu_server_max_chunk();
  $want = (int)round(max(1, min(95, (float)$cfg['chunk_size_mb'])) * 1000 * 1000);
  return max(256 * 1024, min($want, $max));
}

function pu_buffer_dir()
{
  global $conf;
  return PHPWG_ROOT_PATH.$conf['upload_dir'].'/buffer/parallel';
}

function pu_allowed_exts()
{
  global $conf;
  return array_values(array_unique(array_map(
    'strtolower',
    $conf['upload_form_all_types'] ? $conf['file_ext'] : $conf['picture_ext']
  )));
}

function pu_rrmdir($dir)
{
  if (!is_dir($dir)) return;
  foreach (scandir($dir) as $f)
  {
    if ($f == '.' || $f == '..') continue;
    $p = $dir.'/'.$f;
    is_dir($p) && !is_link($p) ? pu_rrmdir($p) : @unlink($p);
  }
  @rmdir($dir);
}

/** Remove partial / finished upload folders that have not been touched for stale_hours. */
function pu_cleanup_stale()
{
  $cfg = pu_get_config();
  $base = pu_buffer_dir();
  if (!is_dir($base)) return 0;
  $limit = time() - max(1, (int)$cfg['stale_hours']) * 3600;
  $n = 0;
  foreach (glob($base.'/pu_*', GLOB_ONLYDIR) ?: array() as $dir)
  {
    $last = @filemtime($dir);
    foreach (array('meta.json', 'data.part', 'result.json') as $f)
    {
      if (file_exists($dir.'/'.$f)) $last = max($last, filemtime($dir.'/'.$f));
    }
    if ($last < $limit)
    {
      pu_rrmdir($dir);
      $n++;
    }
  }
  return $n;
}

/** Bytes and count of partial uploads sitting in the buffer (for the admin page). */
function pu_buffer_usage()
{
  $base = pu_buffer_dir();
  $bytes = 0; $count = 0;
  foreach (glob($base.'/pu_*', GLOB_ONLYDIR) ?: array() as $dir)
  {
    // finished uploads keep only a small result file until purged; don't count them
    if (!file_exists($dir.'/data.part')) continue;
    $count++;
    $bytes += filesize($dir.'/data.part');
  }
  return array($count, $bytes);
}
