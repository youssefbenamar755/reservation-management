<?php
/** Full actual Laravel service -> verified local TLS -> Woo REST journey. No external email. */
$options=getopt('', ['fixture:','phase:']);
$root=realpath((string)($options['fixture']??''));
$repo=dirname(__DIR__,2);
if(PHP_SAPI!=='cli' || !$root || !is_file($root.'/FICTIONAL-ONLY') || !is_file($root.'/private.json') || function_exists('mail')) {
    throw new RuntimeException('CLI fresh isolated fixture and native-mail disable required.');
}
$private=json_decode(file_get_contents($root.'/private.json'),true,flags:JSON_THROW_ON_ERROR);
if(($private['format']??'')!=='wphub-network-fixture-v1' || ($private['transport']??'')!=='capture' || ($private['origin']??'')!=='https://fixture.example.invalid') {
    throw new RuntimeException('Fixture manifest does not match capture-only network harness.');
}
// Windows sandbox may report false for native is_readable even when actual I/O works.
// Only this harness runs with that predicate disabled; no vendor implementation is edited.
if(!function_exists('is_readable')) {
    function is_readable($path): bool {
        global $root,$repo;
        if(!is_string($path)) return false;
        $normalized=strtolower(str_replace('\\','/',realpath($path)?:$path));
        $allowed=false;
        foreach([$root,$repo] as $directory) {
            if(str_starts_with($normalized,strtolower(str_replace('\\','/',$directory)).'/')) $allowed=true;
        }
        if(!$allowed) return false;
        if(is_dir($path)) {$stream=@opendir($path);if(!$stream)return false;closedir($stream);return true;}
        $stream=@fopen($path,'rb');if(!$stream)return false;fclose($stream);return true;
    }
}
foreach(glob($repo.'/config/*.php') as $file) {
    preg_match_all('/\benv\([\'\"]([A-Z][A-Z0-9_]*)[\'\"]/',file_get_contents($file),$matches);
    foreach($matches[1] as $name) {putenv($name);unset($_ENV[$name],$_SERVER[$name]);}
}
$env=['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>$private['key'],'APP_URL'=>'https://app.example.invalid',
    'APP_CONFIG_CACHE'=>$root.'/app/absent-config.php','APP_ROUTES_CACHE'=>$root.'/app/absent-routes.php','APP_EVENTS_CACHE'=>$root.'/app/absent-events.php',
    'APP_SERVICES_CACHE'=>$root.'/app/cache/services.php','APP_PACKAGES_CACHE'=>$root.'/app/cache/packages.php',
    'DB_CONNECTION'=>'sqlite','DB_DATABASE'=>$root.'/app/fixture.sqlite','DB_URL'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array',
    'MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'database','BROADCAST_CONNECTION'=>'null','FILESYSTEM_DISK'=>'local','WPHUB_DOCUMENT_DISK'=>'local',
    'WPHUB_VALIDATION_MODE'=>'true','WPHUB_VALIDATION_MAIL_TRANSPORT'=>'capture','WPHUB_VALIDATION_RECIPIENTS'=>'fixture-recipient@example.invalid',
    'WPHUB_VALIDATION_STORE_ORIGINS'=>'https://fixture.example.invalid','NIGHTWATCH_ENABLED'=>'false','NIGHTWATCH_TOKEN'=>'',
    'AWS_ACCESS_KEY_ID'=>'','AWS_SECRET_ACCESS_KEY'=>'','AWS_BUCKET'=>'','MAIL_USERNAME'=>'','MAIL_PASSWORD'=>''];
