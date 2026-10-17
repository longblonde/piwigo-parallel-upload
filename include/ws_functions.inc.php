<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/*
 * Protocol
 * --------
 *   pu.upload.init    name, size, chunk_size, category, level, update_mode  -> upload_id, chunks
 *   pu.upload.chunk   upload_id, index, sha256?, $_FILES[chunk]             -> received count
 *                     (any order, any concurrency; each chunk is written at
 *                      index*chunk_size into one preallocated file - no merge step)
 *   pu.upload.finish  upload_id                                             -> same payload as pwg.images.upload
 *   pu.upload.status  upload_id   (poll this if finish got cut off by the proxy's 100 s timeout)
 *   pu.upload.abort   upload_id
 *
 * All methods: admin only, POST only, pwg_token required.
 */

function pu_ws_add_methods($arr)
{
  $service = &$arr[0];
  $f = PU_PATH.'include/ws_functions.inc.php';
  $opt = array('admin_only' => true, 'post_only' => true);

  $service->addMethod('pu.upload.init', 'pu_ws_init', array(
      'name'        => array(),
      'size'        => array(),
      'chunk_size'  => array('type' => WS_TYPE_INT | WS_TYPE_POSITIVE),
      'category'    => array('type' => WS_TYPE_ID),
      'level'       => array('default' => 0, 'type' => WS_TYPE_INT | WS_TYPE_POSITIVE),
      'update_mode' => array('default' => false, 'type' => WS_TYPE_BOOL),
      'pwg_token'   => array(),
    ), 'Parallel Upload: start a chunked upload.', $f, $opt);

  $service->addMethod('pu.upload.chunk', 'pu_ws_chunk', array(
      'upload_id' => array(),
      'index'     => array('type' => WS_TYPE_INT | WS_TYPE_POSITIVE),
      'sha256'    => array('default' => ''),
      'pwg_token' => array(),
    ), 'Parallel Upload: send one chunk ($_FILES[chunk]). Chunks may arrive in any order and in parallel.', $f, $opt);

  $service->addMethod('pu.upload.finish', 'pu_ws_finish', array(
      'upload_id' => array(),
      'pwg_token' => array(),
    ), 'Parallel Upload: all chunks sent, register the photo.', $f, $opt);

  $service->addMethod('pu.upload.status', 'pu_ws_status', array(
      'upload_id' => array(),
      'pwg_token' => array(),
    ), 'Parallel Upload: state of an upload (used to recover a finish that outlived the proxy timeout).', $f, $opt);

  $service->addMethod('pu.upload.abort', 'pu_ws_abort', array(
      'upload_id' => array(),
      'pwg_token' => array(),
    ), 'Parallel Upload: cancel and delete a partial upload.', $f, $opt);
}

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

function pu_check_token($params)
{
  return get_pwg_token() == $params['pwg_token'];
}

/** @return array(dir, meta) or PwgError */
function pu_load($upload_id)
{
  global $user;
  if (!preg_match('/^pu_(\d+)_[a-f0-9]{24}$/', $upload_id, $m))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid upload_id');
  }
  if ((int)$m[1] != (int)$user['id'])
  {
    return new PwgError(403, 'This upload belongs to another user');
  }
  $dir = pu_buffer_dir().'/'.$upload_id;
  if (!is_file($dir.'/meta.json'))
  {
    return new PwgError(404, 'Unknown or expired upload_id');
  }
  $meta = json_decode(file_get_contents($dir.'/meta.json'), true);
  if (!is_array($meta))
  {
    return new PwgError(500, 'Corrupted upload metadata');
  }
  return array($dir, $meta);
}

function pu_missing_chunks($dir, $meta)
{
  $missing = array();
  for ($i = 0; $i < $meta['chunks']; $i++)
  {
    if (!file_exists($dir.'/c_'.$i)) $missing[] = $i;
  }
  return $missing;
}

function pu_chunk_len($meta, $index)
{
  $offset = $index * $meta['chunk_size'];
  return min($meta['chunk_size'], $meta['size'] - $offset);
}

// ---------------------------------------------------------------------------
// pu.upload.init
// ---------------------------------------------------------------------------

