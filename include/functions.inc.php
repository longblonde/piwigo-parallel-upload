<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Largest chunk payload the reverse proxy will pass, in bytes: the proxy's
 * request-body limit minus 5% for multipart framing and form fields.
 * Cloudflare: 100 MB on Free/Pro, 200 MB Business, 500 MB Enterprise.
 */
function pu_proxy_max_chunk($cfg = null)
{
  if ($cfg === null) $cfg = pu_get_config();
  return (int)floor(pu_proxy_limit_mb($cfg) * 1000000 * 0.95);
}

function pu_proxy_limit_mb($cfg)
{
  return max(10, min(5000, (int)$cfg['proxy_limit_mb']));
}

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
    'proxy_limit_mb'  => 100,  // request-body limit of the proxy in front (Cloudflare Free/Pro: 100)
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
  $cfg = pu_get_config();
  $limits = array(sprintf('proxy limit %d MB, minus 5%%', pu_proxy_limit_mb($cfg)) => pu_proxy_max_chunk($cfg));

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
    l10n('Chunks are capped at %s by %s (you asked for %s MB). Raise upload_max_filesize and post_max_size to about %d M in PHP (and client_max_body_size in nginx), or re-run install.sh, which follows the proxy request limit.'),
    round($chunk / 1000000, 1).' MB', $why, $cfg['chunk_size_mb'], ceil($cfg['chunk_size_mb']) + 4
  ));
}

/** Chunk size the browser should use, in bytes. */
function pu_effective_chunk_size()
{
  $cfg = pu_get_config();
  list($max) = pu_server_max_chunk();
  $want = (int)round(max(1, (float)$cfg['chunk_size_mb']) * 1000 * 1000);
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

/**
 * What PHP-FPM allows for the pool serving this request.
 * Every chunk in flight occupies one worker, so pm.max_children has to be
 * comfortably above "Max parallel connections".
 *
 * @return array  sapi, pool, pm, max_children, source (file:line), running, conf, error
 */
function pu_fpm_pool_info()
{
  $info = array('sapi' => PHP_SAPI, 'pool' => null, 'pm' => null, 'max_children' => null,
                'source' => null, 'running' => null, 'conf' => null, 'error' => null);
  if (PHP_SAPI != 'fpm-fcgi')
  {
    $info['error'] = 'PHP is not running under PHP-FPM ('.PHP_SAPI.')';
    return $info;
  }

  // worker process title: "php-fpm: pool www"
  $self = @file_get_contents('/proc/self/cmdline');
  if ($self && preg_match('/php-fpm: pool (\S+)/', str_replace("\0", ' ', $self), $m))
  {
    $info['pool'] = $m[1];
  }

  // master process title: "php-fpm: master process (/etc/php/8.3/fpm/php-fpm.conf)"
  $stat = @file_get_contents('/proc/self/stat');
  if ($stat && preg_match('/^\d+ \(.*?\) \S (\d+)/', $stat, $m))
  {
    $mcmd = @file_get_contents('/proc/'.$m[1].'/cmdline');
    if ($mcmd && preg_match('/master process \(([^)]+)\)/', str_replace("\0", ' ', $mcmd), $mm))
    {
      $info['conf'] = $mm[1];
    }
  }
  if (!$info['conf'])
  {
    $ini = php_ini_loaded_file();
    $candidates = array();
    if ($ini) $candidates[] = dirname($ini).'/php-fpm.conf';
    $candidates = array_merge($candidates, array('/usr/local/etc/php-fpm.conf'),
      glob('/etc/php/*/fpm/php-fpm.conf') ?: array(), glob('/etc/php*/php-fpm.conf') ?: array());
    foreach ($candidates as $c) { if (@is_readable($c)) { $info['conf'] = $c; break; } }
  }
  if (!$info['conf'] || !@is_readable($info['conf']))
  {
    $info['error'] = 'Could not find or read php-fpm.conf'.(ini_get('open_basedir') ? ' (open_basedir is set)' : '');
    return $info;
  }

  // files in the order PHP-FPM reads them: main file, then each include glob
  $files = array($info['conf']);
  foreach (@file($info['conf']) ?: array() as $line)
  {
    if (preg_match('/^\s*include\s*=\s*(.+?)\s*$/', $line, $m))
    {
      $pattern = $m[1];
      if ($pattern[0] != '/') $pattern = dirname($info['conf']).'/'.$pattern;
      foreach (glob($pattern) ?: array() as $f) $files[] = $f;
    }
  }

  // later definitions override earlier ones, also across files with the same [pool]
  $section = null;
  foreach ($files as $f)
  {
    $lines = @file($f);
    if ($lines === false) continue;
    foreach ($lines as $n => $line)
    {
      $line = trim($line);
      if ($line === '' || $line[0] == ';' || $line[0] == '#') continue;
      if (preg_match('/^\[(.+)\]$/', $line, $m)) { $section = $m[1]; continue; }
      if ($info['pool'] !== null && $section !== $info['pool']) continue;
      if (preg_match('/^pm\s*=\s*(\w+)/', $line, $m)) $info['pm'] = $m[1];
      if (preg_match('/^pm\.max_children\s*=\s*(\d+)/', $line, $m))
      {
        $info['max_children'] = (int)$m[1];
        $info['source'] = $f.':'.($n + 1);
      }
    }
  }
  if ($info['max_children'] === null && !$info['error'])
  {
    $info['error'] = 'pm.max_children not found in '.implode(', ', $files);
  }

  // workers alive right now for this pool
  if ($info['pool'] !== null && @is_dir('/proc'))
  {
    $running = 0;
    foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: array() as $p)
    {
      $c = @file_get_contents($p.'/cmdline');
      if ($c && strpos($c, 'php-fpm: pool '.$info['pool']) === 0) $running++;
    }
    $info['running'] = $running;
  }
  return $info;
}

