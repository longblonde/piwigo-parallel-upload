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
    // ---- Community plugin users (registered, non-admin) ----
    'community_mode'        => 'off', // off | tab (extra tab on Community's upload page) | replace (takes it over)
    'community_max_file_mb' => 0,     // 0 = same as the gallery's upload_form_max_file_size
    'community_max_open'    => 3,     // uploads one user may have in progress at once
    'community_max_reserved_gb' => 20,// disk one user's unfinished uploads may hold
    'community_concurrency' => 4,     // parallel connections for one Community user
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
  foreach (@scandir($dir) ?: array() as $f)
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

  // Several files start at once (one request each), and each used to scan and
  // delete in parallel: one request removed a folder while another was reading
  // it. Now only one request cleans at a time, and only every 10 minutes.
  $lock = $base.'/.cleanup.lock';
  $lf = @fopen($lock, 'c');
  if (!$lf) return 0;
  if (!flock($lf, LOCK_EX | LOCK_NB)) { fclose($lf); return 0; }   // another request is on it
  clearstatcache(true, $lock);
  $ran = @filemtime($lock);
  if ($ran && $ran > time() - 600 && @filesize($lock) > 0) { flock($lf, LOCK_UN); fclose($lf); return 0; }
  ftruncate($lf, 0); fwrite($lf, (string)time()); fflush($lf);
  @touch($lock);

  $n = 0;
  foreach (glob($base.'/pu_*', GLOB_ONLYDIR) ?: array() as $dir)
  {
    clearstatcache();
    $last = @filemtime($dir);
    if ($last === false) continue;                       // already gone
    foreach (array('meta.json', 'data.part', 'result.json') as $f)
    {
      $t = @filemtime($dir.'/'.$f);
      if ($t !== false) $last = max($last, $t);
    }
    if ($last < $limit)
    {
      pu_rrmdir($dir);
      $n++;
    }
  }
  flock($lf, LOCK_UN);
  fclose($lf);
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

// ===========================================================================
// Community plugin integration
// ===========================================================================

/** Community is installed, active and the version we know (its permission + moderation tables). */
function pu_community_available()
{
  return defined('COMMUNITY_PENDINGS_TABLE')
    && defined('COMMUNITY_PERMISSIONS_TABLE')
    && function_exists('community_get_user_permissions')
    && function_exists('community_get_user_limits');
}

function pu_community_mode($cfg = null)
{
  if (!pu_community_available()) return 'off';
  if ($cfg === null) $cfg = pu_get_config();
  return in_array($cfg['community_mode'], array('tab', 'replace')) ? $cfg['community_mode'] : 'off';
}

/**
 * Who is calling: 'admin', 'community' (a registered user Community lets upload
 * somewhere) or null (nobody we serve).
 *
 * Guests and shared "generic" accounts are never let in, even when Community
 * gives "any visitor" upload rights: big parallel uploads from anonymous
 * visitors are an abuse risk this plugin doesn't take.
 */
function pu_access_kind()
{
  global $user;
  if (is_admin()) return 'admin';
  if (pu_community_mode() == 'off') return null;
  if (is_a_guest() || $user['id'] == $GLOBALS['conf']['guest_id']) return null;
  if (!is_autorize_status(ACCESS_CLASSIC)) return null;          // excludes guest and generic
  $p = pu_community_perms();
  return empty($p['community_enabled']) ? null : 'community';
}

function pu_community_perms()
{
  global $user;
  if (!pu_community_available()) return array('community_enabled' => false, 'upload_categories' => array());
  $p = community_get_user_permissions($user['id']);
  $p['upload_categories'] = array_values(array_map('intval', (array)$p['upload_categories']));
  $p['create_categories'] = array_values(array_map('intval', (array)$p['create_categories']));
  return $p;
}

function pu_community_can_upload_to($category_id)
{
  $p = pu_community_perms();
  return in_array((int)$category_id, $p['upload_categories'], true);
}

/** Largest single file a Community user may send, in bytes (0 = no limit). */
function pu_community_max_file($cfg = null)
{
  global $conf;
  if ($cfg === null) $cfg = pu_get_config();
  $gallery = (int)$conf['upload_form_max_file_size'] * 1024 * 1024;
  $own = (int)$cfg['community_max_file_mb'] * 1024 * 1024;
  if ($own > 0 && ($gallery == 0 || $own < $gallery)) return $own;
  return $gallery;
}

