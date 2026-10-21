<?php
// Only the local TLS proxy can reach this loopback HTTP process. No public document root.
$root = realpath((string) getenv('WPHUB_FIXTURE_ROOT'));
$path=parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH);
if (!$root || !is_file($root.'/FICTIONAL-ONLY') || !is_file($root.'/private.json')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)
    || ($_SERVER['HTTP_HOST'] ?? '')!=='fixture.example.invalid'
    || !preg_match('#^/wp-json/wc/v3/(?:orders(?:/|$)|wphub-documents/)#',$path)) {
    http_response_code(404); exit;
}
define('WPHUB_FIXTURE_ROOT',$root);
require dirname(__DIR__,2).'/integrations/wphub-documents/tests/real/windows-readability.php';
define('WP_USE_THEMES',false);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
$_SERVER['HTTPS']='on';
$_SERVER['SERVER_PORT']='443';
$_SERVER['SCRIPT_NAME']='/index.php';
$_SERVER['PHP_SELF']='/index.php';
$_GET['rest_route']=substr($path,strlen('/wp-json'));
require $root.'/wordpress/wp-load.php';
register_shutdown_function(static function () use ($root,$path) {
    file_put_contents($root.'/store-requests.jsonl',json_encode(['method'=>$_SERVER['REQUEST_METHOD'],'path'=>$path,
        'status'=>http_response_code(),'captured_notifications'=>count($GLOBALS['wphub_fixture_mail']),
        'notification_subjects'=>array_column($GLOBALS['wphub_fixture_mail'],'subject')])."\n",FILE_APPEND);
});
wp();
