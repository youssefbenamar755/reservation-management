<?php
/** Runs real WordPress/WooCommerce against a wholly new local fixture, never an existing installation. */
if (PHP_SAPI !== 'cli' || getenv('WPHUB_VALIDATION_MODE') !== 'true' || getenv('WPHUB_VALIDATION_MAIL_TRANSPORT') !== 'capture') {
    throw new RuntimeException('Only CLI capture-mode validation is allowed.');
}
$fixture = realpath((string) getenv('WPHUB_FIXTURE_ROOT'));
$mode = $argv[1] ?? '';
if (! $fixture || ! is_file($fixture.'/FICTIONAL-ONLY') || ! in_array($mode, ['hpos', 'legacy'], true)) {
    throw new RuntimeException('An explicitly initialized fictional fixture and hpos/legacy mode are required.');
}
define('WPHUB_FIXTURE_ROOT', $fixture);
if (DIRECTORY_SEPARATOR === '\\') {
    require __DIR__.'/windows-readability.php';
}
define('WP_USE_THEMES', false);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $fixture.'/wordpress/wp-load.php';
global $wpdb;
$expectedDataDirectory = strtolower(rtrim(str_replace('\\', '/', $fixture), '/').'/data/');
$actualDataDirectory = strtolower(rtrim(str_replace('\\', '/', (string) $wpdb->get_var('SELECT @@datadir')), '/').'/');
$fixtureOrigins = ['http://127.0.0.1', 'https://fixture.example.invalid'];
if (DB_HOST !== '127.0.0.1:33177' || DB_NAME !== 'wphub_fixture_'.$mode
    || $actualDataDirectory !== $expectedDataDirectory
    || ! in_array(get_option('home'), $fixtureOrigins, true)
    || ! in_array(get_option('siteurl'), $fixtureOrigins, true)) {
    throw new RuntimeException('Fixture database guard rejected an unexpected database.');
}
if (getenv('WPHUB_FIXTURE_EMAIL_IMPROVEMENTS') === 'yes') {
    update_option('woocommerce_feature_email_improvements_enabled', 'yes');
}
wp_set_current_user(1);
WC_Install::install();
WC_Install::create_roles();
wp_roles()->for_site();
WC_Install::create_tables();
wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class)->create_database_tables();
WC()->mailer();
wp_set_current_user(0);
clean_user_cache(1);
wp_set_current_user(1);

