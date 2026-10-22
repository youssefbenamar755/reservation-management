<?php
/** Explicitly approved fictional recipients only. No production or existing fixture writes. */
// Draft harness: configure/prepare do not send. Only an explicit --phase=send may transmit two saved previews.
$options=getopt('', ['fixture:','phase:']);
$root=realpath((string)($options['fixture']??''));$phase=$options['phase']??'';$repo=dirname(__DIR__,2);
if(PHP_SAPI!=='cli' || !$root || !is_file($root.'/FICTIONAL-ONLY') || !is_file($root.'/private.json') || function_exists('mail')
    || !in_array($phase,['configure','seed-store','prepare','send','evidence'],true)) throw new RuntimeException('Explicit new private fixture and valid phase required.');
$private=json_decode(file_get_contents($root.'/private.json'),true,flags:JSON_THROW_ON_ERROR);
if(($private['format']??'')!=='wphub-network-fixture-v1' || ($private['origin']??'')!=='https://fixture.example.invalid'
    || ($private['transport']??'')!=='capture') throw new RuntimeException('This bridge accepts only a new fictional network fixture kept in capture by default.');
$recipients=['youssefbenamar755@gmail.com','benamar-y14@outlook.com'];
if($phase==='seed-store') {
    if(!is_file($root.'/gmail-bridge-configured.json') || is_file($root.'/gmail-bridge-orders.json')) throw new RuntimeException('Configure once, then seed once. Existing orders are never recreated.');
    if(getenv('WPHUB_VALIDATION_MAIL_TRANSPORT')!=='capture' || explode(',',(string)getenv('WPHUB_VALIDATION_RECIPIENTS'))!==$recipients) throw new RuntimeException('Store must capture only to fixed approved recipients.');
    define('WPHUB_FIXTURE_ROOT',$root);require $repo.'/integrations/wphub-documents/tests/real/windows-readability.php';
    define('WP_USE_THEMES',false);define('WP_DISABLE_FATAL_ERROR_HANDLER',true);
    $_SERVER['HTTP_HOST']='fixture.example.invalid';$_SERVER['REQUEST_URI']='/';$_SERVER['HTTPS']='on';
    require $root.'/wordpress/wp-load.php';global $wpdb;
    if(DB_HOST!=='127.0.0.1:33177' || DB_NAME!=='wphub_fixture_hpos' || !str_starts_with(strtolower(str_replace('\\','/',$wpdb->get_var('SELECT @@datadir'))),strtolower(str_replace('\\','/',$root)).'/data/')) throw new RuntimeException('Unexpected store database.');
    update_option('admin_email',$recipients[0]);
    update_option('woocommerce_new_order_settings',array_merge((array)get_option('woocommerce_new_order_settings',[]),['recipient'=>$recipients[0]]));
    WC()->mailer();$orders=[];
    foreach(['en'=>$recipients[0],'fr'=>$recipients[1]] as $language=>$recipient) {
        $order=wc_create_order();$order->set_billing_email($recipient);$order->set_billing_first_name('Alex FICTIONAL');$order->set_billing_last_name('approved network journey '.$language);
        $order->set_currency('USD');$order->set_total('15.00');$order->set_date_paid(time()-600);$order->set_payment_method('fixture-no-gateway');$order->update_meta_data('_order_locale',$language);
        $item=new WC_Order_Item_Product();$item->set_name('FICTIONAL sample only, not a reservation');$item->set_quantity(1);$item->set_subtotal('15.00');$item->set_total('15.00');$order->add_item($item);
        $order->save();$order->update_status('processing');$orders[$language]=['wp_order_id'=>$order->get_id(),'recipient'=>$recipient];
    }
    file_put_contents($root.'/gmail-bridge-orders.json',json_encode($orders,JSON_PRETTY_PRINT));
    file_put_contents($root.'/gmail-bridge-store-acks.json',json_encode($GLOBALS['wphub_fixture_mail'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo json_encode(['phase'=>'seed-store','fictional_orders'=>2,'captured_store_notifications'=>count($GLOBALS['wphub_fixture_mail']),'real_emails_sent'=>0])."\n";exit;
}
if(!function_exists('is_readable')) {
    function is_readable($path): bool {
        global $root,$repo;if(!is_string($path))return false;
        $resolved=strtolower(str_replace('\\','/',realpath($path)?:$path));$allowed=false;
        foreach([$root,$repo] as $base) if(str_starts_with($resolved,strtolower(str_replace('\\','/',$base)).'/'))$allowed=true;
        if(!$allowed)return false;
        if(is_dir($path)){$stream=@opendir($path);if(!$stream)return false;closedir($stream);return true;}
        $stream=@fopen($path,'rb');if(!$stream)return false;fclose($stream);return true;
    }
}
foreach(glob($repo.'/config/*.php') as $file) {
    preg_match_all('/\benv\([\'\"]([A-Z][A-Z0-9_]*)[\'\"]/',file_get_contents($file),$matches);
    foreach($matches[1] as $name){putenv($name);unset($_ENV[$name],$_SERVER[$name]);}
}
$env=['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>$private['key'],'APP_URL'=>'https://app.example.invalid',
    'APP_CONFIG_CACHE'=>$root.'/app/absent-config.php','APP_ROUTES_CACHE'=>$root.'/app/absent-routes.php','APP_EVENTS_CACHE'=>$root.'/app/absent-events.php',
    'APP_SERVICES_CACHE'=>$root.'/app/cache/services.php','APP_PACKAGES_CACHE'=>$root.'/app/cache/packages.php',
    'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$root.'/app/fixture.sqlite','DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array',
    'MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'database','BROADCAST_CONNECTION'=>'null','FILESYSTEM_DISK'=>'local','WPHUB_DOCUMENT_DISK'=>'local',
    'WPHUB_VALIDATION_MODE'=>'true','WPHUB_VALIDATION_MAIL_TRANSPORT'=>$phase==='evidence'?'capture':'gmail',
    'WPHUB_VALIDATION_RECIPIENTS'=>implode(',',$recipients),'WPHUB_VALIDATION_STORE_ORIGINS'=>'https://fixture.example.invalid',
    'NIGHTWATCH_ENABLED'=>'false','NIGHTWATCH_TOKEN'=>'','AWS_ACCESS_KEY_ID'=>'','AWS_SECRET_ACCESS_KEY'=>'','AWS_BUCKET'=>'','MAIL_USERNAME'=>'','MAIL_PASSWORD'=>''];
foreach($env as $name=>$value){putenv($name.'='.$value);$_ENV[$name]=$_SERVER[$name]=$value;}
require $repo.'/vendor/autoload.php';$app=require $repo.'/bootstrap/app.php';$app->addAbsoluteCachePathPrefix(substr($root,0,2));
$app->useEnvironmentPath($root.'/app')->loadEnvironmentFrom('absent.env');$app->useStoragePath($root.'/app/storage');
$kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
if(config('database.connections.sqlite.database')!==$root.'/app/fixture.sqlite' || config('document_delivery.validation.recipients')!==$recipients) throw new RuntimeException('Isolated app guard failed.');
class NetworkGmailGateway extends \App\Services\DocumentStoreGateway {
    public function __construct(private string $ca){}
    protected function resolvePublicAddresses(string $host):array {if($host!=='fixture.example.invalid')throw new RuntimeException('Unexpected host');return ['127.0.0.1'];}
    protected function client(\App\Models\Website $website,?string $identity):\Illuminate\Http\Client\PendingRequest {
        return parent::client($website,$identity)->withOptions(['verify'=>$this->ca,'curl'=>[CURLOPT_CONNECT_TO=>['fixture.example.invalid:443:127.0.0.1:8443']]]);
    }
}
$gateway=new NetworkGmailGateway($root.'/ca.pem');$app->instance(\App\Services\DocumentStoreGateway::class,$gateway);
$actor=\App\Models\User::sole();$site=\App\Models\Website::sole();$profile=\App\Models\WebsiteDocumentProfile::sole();
if($site->base_url!==$private['origin'] || $profile->store_pairing_key!==$private['pairing'])throw new RuntimeException('Exact fictional site mismatch');
function bridgeRequest(string $method,string $path,array $body=[]):\Illuminate\Http\Client\Response {
    global $root,$private;
    return \Illuminate\Support\Facades\Http::acceptJson()->asJson()->withBasicAuth($private['consumer_key'],$private['consumer_secret'])
        ->withHeaders(['X-WPHub-Website'=>$private['pairing']])->withOptions(['verify'=>$root.'/ca.pem','proxy'=>'','curl'=>[CURLOPT_CONNECT_TO=>['fixture.example.invalid:443:127.0.0.1:8443']]])
        ->withoutRedirecting()->timeout(20)->connectTimeout(3)->send($method,'https://fixture.example.invalid/wp-json/wc/v3/'.$path,$body?['json'=>$body]:[]);
}
if($phase==='configure') {
    if(is_file($root.'/gmail-bridge-configured.json') || \App\Models\OrderEmailDelivery::count()!==0 || \App\Models\WcOrder::count()!==0) throw new RuntimeException('Configure requires unused freshly set up target app. Never use a previous fixture.');
    // Exact prior consented local test fixture ONLY. No production config/database is accepted.
    $source=dirname($repo,2).'/outputs/wphub-local-gmail-4483c123ccd53998';
    try {
        $manifest=json_decode(file_get_contents($source.'/fixture.json'),true,flags:JSON_THROW_ON_ERROR);
        if(($manifest['format']??'')!=='wphub-local-gmail-fixture-v1' || ($manifest['origin']??'')!=='http://localhost:8799' || ($manifest['transport']??'')!=='capture') throw new RuntimeException;
        $sourceDb=new PDO('sqlite:'.$source.'/fixture.sqlite');$sourceDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$sourceDb->exec('PRAGMA query_only = ON');
        $settings=$sourceDb->query('SELECT client_id, client_secret FROM gmail_app_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $rows=$sourceDb->query("SELECT email, access_token, refresh_token, app_fingerprint, expires_at, connected_at FROM gmail_connections WHERE email='visabookinghelp@gmail.com' AND connected_at IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==1 || ($settings['client_id']??'')!=='677430872479-6ccjjtfhi4mmu12ccobj89ht9fus26b9.apps.googleusercontent.com') throw new RuntimeException;
        $decrypt=new \Illuminate\Encryption\Encrypter(base64_decode(substr($manifest['key'],7),true),'AES-256-CBC');
        $secret=$decrypt->decryptString($settings['client_secret']);$access=$decrypt->decryptString($rows[0]['access_token']);$refresh=$decrypt->decryptString($rows[0]['refresh_token']);
        if(!$access || !$refresh || !hash_equals(hash('sha256',$settings['client_id'].':'.$secret),$rows[0]['app_fingerprint']))throw new RuntimeException;
        \App\Models\GmailAppSetting::findOrFail(1)->update(['client_id'=>$settings['client_id'],'client_secret'=>$secret]);
        $gmail=\App\Models\GmailConnection::sole();
        $gmail->update(['email'=>'visabookinghelp@gmail.com','app_fingerprint'=>app(\App\Services\GmailClient::class)->fingerprint(),
            'access_token'=>$access,'refresh_token'=>$refresh,'expires_at'=>$rows[0]['expires_at'],'connected_at'=>$rows[0]['connected_at'],'aliases'=>[]]);
        unset($manifest,$sourceDb,$settings,$rows,$secret,$access,$refresh,$decrypt);
    } catch(\Throwable) {fwrite(STDERR,"Private test credential import failed; no secret values were printed.\n");exit(1);}
    $aliases=app(\App\Services\GmailClient::class)->aliases($gmail->fresh());
    if(!collect($aliases)->contains(fn($alias)=>($alias['email']??'')==='visabookinghelp@gmail.com'))throw new RuntimeException('Approved real test sender is not verified');
    $templates=\App\Services\BrandedDocumentRenderer::defaults();
    foreach($templates as &$languages)foreach($languages as $lang=>&$template){$template['subject']='[TEST] FICTIONAL connected store journey — '.$template['subject'];$template['body']=($lang==='fr'?'TEST FICTIF : aucune réservation, aucun achat réel.':'FICTIONAL TEST: no real reservation or purchase.')."\n\n".$template['body'];}unset($languages,$template);
    $profile->update(['sender_email'=>'visabookinghelp@gmail.com','reply_to'=>'visabookinghelp@gmail.com','sender_name'=>'FICTIONAL WP Hub connected test',
        'brand_name'=>'FICTIONAL connected store journey','templates'=>$templates,'workflow_enabled'=>true,'config_version'=>$profile->config_version+1]);
    $gateway->syncBrand($profile->fresh());$health=$gateway->health($site);
    if(!$health['verified'] || !$health['enabled'])throw new RuntimeException('Paired test store not ready');
    $profile->update(['store_capability'=>$health,'store_checked_at'=>now(),'brand_sync_version'=>$profile->config_version]);
    file_put_contents($root.'/gmail-bridge-configured.json',json_encode(['source_fixture_access'=>'query-only credential read; no before/after hash baseline','target'=>'new isolated encrypted fixture database','sender'=>'visabookinghelp@gmail.com','recipients'=>$recipients,'real_emails_sent'=>0],JSON_PRETTY_PRINT));
    echo "New test fixture configured with genuinely verified sender; no email sent.\n";exit;
}
if(!is_file($root.'/gmail-bridge-orders.json'))throw new RuntimeException('Approved fictional store orders required');
$orders=json_decode(file_get_contents($root.'/gmail-bridge-orders.json'),true,flags:JSON_THROW_ON_ERROR);
if(array_keys($orders)!==['en','fr'] || array_column($orders,'recipient')!==$recipients)throw new RuntimeException('Exact two-recipient fictional order scope required');
$service=app(\App\Services\BrandedDocumentDelivery::class);
if($phase==='prepare') {
    if(is_file($root.'/gmail-bridge-prepared.json') || \App\Models\OrderEmailDelivery::count()!==0)throw new RuntimeException('Previews already exist; do not recreate or automatically resend');
    $prepared=[];
    foreach($orders as $language=>$record) {
        $remote=bridgeRequest('GET','orders/'.$record['wp_order_id']);
        if(!$remote->successful() || $remote->json('billing.email')!==$record['recipient'])throw new RuntimeException('Exact fictional order customer mismatch');
        $order=\App\Models\WcOrder::withoutEvents(fn()=>app(\App\Services\WooCommerceOrderStore::class)->store($site->id,$remote->json())['order']);
        $documents=[];
        for($index=1;$index<=($language==='en'?1:2);$index++) {
            $pdf=new \Dompdf\Dompdf;$pdf->set_option('isRemoteEnabled',false);$pdf->loadHtml('<h1>FICTIONAL WP Hub connected journey</h1><p>Sample '.$index.'. Not a reservation or travel document. No real transaction.</p>');$pdf->render();
            $file=$root.'/approved-sample-'.$language.'-'.$index.'.pdf';file_put_contents($file,$pdf->output());
            $documents[]=app(\App\Services\OrderDocumentStorage::class)->upload($order,$actor,[new \Illuminate\Http\UploadedFile($file,'Fictional-network-'.$language.'-'.$index.'.pdf','application/pdf',null,true)],$record['recipient'],true)[0];
        }
        $delivery=$service->prepare($order,$actor,['mode'=>'complete','document_ids'=>array_column($documents,'id'),'recipient'=>$record['recipient'],'fulfilled_services'=>['Flight'],'fulfillment_confirmed'=>true,'language'=>$language]);
        $snapshot=$delivery->snapshot;
        if(!str_starts_with($snapshot['subject'],'[TEST]') || $snapshot['sender']['email']!=='visabookinghelp@gmail.com')throw new RuntimeException('Fictional subject or sender guard failed');
        // Fictional reviewed MIME only; contains no OAuth or customer secrets. Required for later attachment byte matching.
        file_put_contents($root.'/reviewed-'.$language.'.eml',$delivery->mime);
        $prepared[$language]=['delivery_id'=>$delivery->id,'order_id'=>$order->id,'wp_order_id'=>$record['wp_order_id'],'recipient'=>$record['recipient'],
            'sender'=>$snapshot['sender']['email'],'subject'=>$snapshot['subject'],'pdfs'=>array_map(fn($d)=>array_intersect_key($d,array_flip(['name','sha256','size','version'])),$documents)];
    }
    file_put_contents($root.'/gmail-bridge-prepared.json',json_encode($prepared,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    echo json_encode(['phase'=>'prepared','real_emails_sent'=>0,'previews'=>$prepared],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";exit;
}
if(!is_file($root.'/gmail-bridge-prepared.json'))throw new RuntimeException('Reviewable saved previews are required before sending');
$prepared=json_decode(file_get_contents($root.'/gmail-bridge-prepared.json'),true,flags:JSON_THROW_ON_ERROR);
$evidence=[];
try {
    foreach($prepared as $language=>$record) {
        $order=\App\Models\WcOrder::findOrFail($record['order_id']);$delivery=\App\Models\OrderEmailDelivery::findOrFail($record['delivery_id']);
        if($order->customer_email!==$orders[$language]['recipient'] || $delivery->snapshot['recipient']!==$order->customer_email || $delivery->snapshot['sender']['email']!=='visabookinghelp@gmail.com')throw new RuntimeException('Saved recipient/sender mismatch');
        if($phase==='send') {
            if(!in_array($delivery->status,['prepared','sent'],true))throw new RuntimeException('Failed or uncertain delivery needs manual review; no automatic retry');
            if($delivery->status==='prepared')$delivery=$service->send($order,$delivery,$actor);
            if($delivery->status!=='sent' || !$delivery->gmail_message_id || str_starts_with($delivery->gmail_message_id,'capture_'))throw new RuntimeException('Gmail acceptance not confirmed; inspect isolated delivery evidence before continuing');
            if($kernel->call('queue:work',['connection'=>'documents','--queue'=>'documents','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>90])!==0)throw new RuntimeException('Dedicated completion worker failed; never resend email');
            $delivery->refresh();
            if($delivery->sync_status!=='completed')throw new RuntimeException('Email accepted; order completion pending. Sync-only review required.');
        }
        $evidence[$language]=$record+['status'=>$delivery->status,'sync_status'=>$delivery->sync_status,'provider_message_id'=>$delivery->gmail_message_id,
            'sent_at'=>$delivery->sent_at?->toIso8601String(),'send_attempts'=>$delivery->send_attempts,'sync_attempts'=>$delivery->sync_attempts,'local_order_status'=>$order->fresh()->status];
    }
} finally {
    if($phase==='send') {
        $profile->refresh()->update(['workflow_enabled'=>false]);
        try{$gateway->syncBrand($profile->fresh());}catch(\Throwable){fwrite(STDERR,"Fixture app disabled; remote disabled-state publish needs checking.\n");}
        // Runtime transport is never persisted as Gmail; all fixture processes stop after this explicit command.
        config(['document_delivery.validation.mail_transport'=>'capture']);
    }
    file_put_contents($root.'/gmail-bridge-evidence.json',json_encode(['phase'=>$phase,'transport_after'=>'capture default','fixture_origin'=>'local TLS only','source_8799_access'=>'query-only credential read; no before/after hash baseline',
        'production_changes'=>false,'inbox_receipt_verified'=>false,'received_pdf_hashes_verified'=>false,'deliveries'=>$evidence],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}
echo json_encode(['phase'=>$phase,'deliveries'=>$evidence],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