/**
 * Apache with PHP as a module (apache2handler): every request, chunk uploads
 * included, takes one Apache worker, capped by MaxRequestWorkers.
 */
function pu_apache_info()
{
  $info = array('mpm' => null, 'max_workers' => null, 'source' => null, 'running' => null, 'error' => null);

  if (function_exists('apache_get_modules'))
  {
    foreach (apache_get_modules() as $m)
    {
      if (preg_match('/^(?:mod_)?mpm_(\w+)/', $m, $mm) || preg_match('/^(prefork|worker|event)(?:\.c)?$/', $m, $mm)) $info['mpm'] = $mm[1];
    }
  }

  // Debian/Ubuntu layout first, then RHEL/Alpine
  $files = array();
  foreach (array('/etc/apache2', '/etc/httpd') as $base)
  {
    if (!@is_dir($base)) continue;
    foreach (array('apache2.conf', 'httpd.conf', 'conf/httpd.conf') as $main) if (@is_file("$base/$main")) $files[] = "$base/$main";
    foreach (array('mods-enabled/mpm_*.conf', 'conf.modules.d/*.conf', 'conf-enabled/*.conf', 'conf.d/*.conf', 'extra/httpd-mpm.conf') as $g)
    {
      foreach (glob("$base/$g") ?: array() as $x) $files[] = $x;
    }
  }
  if (!$files)
  {
    $info['error'] = 'Apache configuration not readable'.(ini_get('open_basedir') ? ' (open_basedir is set)' : '');
  }

  // MaxRequestWorkers (MaxClients before 2.4) inside the active MPM's block, or at top level
  foreach ($files as $file)
  {
    $block = null;
    foreach (@file($file) ?: array() as $n => $line)
    {
      $line = trim($line);
      if ($line === '' || $line[0] == '#') continue;
      if (preg_match('/^<IfModule\s+!?\s*(?:mod_)?mpm_(\w+)(?:_module|\.c)?\s*>/i', $line, $m)) { $block = strtolower($m[1]); continue; }
      if (preg_match('/^<IfModule\s+(prefork|worker|event)\.c\s*>/i', $line, $m)) { $block = strtolower($m[1]); continue; }
      if (preg_match('#^</IfModule>#i', $line)) { $block = null; continue; }
      if (preg_match('/^(MaxRequestWorkers|MaxClients)\s+(\d+)/i', $line, $m))
      {
        if ($block !== null && $info['mpm'] !== null && $block != $info['mpm']) continue;
        if ($block !== null && $info['mpm'] === null) $info['mpm'] = $block;
        $info['max_workers'] = (int)$m[2];
        $info['source'] = $file.':'.($n + 1);
        if ($info['mpm'] === null && preg_match('/mpm_(prefork|worker|event)\.conf$/', $file, $fm)) $info['mpm'] = $fm[1];
      }
    }
  }
  if ($info['max_workers'] === null && !$info['error'])
  {
    // compiled-in defaults
    $info['max_workers'] = ($info['mpm'] == 'prefork' || $info['mpm'] === null) ? 256 : 400;
    $info['source'] = 'Apache built-in default';
  }

  // child processes alive now (the root-owned parent isn't a worker)
  $running = 0; $seen = false;
  foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: array() as $p)
  {
    $comm = trim((string)@file_get_contents($p.'/comm'));
    if ($comm != 'apache2' && $comm != 'httpd') continue;
    $seen = true;
    if (@fileowner($p) !== 0) $running++;
  }
  if ($seen) $info['running'] = $running;
  return $info;
}

/**
 * How many PHP requests the web server can run at once, whatever the setup:
 * PHP-FPM pool (pm.max_children) or Apache module (MaxRequestWorkers).
 *
 * @return array  type (fpm|apache|other), label, setting, limit, source, running,
 *                pool, pm, mpm, sapi, error
 */
function pu_server_workers()
{
  $w = array('type' => 'other', 'label' => PHP_SAPI, 'setting' => null, 'limit' => null, 'source' => null,
             'running' => null, 'pool' => null, 'pm' => null, 'mpm' => null, 'sapi' => PHP_SAPI, 'error' => null);

  if (PHP_SAPI == 'fpm-fcgi')
  {
    $f = pu_fpm_pool_info();
    return array_merge($w, array(
      'type' => 'fpm', 'label' => 'PHP-FPM', 'setting' => 'pm.max_children',
      'limit' => $f['max_children'], 'source' => $f['source'], 'running' => $f['running'],
      'pool' => $f['pool'], 'pm' => $f['pm'], 'error' => $f['error'],
    ));
  }
  if (PHP_SAPI == 'apache2handler')
  {
    $a = pu_apache_info();
    return array_merge($w, array(
      'type' => 'apache', 'label' => 'Apache (mod_php)', 'setting' => 'MaxRequestWorkers',
      'limit' => $a['max_workers'], 'source' => $a['source'], 'running' => $a['running'],
      'mpm' => $a['mpm'], 'error' => $a['error'],
    ));
  }
  $w['error'] = 'Worker limit not detected for PHP SAPI "'.PHP_SAPI.'"';
  return $w;
}

/** Back-compat name used by the pages. */
function pu_fpm_info()
{
  return pu_server_workers();
}

/** Workers the plugin needs: one per parallel chunk, plus room for page loads and the finish step. */
function pu_fpm_needed($cfg)
{
  return (int)$cfg['concurrency'] + 2;
}