$checks = [];
function verify(bool $condition, string $message): void {
    $GLOBALS['checks'][] = ['test' => $message, 'passed' => $condition];
    if (! $condition) {
        throw new RuntimeException($message);
    }
}
function api(string $method, string $path, array $body = [], string $identity = 'b2648371-c9ab-45a2-bc03-7b36df8b190e'): WP_REST_Response {
    $request = new WP_REST_Request($method, '/wc/v3/wphub-documents'.$path);
    $request->set_header('X-WPHub-Website', $identity);
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode($body));
    $response = rest_do_request($request);
    $GLOBALS['wphub_fixture_api'][] = ['method' => $method, 'path' => $path, 'status' => $response->get_status(), 'response' => $response->get_data()];
    file_put_contents(WPHUB_FIXTURE_ROOT.'/api-events-'.getenv('WPHUB_FIXTURE_STORAGE').'.json', json_encode($GLOBALS['wphub_fixture_api'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $response;
}
function fictional_order(string $language = 'en', bool $paid = true): WC_Order {
    $order = wc_create_order();
    $order->set_billing_email('fixture-recipient@example.invalid');
    $order->set_billing_first_name('Alex Fictional Long Customer');
    $order->set_currency('USD');
    $order->set_total('15.00');
    $item = new WC_Order_Item_Product();
    $item->set_name('Fictional reservation document');
    $item->set_quantity(1);
    $item->set_subtotal('15.00');
    $item->set_total('15.00');
    $order->add_item($item);
    $order->set_payment_method('fixture-manual-no-gateway');
    $order->update_meta_data('_order_locale', $language);
    if ($paid) {
        $order->set_date_paid(time() - 600);
    }
    $order->save();
    $order->update_status($paid ? 'processing' : 'on-hold');
    return wc_get_order($order->get_id());
}
function proof(WC_Order $order): array {
    $eligibility = api('GET', '/orders/'.$order->get_id().'/eligibility');
    verify($eligibility->get_status() === 200 && preg_match('/^[a-f0-9]{64}$/', $eligibility->get_data()['fulfillment_revision'] ?? '') === 1, 'Actual companion eligibility supplies a strong order revision');
    return ['receipt_id' => wp_generate_uuid4(), 'provider_message_id' => 'fixture-provider-proof-no-real-email',
        'sent_at' => gmdate(DATE_ATOM), 'document_set_version' => hash('sha256', 'fictional-approved-pdfs'),
        'fulfillment_confirmed' => true, 'attachment_count' => 2, 'config_version' => '1',
        'expected_modified_gmt' => gmdate('Y-m-d\TH:i:s', $order->get_date_modified()->getTimestamp()),
        'expected_fulfillment_revision' => $eligibility->get_data()['fulfillment_revision']];
}

try {
    global $wpdb;
    verify(in_array(get_option('home'), $fixtureOrigins, true), 'Site origin is explicitly fictional and no HTTP server is started');
    verify($actualDataDirectory === $expectedDataDirectory, 'Database is the exact explicitly selected new isolated data directory');
    verify(! function_exists('mail'), 'PHP mail function is disabled as a transport backstop');
    verify(DISABLE_WP_CRON && get_option('active_plugins') === ['woocommerce/woocommerce.php', 'wphub-documents/wphub-documents.php'], 'Only the two explicitly installed test plugins are active and automatic cron is disabled');
    verify(count(WC()->payment_gateways()->payment_gateways()) === 0, 'All payment gateways are disabled');
    verify(is_wp_error(wp_remote_get('https://example.invalid')), 'External HTTP is blocked before any integration test');
    try {
        wp_mail('not-approved@example.invalid', 'Must be blocked', 'No delivery');
        verify(false, 'Unapproved recipients rejected');
    } catch (RuntimeException $exception) {
        verify(str_contains($exception->getMessage(), 'allowlist'), 'Unapproved recipients rejected');
    }
    $hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    verify($hpos === ($mode === 'hpos'), 'Requested order storage mode is actually active');
    verify(get_option('woocommerce_custom_orders_table_data_sync_enabled') === 'no', 'HPOS compatibility synchronization is disabled');
    update_option(WPHub_Documents::OPTION, ['website_identity' => 'b2648371-c9ab-45a2-bc03-7b36df8b190e', 'enabled' => false]);
    $brand = ['enabled' => true, 'config_version' => '1', 'brand_name' => 'Fictional Brand', 'sender_name' => 'Fictional Documents',
        'support_email' => 'support@example.invalid', 'reply_to' => 'support@example.invalid', 'service_label' => 'reservation documents',
        'website_url' => 'https://example.invalid', 'logo_url' => '', 'primary_color' => '#123456', 'accent_color' => '#654321',
        'background_color' => '#f5f5f5', 'text_color' => '#222222', 'default_language' => 'en', 'footer' => 'Fictional validation only', 'templates' => []];
    verify(current_user_can('manage_woocommerce'), 'Fixture manager has the normal installed WooCommerce role capability');
    $wrongPairing = api('GET', '/health', [], 'wrong-pairing');
    verify($wrongPairing->get_status() === 403 && ($wrongPairing->get_data()['code'] ?? '') === 'wphub_pairing', 'Wrong website pairing is rejected by the actual pairing check');
    verify(api('PUT', '/brand', $brand)->get_status() === 200, 'Brand is saved through real WordPress REST dispatch');
    $health = api('GET', '/health')->get_data();
    file_put_contents($fixture.'/table-engines-'.$mode.'.json', json_encode($wpdb->get_results('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()', ARRAY_A), JSON_PRETTY_PRINT));
    verify($health['requirements']['compatible'] === true, 'Real InnoDB and WooCommerce compatibility is verified');
    $GLOBALS['wphub_fixture_mail'] = [];
    $order = fictional_order();
    file_put_contents($fixture.'/captured-paid-'.$mode.'.json', json_encode($GLOBALS['wphub_fixture_mail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $orderMail = array_values(array_filter($GLOBALS['wphub_fixture_mail'], fn ($mail) => str_contains($mail['subject'], 'Fictional Brand')));
    verify(count($orderMail) === 1, 'Paid order generates exactly one customer acknowledgement');
    verify(str_contains($orderMail[0]['message'], 'separate email') && str_contains($orderMail[0]['message'], 'Your payment has been received'), 'Paid acknowledgement explains separate document delivery');
    verify(str_contains($orderMail[0]['headers'], 'Reply-To: support@example.invalid'), 'Real acknowledgement uses configured Reply-To');
    verify(empty($orderMail[0]['attachments']), 'Acknowledgement has no premature document attachments');
    file_put_contents($fixture.'/acknowledgement-'.$mode.'-en.html', $orderMail[0]['message']);
    $GLOBALS['wphub_fixture_mail'] = [];
    $pending = fictional_order('fr', false);
    $pendingMail = array_values(array_filter($GLOBALS['wphub_fixture_mail'], fn ($mail) => str_contains($mail['subject'], 'Fictional Brand')));
    verify(count($pendingMail) === 1, 'Unpaid order generates one acknowledgement');
    verify(str_contains($pendingMail[0]['message'], 'Le paiement n’est pas encore confirmé') && ! str_contains($pendingMail[0]['message'], 'Votre paiement a bien été reçu'), 'French pending acknowledgement never claims payment');
    file_put_contents($fixture.'/acknowledgement-'.$mode.'-fr-pending.html', $pendingMail[0]['message']);
    $GLOBALS['wphub_fixture_mail'] = [];
    fictional_order('fr', true);
    $frenchMail = array_values(array_filter($GLOBALS['wphub_fixture_mail'], fn ($mail) => str_contains($mail['subject'], 'Fictional Brand')));
    verify(count($frenchMail) === 1 && str_contains($frenchMail[0]['message'], 'Votre paiement a bien été reçu'), 'French paid acknowledgement uses the recorded order language');
    file_put_contents($fixture.'/acknowledgement-'.$mode.'-fr.html', $frenchMail[0]['message']);
    $proof = proof($order);
    $GLOBALS['wphub_fixture_mail'] = [];
    verify(api('POST', '/orders/'.$order->get_id().'/complete', ['receipt_id' => $proof['receipt_id']])->get_status() === 409, 'Completion without receipt is rejected');
    $receipt = api('POST', '/orders/'.$order->get_id().'/receipt', $proof);
    verify($receipt->get_status() === 200 && $receipt->get_data()['verified'], 'Full receipt persists using real WooCommerce CRUD');
    verify(api('GET', '/orders/'.$order->get_id().'/receipt')->get_data()['verified'], 'Separate REST read verifies persisted receipt');
    verify(wc_get_order($order->get_id())->get_status() === 'processing', 'Receipt creation leaves order processing');
    verify(gmdate('Y-m-d\TH:i:s', wc_get_order($order->get_id())->get_date_modified()->getTimestamp()) === $proof['expected_modified_gmt'], 'Receipt metadata preserves optimistic modification timestamp');
    $observed = false;
    add_action('woocommerce_order_status_completed', static function ($id) use ($order, &$observed) {
        if ($id === $order->get_id()) {
            $reloaded = wc_get_order($id);
            $reloaded->read_meta_data(true);
            $observed = ! empty($reloaded->get_meta(WPHub_Documents::META, true)['complete']);
        }
    }, 1);
    $complete = api('POST', '/orders/'.$order->get_id().'/complete', ['receipt_id' => $proof['receipt_id']]);
    verify($complete->get_status() === 200 && $complete->get_data()['status'] === 'completed', 'Managed order completes using real WooCommerce status transition');
    verify($observed, 'Persisted suppression marker is visible before completion hooks');
    verify(count($GLOBALS['wphub_fixture_mail']) === 0, 'Managed completion and private note produce no customer email');
    $notes = wc_get_order_notes(['order_id' => $order->get_id(), 'type' => 'internal']);
    $proofNotes = array_filter($notes, fn ($note) => str_contains($note->content, 'WP Hub documents sent'));
    verify(count($proofNotes) === 1, 'Exactly one private fulfillment note exists');
    verify(api('POST', '/orders/'.$order->get_id().'/receipt', $proof)->get_status() === 200
        && api('POST', '/orders/'.$order->get_id().'/complete', ['receipt_id' => $proof['receipt_id']])->get_status() === 200, 'Repeated receipt/completion safely reconcile without another transition');
    verify(count($GLOBALS['wphub_fixture_mail']) === 0, 'Sync retry produces no email');
    $notes = wc_get_order_notes(['order_id' => $order->get_id(), 'type' => 'internal']);
    verify(count(array_filter($notes, fn ($note) => str_contains($note->content, 'WP Hub documents sent'))) === 1, 'Sync retry does not duplicate private note');
    if ($mode === 'hpos') {
        verify(get_post_type($order->get_id()) !== 'shop_order', 'HPOS test order is not a legacy shop_order post');
        verify(! get_post_meta($order->get_id(), WPHub_Documents::META, true), 'HPOS receipt is not written to postmeta when compatibility sync is disabled');
    }
    $unmanaged = fictional_order();
    $GLOBALS['wphub_fixture_mail'] = [];
    $unmanaged->update_status('completed');
    verify(count($GLOBALS['wphub_fixture_mail']) === 1, 'Unmanaged order retains its normal customer completion email');
    $saveFailure = fictional_order();
    $saveFailureProof = proof($saveFailure);
    verify(api('POST', '/orders/'.$saveFailure->get_id().'/receipt', $saveFailureProof)->get_status() === 200, 'Status-save failure fixture starts with a persisted receipt');
    $throwOnSave = static function ($saving) use ($saveFailure) {
        if ($saving->get_id() === $saveFailure->get_id() && $saving->get_status() === 'completed') {
            throw new RuntimeException('Intentional fictional database-save failure');
        }
    };
    add_action('woocommerce_before_order_object_save', $throwOnSave);
    $GLOBALS['wphub_fixture_mail'] = [];
    $failedSave = api('POST', '/orders/'.$saveFailure->get_id().'/complete', ['receipt_id' => $saveFailureProof['receipt_id']]);
    remove_action('woocommerce_before_order_object_save', $throwOnSave);
    verify($failedSave->get_status() === 500, 'Real WooCommerce failed status save is surfaced as an error');
    $afterFailure = wc_get_order($saveFailure->get_id());
    $afterFailure->read_meta_data(true);
    verify($afterFailure->get_status() === 'processing' && empty($afterFailure->get_meta(WPHub_Documents::META, true)['complete']), 'Failed status save rolls back suppression flag and evicts stale cache');
    verify(count($GLOBALS['wphub_fixture_mail']) === 0, 'Failed status save sends no completion notification');
    verify(api('POST', '/orders/'.$saveFailure->get_id().'/complete', ['receipt_id' => $saveFailureProof['receipt_id']])->get_status() === 200, 'A failed status save can be reconciled by retrying store synchronization only');
    $locked = fictional_order();
    $lockedProof = proof($locked);
    $secondConnection = new PDO('mysql:host=127.0.0.1;port=33177;dbname='.DB_NAME, DB_USER, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $secondConnection->beginTransaction();
    $headerTable = $mode === 'hpos' ? $wpdb->prefix.'wc_orders' : $wpdb->posts;
    $secondConnection->query('SELECT id FROM `'.$headerTable.'` WHERE id = '.(int) $locked->get_id().' FOR UPDATE')->fetchAll();
    $contended = api('POST', '/orders/'.$locked->get_id().'/receipt', $lockedProof);
    $secondConnection->rollBack();
    verify($contended->get_status() === 500, 'Real InnoDB row-lock contention fails safely within the bounded timeout');
    verify(! wc_get_order($locked->get_id())->get_meta(WPHub_Documents::META, true), 'Contended transaction does not leave a receipt');
    verify(api('POST', '/orders/'.$locked->get_id().'/receipt', $lockedProof)->get_status() === 200, 'Receipt retry succeeds after competing row lock is released');
    $sameSecond = fictional_order();
    $sameSecondProof = proof($sameSecond);
    verify(api('POST', '/orders/'.$sameSecond->get_id().'/receipt', $sameSecondProof)->get_status() === 200, 'Same-second edit fixture starts with a valid receipt');
    $originalModified = $sameSecond->get_date_modified();
    $sameSecond->set_billing_first_name('Changed after approval');
    $sameSecond->set_date_modified($originalModified);
    $sameSecond->save();
    // Explicitly preserve the same timestamp to reproduce the precision limit.
    $sameSecond->set_date_modified($originalModified);
    $sameSecond->save();
    verify(gmdate('Y-m-d\TH:i:s', wc_get_order($sameSecond->get_id())->get_date_modified()->getTimestamp()) === $sameSecondProof['expected_modified_gmt'], 'Concurrent-edit regression preserves the original timestamp');
    verify(api('POST', '/orders/'.$sameSecond->get_id().'/complete', ['receipt_id' => $sameSecondProof['receipt_id']])->get_status() === 409, 'Strong revision rejects a customer change despite an unchanged timestamp');
    verify(wc_get_order($sameSecond->get_id())->get_status() === 'processing', 'Changed customer order remains processing');
    $changedItem = fictional_order();
    $changedItemProof = proof($changedItem);
    verify(api('POST', '/orders/'.$changedItem->get_id().'/receipt', $changedItemProof)->get_status() === 200, 'Line-item edit fixture starts with a valid receipt');
    $items = $changedItem->get_items();
    $item = reset($items);
    $item->set_name('Changed service after approval');
    $item->save();
    verify(api('POST', '/orders/'.$changedItem->get_id().'/complete', ['receipt_id' => $changedItemProof['receipt_id']])->get_status() === 409, 'Strong revision rejects a line-item change before completion');
    $changed = fictional_order();
    $changedProof = proof($changed);
    verify(api('POST', '/orders/'.$changed->get_id().'/receipt', $changedProof)->get_status() === 200, 'Changed-order fixture starts with verified receipt');
    $changed->update_status('cancelled');
    $GLOBALS['wphub_fixture_mail'] = [];
    verify(api('POST', '/orders/'.$changed->get_id().'/complete', ['receipt_id' => $changedProof['receipt_id']])->get_status() === 409, 'Cancellation after receipt is not overwritten');
    verify(wc_get_order($changed->get_id())->get_status() === 'cancelled', 'Cancellation remains recorded');
    verify(api('POST', '/orders/'.$pending->get_id().'/receipt', proof($pending))->get_status() === 409, 'Unpaid order cannot receive fulfillment receipt');
    foreach (['customer_refunded_order', 'cancelled_order', 'new_order'] as $emailId) {
        $emailObject = array_values(array_filter(WC()->mailer()->get_emails(), fn ($email) => $email->id === $emailId))[0];
        verify(apply_filters('woocommerce_email_enabled_'.$emailId, true, $order, $emailObject) === true, $emailId.' notification filter remains unaffected');
    }
    $refund = fictional_order();
    $GLOBALS['wphub_fixture_mail'] = [];
    $refunded = wc_create_refund(['order_id' => $refund->get_id(), 'amount' => $refund->get_total(), 'reason' => 'Fictional refund; no gateway', 'refund_payment' => false, 'restock_items' => false]);
    verify(! is_wp_error($refunded), 'A fictional refund is recorded without contacting a payment gateway');
    verify(count($GLOBALS['wphub_fixture_mail']) >= 1 && (bool) array_filter($GLOBALS['wphub_fixture_mail'], fn ($mail) => str_contains(strtolower($mail['subject']), 'refund')), 'Normal refund notification is still generated');
    $cancel = fictional_order();
    $GLOBALS['wphub_fixture_mail'] = [];
    $cancel->update_status('cancelled');
    verify((bool) array_filter($GLOBALS['wphub_fixture_mail'], fn ($mail) => str_contains(strtolower($mail['subject']), 'cancelled')), 'Normal administrator cancellation notification is still generated');
    $key = 'ck_'.bin2hex(random_bytes(20));
    $secret = 'cs_'.bin2hex(random_bytes(20));
    $wpdb->insert($wpdb->prefix.'woocommerce_api_keys', ['user_id' => 1, 'description' => 'Fictional read-only validation', 'permissions' => 'read',
        'consumer_key' => wc_api_hash($key), 'consumer_secret' => $secret, 'truncated_key' => substr($key, -7)]);
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/wphub-documents/brand';
    $_SERVER['PHP_AUTH_USER'] = $key;
    $_SERVER['PHP_AUTH_PW'] = $secret;
    $authentication = new WC_REST_Authentication();
    verify((int) $authentication->authenticate(false) === 1, 'Actual WooCommerce Basic API-key authenticator accepts a generated local manager key');
    $permission = $authentication->check_user_permissions(null, rest_get_server(), new WP_REST_Request('PUT', '/wc/v3/wphub-documents/brand'));
    verify(is_wp_error($permission), 'Actual WooCommerce API-key permission check denies writes for a read-only key');
    unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
    $result = ['passed' => true, 'mode' => $mode, 'wordpress' => get_bloginfo('version'), 'woocommerce' => WC_VERSION,
        'php' => PHP_VERSION, 'database' => $wpdb->get_var('SELECT VERSION()'), 'hpos_compatibility_sync' => false,
        'email_improvements' => get_option('woocommerce_feature_email_improvements_enabled'),
        'transport' => 'capture only', 'real_external_email_sent' => false, 'network_requests_blocked' => count($GLOBALS['wphub_fixture_http']), 'checks' => $checks];
} catch (Throwable $exception) {
    $result = ['passed' => false, 'mode' => $mode, 'error' => $exception->getMessage(), 'trace' => $exception->getTraceAsString(), 'checks' => $checks];
}
file_put_contents($fixture.'/result-'.$mode.'.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
exit($result['passed'] ? 0 : 1);