function pu_ws_init($params, $service)
{
  global $conf, $user;

  if (!pu_check_token($params)) return new PwgError(403, 'Invalid security token');

  $name = trim(basename(str_replace('\\', '/', stripslashes($params['name']))));
  if ($name === '') return new PwgError(WS_ERR_INVALID_PARAM, 'Missing file name');

  $ext = strtolower(get_extension($name));
  if (!in_array($ext, pu_allowed_exts()))
  {
    return new PwgError(415, sprintf('File type ".%s" is not allowed (allowed: %s)', $ext, implode(', ', pu_allowed_exts())));
  }

  if (!preg_match('/^\d{1,15}$/', (string)$params['size']) || (int)$params['size'] <= 0)
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid size');
  }
  $size = (int)$params['size'];

  $max_file = (int)$conf['upload_form_max_file_size'] * 1024 * 1024;
  if ($max_file > 0 && $size > $max_file)
  {
    return new PwgError(413, sprintf(
      '%s is larger than $conf[\'upload_form_max_file_size\'] (%d MB)', $name, $conf['upload_form_max_file_size']));
  }

  list($server_max, $why) = pu_server_max_chunk();
  $chunk_size = (int)$params['chunk_size'];
  if ($chunk_size < 64 * 1024 || $chunk_size > $server_max)
  {
    return new PwgError(WS_ERR_INVALID_PARAM, sprintf('chunk_size must be between 64 KB and %d bytes (%s)', $server_max, $why));
  }
  $chunks = (int)ceil($size / $chunk_size);
  if ($chunks > 20000) return new PwgError(WS_ERR_INVALID_PARAM, 'Too many chunks, raise the chunk size');

  $query = 'SELECT COUNT(*) FROM '.CATEGORIES_TABLE.' WHERE id = '.(int)$params['category'].';';
  list($count) = pwg_db_fetch_row(pwg_query($query));
  if ($count == 0) return new PwgError(404, 'Album not found');

  if (!in_array((int)$params['level'], $conf['available_permission_levels']))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid privacy level');
  }

  $base = pu_buffer_dir();
  if (!mkgetdir($base, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR))
  {
    return new PwgError(500, 'Unable to create buffer directory '.$base);
  }
  secure_directory($base);
  pu_cleanup_stale();

  $free = @disk_free_space($base);
  if ($free !== false && $free < $size + 64 * 1024 * 1024)
  {
    return new PwgError(507, sprintf('Not enough free space in %s for %s', $base, $name));
  }

  $upload_id = 'pu_'.(int)$user['id'].'_'.bin2hex(random_bytes(12));
  $dir = $base.'/'.$upload_id;
  if (!@mkdir($dir, 0755)) return new PwgError(500, 'Unable to create '.$dir);

  // Preallocate (sparse on ZFS/ext4) so chunks can be written at their offset in any order.
  $fp = @fopen($dir.'/data.part', 'wb');
  if (!$fp || !ftruncate($fp, $size))
  {
    pu_rrmdir($dir);
    return new PwgError(500, 'Unable to allocate buffer file');
  }
  fclose($fp);

  $meta = array(
    'user_id'     => (int)$user['id'],
    'name'        => $name,
    'size'        => $size,
    'chunk_size'  => $chunk_size,
    'chunks'      => $chunks,
    'category'    => (int)$params['category'],
    'level'       => (int)$params['level'],
    'update_mode' => (bool)$params['update_mode'],
    'created'     => time(),
  );
  file_put_contents($dir.'/meta.json', json_encode($meta));

  return array(
    'upload_id'  => $upload_id,
    'chunks'     => $chunks,
    'chunk_size' => $chunk_size,
  );
}

// ---------------------------------------------------------------------------
// pu.upload.chunk
// ---------------------------------------------------------------------------