/** This user's unfinished uploads in the buffer: count and bytes they hold. */
function pu_user_open_uploads($user_id, $except = null)
{
  $n = 0; $bytes = 0;
  foreach (glob(pu_buffer_dir().'/pu_'.(int)$user_id.'_*', GLOB_ONLYDIR) ?: array() as $dir)
  {
    if ($except !== null && basename($dir) == $except) continue;
    if (!file_exists($dir.'/data.part')) continue;      // finished or failed: holds nothing
    $meta = @json_decode(@file_get_contents($dir.'/meta.json'), true);
    if (!is_array($meta)) continue;
    $n++;
    $bytes += (int)$meta['size'];
  }
  return array($n, $bytes);
}

/**
 * Community's photo-count and disk quotas, counting photos already in the
 * gallery plus uploads still in progress. Returns an error message or null.
 * $extra_bytes / $extra_photos: what the request is about to add.
 */
function pu_community_quota_error($perms, $user_id, $extra_bytes, $extra_photos, $except_upload = null)
{
  $usage = community_get_user_limits($user_id);       // nb_photos, storage (MB)
  list($open_n, $open_bytes) = pu_user_open_uploads($user_id, $except_upload);

  if ((int)$perms['nb_photos'] > 0 && $usage['nb_photos'] + $open_n + $extra_photos > (int)$perms['nb_photos'])
  {
    return sprintf(l10n('Maximum number of photos reached (%u)'), $perms['nb_photos']);
  }
  if ((int)$perms['storage'] > 0)
  {
    $mb = $usage['storage'] + ($open_bytes + $extra_bytes) / 1048576;
    if ($mb > (int)$perms['storage'])
    {
      return sprintf(l10n('Disk usage quota reached (%uMB)'), $perms['storage']);
    }
  }
  return null;
}

/**
 * Community's moderation for a photo it did not see being uploaded.
 * Same rule as Community's own uploader (community_sendResponse): the photo
 * is held for approval unless one of the user's permissions on the path from
 * the album up to the gallery root is unmoderated.
 * Returns true when the photo is pending approval.
 */
function pu_community_moderate($image_id, $category_id)
{
  $perms = pu_community_perms();
  $category_infos = get_cat_info($category_id);

  $moderate = true;
  if (count($perms['permission_ids']) > 0)
  {
    $rows = query2array('
SELECT cp.category_id, c.uppercats
  FROM '.COMMUNITY_PERMISSIONS_TABLE.' AS cp
    LEFT JOIN '.CATEGORIES_TABLE.' AS c ON cp.category_id = c.id
  WHERE cp.id IN ('.implode(',', array_map('intval', $perms['permission_ids'])).')
    AND cp.moderated = \'false\'
;');
    foreach ($rows as $row)
    {
      if (empty($row['category_id'])) { $moderate = false; break; }
      if (preg_match('/^'.preg_quote($row['uppercats'], '/').'(,|$)/', $category_infos['uppercats'])) { $moderate = false; break; }
    }
  }

  $image = pwg_db_fetch_assoc(pwg_query('SELECT id, date_available FROM '.IMAGES_TABLE.' WHERE id = '.(int)$image_id.';'));
  pwg_query('DELETE FROM '.COMMUNITY_PENDINGS_TABLE.' WHERE image_id = '.(int)$image_id.';');
  single_insert(COMMUNITY_PENDINGS_TABLE, array(
    'image_id' => (int)$image_id,
    'added_on' => $image['date_available'],
    'state'    => $moderate ? 'moderation_pending' : 'validated',
  ));
  pwg_query('UPDATE '.IMAGES_TABLE.' SET level = '.($moderate ? 16 : 0).' WHERE id = '.(int)$image_id.';');
  invalidate_user_cache();
  return $moderate;
}

/** Serialize one user's upload starts, so two of them can't both pass the limits. */
function pu_user_lock($user_id)
{
  $dir = pu_buffer_dir();
  if (!is_dir($dir)) @mkgetdir($dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR);
  $fp = @fopen($dir.'/.user_'.(int)$user_id.'.lock', 'c');
  if ($fp) flock($fp, LOCK_EX);
  return $fp;
}
