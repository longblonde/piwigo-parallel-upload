<?php
defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

class ParallelUpload_maintain extends PluginMaintain
{
  function install($plugin_version, &$errors = array())
  {
    global $conf;
    if (empty($conf['parallel_upload']))
    {
      include_once(PHPWG_PLUGINS_PATH.'ParallelUpload/include/functions.inc.php');
      conf_update_param('parallel_upload', json_encode(pu_default_config()));
    }
  }

  function update($old_version, $new_version, &$errors = array())
  {
    $this->install($new_version, $errors);
  }

  function uninstall()
  {
    global $conf;
    conf_delete_param('parallel_upload');
    $dir = PHPWG_ROOT_PATH.$conf['upload_dir'].'/buffer/parallel';
    if (is_dir($dir))
    {
      include_once(PHPWG_PLUGINS_PATH.'ParallelUpload/include/functions.inc.php');
      pu_rrmdir($dir);
    }
  }
}