foreach($env as $name=>$value) {putenv($name.'='.$value);$_ENV[$name]=$_SERVER[$name]=$value;}
require $repo.'/vendor/autoload.php';
$app=require $repo.'/bootstrap/app.php';
$app->addAbsoluteCachePathPrefix(substr($root,0,2));
$app->useEnvironmentPath($root.'/app')->loadEnvironmentFrom('absent.env');
$app->useStoragePath($root.'/app/storage');
$kernel=$app->make(\Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
if(config('database.connections.sqlite.database')!==$root.'/app/fixture.sqlite' || !app(\App\Services\DocumentValidationSafety::class)->capture()) {
    throw new RuntimeException('Isolated app preflight failed.');
}
// Harness-only resolver and trust-root override. Production URL/receipt/completion methods unchanged.
class NetworkFixtureGateway extends \App\Services\DocumentStoreGateway {
    public function __construct(private string $ca) {}
    protected function resolvePublicAddresses(string $host): array {
        if($host!=='fixture.example.invalid') throw new RuntimeException('Harness refuses every other store host.');
        return ['127.0.0.1'];
    }
    protected function client(\App\Models\Website $website,?string $identity): \Illuminate\Http\Client\PendingRequest {
        return parent::client($website,$identity)->withOptions(['verify'=>$this->ca,'curl'=>[CURLOPT_CONNECT_TO=>['fixture.example.invalid:443:127.0.0.1:8443']]]);
    }
}
$gateway=new NetworkFixtureGateway($root.'/ca.pem');
$app->instance(\App\Services\DocumentStoreGateway::class,$gateway);
function networkFixtureHttp(string $method,string $path,array $body=[],bool $valid=true): \Illuminate\Http\Client\Response {
    global $root,$private;
    return \Illuminate\Support\Facades\Http::acceptJson()->asJson()->withBasicAuth($valid?$private['consumer_key']:'ck_invalid',$valid?$private['consumer_secret']:'cs_invalid')
        ->withHeaders(['X-WPHub-Website'=>$private['pairing']])->withOptions(['verify'=>$root.'/ca.pem','proxy'=>'','curl'=>[CURLOPT_RESOLVE=>['fixture.example.invalid:443:127.0.0.1'],CURLOPT_CONNECT_TO=>['fixture.example.invalid:443:127.0.0.1:8443']]])
        ->withoutRedirecting()->connectTimeout(3)->timeout(20)->send($method,'https://fixture.example.invalid/wp-json/wc/v3/'.$path,$body?['json'=>$body]:[]);
}
$checks=[];
function checkJourney(bool $condition,string $name): void {
    global $checks;$checks[]=['check'=>$name,'passed'=>$condition];
    if(!$condition) throw new RuntimeException($name);
}
if(($options['phase']??'')==='setup') {
    if(\Illuminate\Support\Facades\Schema::hasTable('users')) throw new RuntimeException('Setup requires a fresh empty app database.');
    if($kernel->call('migrate',['--force'=>true])!==0) throw new RuntimeException('Fixture migrations failed');
    $actor=\App\Models\User::create(['name'=>'Fictional network operator','email'=>'operator@example.invalid','password'=>bin2hex(random_bytes(24)),'is_admin'=>false]);
    $site=\App\Models\Website::create(['user_id'=>$actor->id,'name'=>'FICTIONAL Network Brand','slug'=>'fictional-network','status'=>'active','base_url'=>$private['origin'],
        'wc_consumer_key'=>$private['consumer_key'],'wc_consumer_secret'=>$private['consumer_secret']]);
    \App\Models\GmailAppSetting::create(['id'=>1,'client_id'=>'fictional-capture-client','client_secret'=>'fictional-capture-secret']);
    $gmail=\App\Models\GmailConnection::create(['user_id'=>$actor->id,'email'=>'sender@example.invalid','connection_key'=>(string)\Illuminate\Support\Str::uuid(),
        'app_fingerprint'=>app(\App\Services\GmailClient::class)->fingerprint(),'access_token'=>'fictional-not-a-token','refresh_token'=>'fictional-not-a-token',
        'connected_at'=>now(),'expires_at'=>now()->addDay(),'aliases'=>[['email'=>'sender@example.invalid','name'=>'FICTIONAL Brand']]]);
    $profile=\App\Models\WebsiteDocumentProfile::create(['website_id'=>$site->id,'configured_by_user_id'=>$actor->id,'brand_name'=>'FICTIONAL Network Brand',
        'logo_url'=>'https://fixture.example.invalid/logo.png','primary_color'=>'#143e50','accent_color'=>'#248070','background_color'=>'#f5f7fa','text_color'=>'#102030',
        'support_email'=>'support@example.invalid','website_url'=>$private['origin'],'footer'=>'Fictional validation only; no reservation, transaction or travel document.',
        'gmail_connection_id'=>$gmail->id,'sender_email'=>'sender@example.invalid','sender_name'=>'FICTIONAL Network Brand','reply_to'=>'support@example.invalid',
        'default_language'=>'en','templates'=>\App\Services\BrandedDocumentRenderer::defaults(),'service_label'=>'fictional samples','required_services'=>['Flight'],
        'workflow_enabled'=>true,'config_version'=>1,'store_pairing_key'=>$private['pairing']]);
    $unauthorized=networkFixtureHttp('GET','wphub-documents/health',[],false);
    checkJourney(in_array($unauthorized->status(),[401,403],true),'Actual HTTP Basic authentication rejects invalid Woo credentials');
    $brand=$gateway->syncBrand($profile);
    checkJourney(($brand['website_identity']??'')===$private['pairing'],'Actual authenticated TLS brand publish returns exact pairing');
    $health=$gateway->health($site);
    checkJourney($health['verified']&&$health['supported']&&$health['enabled'],'Actual Woo health verifies paired companion and transactional capabilities');
    $profile->update(['store_capability'=>$health,'store_checked_at'=>now(),'brand_sync_version'=>1]);
    file_put_contents($root.'/setup-evidence.json',json_encode($checks,JSON_PRETTY_PRINT));
    echo json_encode(['status'=>'isolated_setup_verified','checks'=>$checks,'real_emails_sent'=>0],JSON_PRETTY_PRINT)."\n";exit;
}
if(($options['phase']??'')!=='journey') throw new RuntimeException('Choose setup or journey phase');
$actor=\App\Models\User::sole();$site=\App\Models\Website::sole();
$ids=json_decode(file_get_contents($root.'/orders.json'),true);
$pdf=new \Dompdf\Dompdf;$pdf->set_option('isRemoteEnabled',false);
$pdf->loadHtml('<h1>Fictional network fixture</h1><p>Not a reservation or travel document. Captured only.</p>');$pdf->render();
$bytes=$pdf->output();file_put_contents($root.'/fictional.pdf',$bytes);
$deliveryService=app(\App\Services\BrandedDocumentDelivery::class);
$captures=[];$details=[];
foreach($ids as $label=>$id) {
    $response=networkFixtureHttp('GET','orders/'.$id);
    checkJourney($response->successful(),'Actual authenticated order fetch: '.$label);
    $order=\App\Models\WcOrder::withoutEvents(fn()=>app(\App\Services\WooCommerceOrderStore::class)->store($site->id,$response->json())['order']);
    $document=app(\App\Services\OrderDocumentStorage::class)->upload($order,$actor,[new \Illuminate\Http\UploadedFile($root.'/fictional.pdf','Fictional sample '.$label.'.pdf','application/pdf',null,true)],$private['recipient'],true)[0];
    checkJourney($document['sha256']===hash('sha256',$bytes),'Approved private PDF hash matches generated sample: '.$label);
    $delivery=$deliveryService->prepare($order,$actor,['mode'=>'complete','document_ids'=>[$document['id']],'recipient'=>$private['recipient'],
        'fulfilled_services'=>['Flight'],'fulfillment_confirmed'=>true,'language'=>str_ends_with($label,'fr')?'fr':'en']);
    checkJourney($delivery->status==='prepared','Full service saves reviewed immutable MIME: '.$label);
    if($label==='stale_preview_en') {
        $changed=networkFixtureHttp('PUT','orders/'.$id,['billing'=>['first_name'=>'Changed FICTIONAL name']]);
        checkJourney($changed->successful(),'Real Woo order changed after app preview');
    }
    $sent=$deliveryService->send($order,$delivery,$actor);
    if($label==='stale_preview_en') {
        checkJourney($sent->status==='failed' && !$sent->gmail_message_id,'Real order revision change blocks capture before transmission');
        $details[$label]=['delivery_status'=>$sent->status,'sync_status'=>$sent->sync_status,'send_attempts'=>$sent->send_attempts];
        continue;
    }
    checkJourney($sent->status==='sent' && str_starts_with($sent->gmail_message_id,'capture_'),'Actual send service captures one MIME without Gmail request: '.$label);
    $capture=config('document_delivery.validation.capture_path').'/'.$sent->gmail_message_id.'.eml';
    checkJourney(is_file($capture),'Private MIME capture exists: '.$label);$captures[]=$capture;
    checkJourney(\Illuminate\Support\Facades\DB::table('jobs')->where('queue','documents')->count()===1,'Completion enters actual dedicated database queue: '.$label);
    if($label==='lost_response_fr') file_put_contents($root.'/fault-once.json',json_encode(['path'=>'/wp-json/wc/v3/wphub-documents/orders/'.$id.'/complete']));
    $result=$kernel->call('queue:work',['connection'=>'documents','--queue'=>'documents','--stop-when-empty'=>true,'--tries'=>1,'--timeout'=>90]);
    checkJourney($result===0,'Actual database queue worker exits successfully: '.$label);
    $sent->refresh();
    if($label==='lost_response_fr') {
        checkJourney($sent->sync_status==='pending','Lost response after real store completion retains pending synchronization');
        $remote=networkFixtureHttp('GET','orders/'.$id);
        checkJourney($remote->json('status')==='completed','Woo completion really committed before injected response loss');
        $sent=app(\App\Services\DocumentCompletionSync::class)->sync($sent->id);
    }
    checkJourney($sent->sync_status==='completed' && $order->fresh()->status==='completed','Receipt verification and completed state return to WP Hub: '.$label);
    $again=$deliveryService->send($order->fresh(),$sent,$actor);
    checkJourney($again->send_attempts===1,'Duplicate Send invocation does not recapture MIME: '.$label);
    $before=$sent->sync_attempts;
    $again=app(\App\Services\DocumentCompletionSync::class)->sync($sent->id);
    checkJourney($again->sync_attempts===$before,'Completed synchronization is idempotent: '.$label);
    $details[$label]=['delivery_id'=>$sent->id,'delivery_status'=>$sent->status,'sync_status'=>$sent->sync_status,'send_attempts'=>$sent->send_attempts,
        'sync_attempts'=>$sent->sync_attempts,'captured_mime_sha256'=>hash_file('sha256',$capture),'approved_pdf_sha256'=>hash('sha256',$bytes)];
}
checkJourney(count(glob(config('document_delivery.validation.capture_path').'/capture_*.eml'))===2,'Exactly two local MIME captures; stale preview produces none');
checkJourney(\Illuminate\Support\Facades\DB::table('jobs')->count()===0,'Dedicated database queue drained with no unrelated queued jobs');
\App\Models\WebsiteDocumentProfile::sole()->update(['workflow_enabled'=>false]);
$gateway->syncBrand(\App\Models\WebsiteDocumentProfile::sole());
checkJourney(!$gateway->health($site)['enabled'],'Fixture workflow explicitly disabled locally and on real test store after journey');
$report=['status'=>'local_tls_journey_verified','generated_at'=>gmdate(DATE_ATOM),'checks'=>$checks,'deliveries'=>$details,
    'laravel'=>$app->version(),'php'=>PHP_VERSION,'transport'=>'capture','recipient'=>'fixture-recipient@example.invalid','real_emails_sent'=>0,
    'local_tls'=>true,'local_tls_port'=>8443,'external_https'=>false,'cloud_storage'=>false,'google_aliases'=>'fictional capture-only fixtures, not Google verification',
    'harness_overrides'=>['DNS resolves only fixture.example.invalid to127.0.0.1','cURL CONNECT_TO maps original HTTPS443 to loopback8443 without changing request origin/SNI','Dedicated generated CA trusted only by harness client'],
    'pdf_sha256'=>hash('sha256',$bytes),'queue'=>'real database documents queue and worker','production_changes'=>false];
file_put_contents($root.'/journey-evidence.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
