<?php
// Actual WordPress/WooCommerce, private new database, never a production site.
$root = realpath((string) getenv('WPHUB_FIXTURE_ROOT'));
$mode = $argv[1] ?? '';
if (! $root || ! is_file($root.'/FICTIONAL-ONLY') || ! is_file($root.'/private.json')
    || getenv('WPHUB_VALIDATION_MAIL_TRANSPORT') !== 'capture') {
    throw new RuntimeException('Private fictional capture fixture required.');
}
define('WPHUB_FIXTURE_ROOT', $root);
require dirname(__DIR__, 2).'/integrations/wphub-documents/tests/real/windows-readability.php';
define('WP_USE_THEMES', false);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
$_SERVER['HTTP_HOST'] = 'fixture.example.invalid';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS'] = 'on';
require $root.'/wordpress/wp-load.php';
global $wpdb;
if (DB_NAME !== 'wphub_fixture_hpos' || DB_HOST !== '127.0.0.1:33177'
    || ! str_starts_with(strtolower(str_replace('\\', '/', $wpdb->get_var('SELECT @@datadir'))), strtolower(str_replace('\\', '/', $root)).'/data/')) {
    throw new RuntimeException('Fresh fixture database identity mismatch.');
}
WC()->mailer();
$private = json_decode(file_get_contents($root.'/private.json'), true);
if ($mode === 'init') {
    WC_Install::install();
    WC_Install::create_roles();
    WC_Install::create_tables();
    wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class)->create_database_tables();
    $key = 'ck_'.bin2hex(random_bytes(20));
    $secret = 'cs_'.bin2hex(random_bytes(20));
    $wpdb->insert($wpdb->prefix.'woocommerce_api_keys', ['user_id'=>1,'description'=>'Fictional isolated network fixture',
        'permissions'=>'read_write','consumer_key'=>wc_api_hash($key),'consumer_secret'=>$secret,'truncated_key'=>substr($key,-7)]);
    update_option(WPHub_Documents::OPTION, ['website_identity'=>$private['pairing'],'enabled'=>false]);
    $private['consumer_key']=$key;
    $private['consumer_secret']=$secret;
    file_put_contents($root.'/private.json', json_encode($private));
    echo "Fictional REST credentials created privately.\n";
} elseif ($mode === 'seed') {
    $orders = [];
    foreach (['normal_en'=>'en', 'lost_response_fr'=>'fr', 'stale_preview_en'=>'en'] as $label=>$language) {
        $order=wc_create_order();
        $order->set_billing_email('fixture-recipient@example.invalid');
        $order->set_billing_first_name('Alex FICTIONAL');
        $order->set_billing_last_name($label);
        $order->set_currency('USD');
        $order->set_total('15.00');
        $item = new WC_Order_Item_Product();
        $item->set_name('FICTIONAL sample, not a travel document');
        $item->set_quantity(1); $item->set_total('15.00'); $item->set_subtotal('15.00');
        $order->add_item($item); $order->set_payment_method('fixture-no-gateway');
        $order->update_meta_data('_order_locale',$language); $order->set_date_paid(time()-600);
        $order->save(); $order->update_status('processing');
        $orders[$label]=$order->get_id();
    }
    file_put_contents($root.'/orders.json', json_encode($orders,JSON_PRETTY_PRINT));
    file_put_contents($root.'/acknowledgements.json', json_encode($GLOBALS['wphub_fixture_mail'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo json_encode(['fictional_orders'=>$orders,'captured_notifications'=>count($GLOBALS['wphub_fixture_mail']),'real_emails_sent'=>0])."\n";
} elseif ($mode === 'evidence') {
    $orders=json_decode(file_get_contents($root.'/orders.json'),true);
    $result=['wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION,'php'=>PHP_VERSION,'mysql'=>$wpdb->db_version(),
        'hpos'=>\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
        'compatibility_sync'=>get_option('woocommerce_custom_orders_table_data_sync_enabled'),
        'gateways'=>count(WC()->payment_gateways()->payment_gateways()),'native_mail_disabled'=>!function_exists('mail'),'orders'=>[]];
    foreach($orders as $label=>$id) {
        $order=wc_get_order($id);
        $notes=wc_get_order_notes(['order_id'=>$id,'type'=>'internal']);
        $result['orders'][$label]=['id'=>$id,'status'=>$order->get_status(),'notes'=>array_map(fn($note)=>$note->content,$notes)];
    }
    file_put_contents($root.'/store-evidence.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
} else { throw new RuntimeException('Unknown private CLI fixture action.'); }
