<?php
// Narrow read-only projection of the two fictional store orders after a real-Gmail journey.
$options=getopt('', ['fixture:']);$root=realpath((string)($options['fixture']??''));
if(PHP_SAPI!=='cli' || !$root || !is_file($root.'/FICTIONAL-ONLY') || !is_file($root.'/gmail-bridge-prepared.json')
    || getenv('WPHUB_VALIDATION_MAIL_TRANSPORT')!=='capture' || function_exists('mail'))throw new RuntimeException('Exact isolated fixture and capture guard required');
$prepared=json_decode(file_get_contents($root.'/gmail-bridge-prepared.json'),true,flags:JSON_THROW_ON_ERROR);
$expected=['en'=>'youssefbenamar755@gmail.com','fr'=>'benamar-y14@outlook.com'];
if(array_keys($prepared)!==array_keys($expected))throw new RuntimeException('Exact two fictional orders required');
define('WPHUB_FIXTURE_ROOT',$root);require dirname(__DIR__,2).'/integrations/wphub-documents/tests/real/windows-readability.php';
define('WP_USE_THEMES',false);define('WP_DISABLE_FATAL_ERROR_HANDLER',true);$_SERVER['HTTP_HOST']='fixture.example.invalid';$_SERVER['REQUEST_URI']='/';$_SERVER['HTTPS']='on';
require $root.'/wordpress/wp-load.php';global $wpdb;
if(DB_HOST!=='127.0.0.1:33177' || DB_NAME!=='wphub_fixture_hpos' || !str_starts_with(strtolower(str_replace('\\','/',$wpdb->get_var('SELECT @@datadir'))),strtolower(str_replace('\\','/',$root)).'/data/'))throw new RuntimeException('Unexpected database rejected');
$wpdb->query('SET SESSION TRANSACTION READ ONLY');$wpdb->query('START TRANSACTION');
$result=['orders'=>[],'wordpress'=>get_bloginfo('version'),'woocommerce'=>WC_VERSION,'hpos'=>\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
    'compatibility_sync'=>get_option('woocommerce_custom_orders_table_data_sync_enabled'),'workflow_enabled'=>(bool)(get_option(WPHub_Documents::OPTION,[])['enabled']??false),'read_only_projection'=>true];
try {
    foreach($prepared as $language=>$record) {
        $order=wc_get_order($record['wp_order_id']);if(!$order || $order->get_billing_email()!==$expected[$language])throw new RuntimeException('Unexpected recipient/order');
        $notes=wc_get_order_notes(['order_id'=>$order->get_id(),'type'=>'internal']);
        $result['orders'][$language]=['wp_order_id'=>$order->get_id(),'recipient'=>$order->get_billing_email(),'status'=>$order->get_status(),
            'receipt'=>$order->get_meta(WPHub_Documents::META,true),'private_document_notes'=>array_values(array_map(fn($note)=>$note->content,array_filter($notes,fn($note)=>str_contains($note->content,'WP Hub documents sent.'))))];
    }
} finally {$wpdb->query('ROLLBACK');}
file_put_contents($root.'/gmail-bridge-store-evidence.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "Two fictional store orders projected read-only; no mail sent.\n";