function pu_ws_chunk($params, $service)
{
  if (!pu_check_token($params)) return new PwgError(403, 'Invalid security token');

  $r = pu_load($params['upload_id']);
  if ($r instanceof PwgError) return $r;
  list($dir, $meta) = $r;

  if (is_dir($dir.'/lock')) return new PwgError(409, 'Upload is already being finalized');

  $index = (int)$params['index'];
  if ($index < 0 || $index >= $meta['chunks'])
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Chunk index out of range');
  }

  if (empty($_FILES['chunk']))
  {
    // post_max_size exceeded empties $_FILES and $_POST entirely, so we would not even get here
    return new PwgError(400, 'No chunk payload received');
  }
  $err = $_FILES['chunk']['error'];
  if ($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE)
  {
    return new PwgError(413, 'Chunk larger than PHP upload_max_filesize ('.ini_get('upload_max_filesize').')');
  }
  if ($err != UPLOAD_ERR_OK || !is_uploaded_file($_FILES['chunk']['tmp_name']))
  {
    return new PwgError(500, 'PHP upload error code '.$err);
  }

  $expected = pu_chunk_len($meta, $index);
  $got = filesize($_FILES['chunk']['tmp_name']);
  if ($got != $expected)
  {
    return new PwgError(400, sprintf('Chunk %d: expected %d bytes, received %d', $index, $expected, $got));
  }

  $in  = fopen($_FILES['chunk']['tmp_name'], 'rb');
  $out = fopen($dir.'/data.part', 'c+b'); // read/write, no truncate
  if (!$in || !$out) return new PwgError(500, 'Unable to open buffer file');

  if (fseek($out, $index * $meta['chunk_size']) !== 0)
  {
    fclose($in); fclose($out);
    return new PwgError(500, 'Seek failed');
  }

  $ctx = hash_init('sha256');
  $written = 0;
  while (!feof($in))
  {
    $buf = fread($in, 1048576);
    if ($buf === false || $buf === '') break;
    hash_update($ctx, $buf);
    $w = fwrite($out, $buf);
    if ($w === false || $w != strlen($buf))
    {
      fclose($in); fclose($out);
      return new PwgError(507, 'Write failed (disk full?)');
    }
    $written += $w;
  }
  fflush($out);
  fclose($out);
  fclose($in);
  @unlink($_FILES['chunk']['tmp_name']);

  $sha = hash_final($ctx);
  $want = strtolower(trim($params['sha256']));
  if ($want !== '' && $want !== $sha)
  {
    @unlink($dir.'/c_'.$index);
    return new PwgError(422, 'Chunk '.$index.' checksum mismatch, resend');
  }

  touch($dir.'/c_'.$index);

  return array(
    'index'    => $index,
    'bytes'    => $written,
    'sha256'   => $sha,
    'received' => $meta['chunks'] - count(pu_missing_chunks($dir, $meta)),
    'chunks'   => $meta['chunks'],
  );
}

// ---------------------------------------------------------------------------
// pu.upload.finish
// ---------------------------------------------------------------------------

function pu_ws_finish($params, $service)
{
  if (!pu_check_token($params)) return new PwgError(403, 'Invalid security token');

  $r = pu_load($params['upload_id']);
  if ($r instanceof PwgError) return $r;
  list($dir, $meta) = $r;

  if (file_exists($dir.'/result.json'))
  {
    return pu_result_response($dir);
  }

  $missing = pu_missing_chunks($dir, $meta);
  if (count($missing) > 0)
  {
    return new PwgError(409, 'Missing chunks: '.implode(',', array_slice($missing, 0, 200)));
  }
  clearstatcache();
  if (filesize($dir.'/data.part') != $meta['size'])
  {
    return new PwgError(500, 'Assembled size mismatch');
  }

  // atomic lock: only one finisher
  if (!@mkdir($dir.'/lock'))
  {
    return new PwgError(409, 'Already finishing, poll pu.upload.status');
  }

  // Keep going even if Cloudflare drops the connection at 100 s: the browser
  // will poll pu.upload.status and pick up result.json.
  ignore_user_abort(true);
  @set_time_limit(0);

  $GLOBALS['pu_finish_dir'] = $dir;
  ob_start();
  register_shutdown_function('pu_finish_shutdown');

  $result = pu_register_photo($dir, $meta);

  $GLOBALS['pu_finish_done'] = true;
  $noise = ob_get_clean();

  if ($result instanceof PwgError)
  {
    file_put_contents($dir.'/result.json', json_encode(array('error' => $result->message(), 'code' => $result->code())));
    @unlink($dir.'/data.part');
    return $result;
  }

  file_put_contents($dir.'/result.json', json_encode(array('result' => $result)));
  @unlink($dir.'/data.part');
  foreach (glob($dir.'/c_*') ?: array() as $c) @unlink($c);
  return $result;
}

/** Catch die()/fatal inside add_uploaded_file so the poller gets an error, not an endless "finishing". */
function pu_finish_shutdown()
{
  if (!empty($GLOBALS['pu_finish_done'])) return;
  $dir = $GLOBALS['pu_finish_dir'];
  $out = '';
  while (ob_get_level() > 0) $out .= ob_get_clean();
  $err = error_get_last();
  $msg = trim(strip_tags($out));
  if ($msg === '' && $err) $msg = $err['message'];
  if ($msg === '') $msg = 'Server stopped while registering the photo';
  // a PwgError already sent by core (e.g. MIME mismatch) is JSON - extract its message
  $j = json_decode($out, true);
  if (is_array($j) && isset($j['message'])) $msg = $j['message'];
  @file_put_contents($dir.'/result.json', json_encode(array('error' => $msg, 'code' => 500)));
  @unlink($dir.'/data.part');
  echo $out;
}

