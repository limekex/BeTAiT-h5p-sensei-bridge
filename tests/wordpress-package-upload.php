<?php
/** Exercise the real wp-admin multipart upload endpoint, not just ZIP extraction. */
if (!defined('FKHS_INTEGRATION_TESTS') || true !== FKHS_INTEGRATION_TESTS || !defined('WP_CLI') || !WP_CLI || home_url()!=='http://wordpress') {
    throw new RuntimeException('Disposable package upload fixture required.');
}
$path=$args[0]??'';
if(dirname($path)!=='/packages' || !is_file($path))throw new RuntimeException('Fixture package required');
wp_set_current_user(1);
$expiration=time()+HOUR_IN_SECONDS;
$sessions=WP_Session_Tokens::get_instance(1);
$token=$sessions->create($expiration);
$logged=wp_generate_auth_cookie(1,$expiration,'logged_in',$token);
$auth=wp_generate_auth_cookie(1,$expiration,'auth',$token);
$_COOKIE[LOGGED_IN_COOKIE]=$logged;
$nonce=wp_create_nonce('plugin-upload');
$boundary='fkhs-package-'.wp_generate_password(16,false);
$body='--'.$boundary."\r\nContent-Disposition: form-data; name=\"_wpnonce\"\r\n\r\n".$nonce."\r\n";
$body.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"pluginzip\"; filename=\"".basename($path)."\"\r\nContent-Type: application/zip\r\n\r\n".file_get_contents($path)."\r\n--".$boundary."--\r\n";
try {
    $response=wp_remote_post(admin_url('update.php?action=upload-plugin'),array('timeout'=>60,'redirection'=>0,'headers'=>array('Cookie'=>AUTH_COOKIE.'='.rawurlencode($auth).'; '.LOGGED_IN_COOKIE.'='.rawurlencode($logged),'Content-Type'=>'multipart/form-data; boundary='.$boundary),'body'=>$body));
    if(is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200 || !is_file(WP_PLUGIN_DIR.'/h5p-sensei-bridge/h5p-sensei-bridge.php')) {
        throw new RuntimeException('Native plugin upload failed: '.(is_wp_error($response)?$response->get_error_message():wp_strip_all_tags(wp_remote_retrieve_body($response))));
    }
    // Compare the actual installed files against every entry in the uploaded ZIP.
    $zip=new ZipArchive();
    if(true!==$zip->open($path))throw new RuntimeException('Cannot inspect uploaded ZIP');
    for($i=0;$i<$zip->numFiles;$i++) {
        $name=$zip->getNameIndex($i);
        if(file_get_contents(WP_PLUGIN_DIR.'/'.$name)!==$zip->getFromIndex($i))throw new RuntimeException('Uploaded file mismatch: '.$name);
    }
    $zip->close();
    WP_CLI::success('Native authenticated wp-admin ZIP upload and installed-file comparison passed.');
} finally { $sessions->destroy($token); unset($_COOKIE[LOGGED_IN_COOKIE]); }
