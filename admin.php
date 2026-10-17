<?php
defined('PU_PATH') or die('Hacking attempt!');

include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
check_status(ACCESS_ADMINISTRATOR);

load_language('plugin.lang', PU_PATH);

// admin.php?page=plugin-ParallelUpload-upload  -> uploader (any administrator)
// admin.php?page=plugin-ParallelUpload         -> settings (webmaster only; the
//                                                 "Settings" link in Admin > Plugins)
if (isset($_GET['tab']) && $_GET['tab'] == 'upload')
{
  include(PU_PATH.'include/admin_upload.inc.php');
}
else
{
  include(PU_PATH.'include/admin_settings.inc.php');
}