function pu_result_response($dir)
{
  $r = json_decode(file_get_contents($dir.'/result.json'), true);
  if (isset($r['result'])) return $r['result'];
  return new PwgError(isset($r['code']) ? $r['code'] : 500, isset($r['error']) ? $r['error'] : 'Unknown error');
}

/**
 * Same registration path as core pwg.images.upload (last chunk branch), Piwigo 16.x.
 */
function pu_register_photo($dir, $meta)
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

  $category = $meta['category'];
  $name = pwg_db_real_escape_string($meta['name']);
  $id_image = null;
  $add_status = 'add';

  if ($meta['update_mode'])
  {
    $query = '
SELECT i.id
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE i.file = \''.$name.'\'
    AND ic.category_id = '.$category.'
;';
    $images = query2array($query);
    if (!empty($images))
    {
      $id_image = $images[0]['id'];
      $add_status = 'update';
    }
  }

  // Plugins hooked on loc_end_add_uploaded_file (e.g. Real Folders) read the
  // target album from the request, as they would for core pwg.images.upload.
  // This request only carries upload_id, so expose the album the same way.
  $_REQUEST['category'] = $_POST['category'] = array($category);

  $image_id = add_uploaded_file(
    $dir.'/data.part',
    $name,
    array($category),
    $meta['level'],
    $id_image
  );

  if (empty($image_id)) return new PwgError(500, 'add_uploaded_file failed');

  $image_infos = pwg_db_fetch_assoc(pwg_query('
SELECT id, name, representative_ext, path
  FROM '.IMAGES_TABLE.'
  WHERE id = '.$image_id.'
;'));

  list($nb_photos) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*) FROM '.IMAGE_CATEGORY_TABLE.' WHERE category_id = '.$category.';'));

  list($nb_lounge) = pwg_db_fetch_row(pwg_query('
SELECT COUNT(*) FROM '.LOUNGE_TABLE.'
  WHERE category_id = '.$category.'
    AND image_id NOT IN (SELECT image_id FROM '.IMAGE_CATEGORY_TABLE.')
;'));

  return array(
    'image_id'   => $image_id,
    'src'        => DerivativeImage::thumb_url($image_infos),
    'square_src' => DerivativeImage::url(ImageStdParams::get_by_type(IMG_SQUARE), $image_infos),
    'name'       => $image_infos['name'],
    'category'   => array(
      'id'        => $category,
      'nb_photos' => $nb_photos + $nb_lounge,
      'label'     => get_cat_display_name_from_id($category, null),
    ),
    'add_status' => $add_status,
  );
}

// ---------------------------------------------------------------------------
// pu.upload.status / pu.upload.abort
// ---------------------------------------------------------------------------

function pu_ws_status($params, $service)
{
  if (!pu_check_token($params)) return new PwgError(403, 'Invalid security token');

  $r = pu_load($params['upload_id']);
  if ($r instanceof PwgError) return $r;
  list($dir, $meta) = $r;

  if (file_exists($dir.'/result.json'))
  {
    $res = json_decode(file_get_contents($dir.'/result.json'), true);
    return isset($res['result'])
      ? array('state' => 'done', 'result' => $res['result'])
      : array('state' => 'error', 'message' => $res['error']);
  }
  if (is_dir($dir.'/lock'))
  {
    return array('state' => 'finishing');
  }
  $missing = pu_missing_chunks($dir, $meta);
  return array(
    'state'    => 'uploading',
    'received' => $meta['chunks'] - count($missing),
    'chunks'   => $meta['chunks'],
    'missing'  => array_slice($missing, 0, 500),
  );
}

function pu_ws_abort($params, $service)
{
  if (!pu_check_token($params)) return new PwgError(403, 'Invalid security token');

  $r = pu_load($params['upload_id']);
  if ($r instanceof PwgError) return $r;
  list($dir) = $r;

  if (is_dir($dir.'/lock') && !file_exists($dir.'/result.json'))
  {
    return new PwgError(409, 'Upload is being finalized, cannot abort');
  }
  pu_rrmdir($dir);
  return array('aborted' => true);
}
