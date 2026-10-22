<?php
/** Isolated contract checks, not a substitute for WooCommerce/HPOS staging tests. */
define('ABSPATH', __DIR__);
define('WC_VERSION', '9.0.0');
$options = [];
$orders = [];
$manager = true;
$orderPermission = true;
$checks = 0;
$transactions = [];
$filters = [];
$cacheCleared = false;

class StubDatabase
{
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $comments = 'wp_comments';
    public string $commentmeta = 'wp_commentmeta';
    public ?string $nonTransactionalTable = null;
    public string $last_error = '';
    public bool $lockAvailable = true;
    public array $queries = [];
    public function prepare($query, $value) { return str_replace(['%s', '%d'], (string) $value, $query); }
    public function get_var($query) {
        $this->queries[] = $query;
        if (str_contains($query, 'SELECT ENGINE')) { return $this->nonTransactionalTable && str_contains($query, $this->nonTransactionalTable) ? 'MyISAM' : 'InnoDB'; }
        return str_contains($query, 'GET_LOCK') ? ($this->lockAvailable ? '1' : '0') : '1';
    }
    public function query($query) { $this->queries[] = $query; return true; }
}
$wpdb = new StubDatabase;

class WP_Error
{
    public function __construct(public string $code, public string $message, public array $data = []) {}
}
class WC_Order
{
    public array $meta = [];
    public array $persisted = [];
    public array $notes = [];
    public ?bool $notificationEnabled = null;
    public string $status = 'processing';
    public string $modified = '2026-10-21T12:00:00';
    public bool $paid = true;
    public bool $statusSaveSucceeds = true;
    public string $name = 'Example <script>bad</script>';
    public string $email = 'fixture@example.test';
    public function __construct(public int $id) {}
    public function get_id() { return $this->id; }
    public function get_status() { return $this->status; }
    public function is_paid() { return $this->paid && in_array($this->status, ['processing', 'completed'], true); }
    public function get_date_paid() { return $this->paid ? new DateTimeImmutable('2026-10-21T10:00:00Z') : null; }
    public function get_date_modified() { return new DateTimeImmutable($this->modified.'Z'); }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function save_meta_data() { $this->persisted = $this->meta; }
    public function read_meta_data($force = false) { $this->meta = $this->persisted; }
    public function get_billing_first_name() { return $this->name; }
    public function get_billing_email() { return $this->email; }
    public function get_data() { return ['status' => $this->status, 'currency' => 'USD', 'billing' => ['email' => $this->email]]; }
    public function get_items($types = []) { return []; }
    public function get_order_number() { return 'TEST-'.$this->id; }
    public function update_status($status) {
        assert_contract(isset($this->persisted[WPHub_Documents::META]['provider_message_id']), 'Marker is durable before status changes');
        $this->status = $status;
        if (! $this->statusSaveSucceeds) { return false; }
        $this->notificationEnabled = WPHub_Documents::completion_enabled(true, $this);
        return true;
    }
    public function add_order_note($note, $customer = false, $user = false) { $this->notes[] = compact('note', 'customer', 'user'); }
}
class Request implements ArrayAccess
{
    public function __construct(public array $body = [], public string $method = 'POST', public string $identity = '7f3edfb6-2110-45ab-b96d-97b10d64d6f6') {}
    public function get_method() { return $this->method; }
    public function get_json_params() { return $this->body; }
    public function get_header($name) { return $this->identity; }
    public function offsetExists(mixed $offset): bool { return isset($this->body[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->body[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->body[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->body[$offset]); }
}
function get_option($name, $default = []) { return $GLOBALS['options'][$name] ?? $default; }
function update_option($name, $value, $autoload = false) { $GLOBALS['options'][$name] = $value; }
function wc_get_order($id) { return $GLOBALS['orders'][$id] ?? null; }
function current_user_can($capability) { return $GLOBALS['manager']; }
function wc_rest_check_post_permissions(...$arguments) { return $GLOBALS['orderPermission']; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function sanitize_textarea_field($value) { return trim(strip_tags($value)); }
function sanitize_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : ''; }
function sanitize_hex_color($value) { return preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i', $value) ? $value : null; }
function esc_url_raw($value, $protocols = []) { return $value; }
function wp_parse_url($value, $component) { return parse_url($value, $component); }
function wp_json_encode($value) { return json_encode($value); }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_html($value); }
function add_filter($name, $callback, $priority = 10, $arguments = 1) { $GLOBALS['filters'][$name][] = $callback; }
function remove_filter($name, $callback, $priority = 10) { unset($GLOBALS['filters'][$name]); }
function wc_transaction_query($mode, $force = false) { assert_contract($force, 'Receipt transactions cannot be disabled by WC_USE_TRANSACTIONS'); $GLOBALS['transactions'][] = $mode; }
function clean_post_cache($id) { $GLOBALS['cacheCleared'] = true; }
function assert_contract($condition, $message) {
    $GLOBALS['checks']++;
    if (! $condition) { throw new RuntimeException($message); }
}
require dirname(__DIR__).'/class-wphub-documents.php';

$identity = '7f3edfb6-2110-45ab-b96d-97b10d64d6f6';
$options[WPHub_Documents::OPTION] = ['website_identity' => $identity, 'enabled' => true, 'config_version' => '3', 'brand_name' => 'Example Brand', 'support_email' => 'support@example.test', 'default_language' => 'en'];
$orders[42] = new WC_Order(42);
$proof = ['id' => 42, 'receipt_id' => '95f6f837-e67b-48bb-8bfb-0d2af8967aee', 'provider_message_id' => 'provider-test-only',
    'document_set_version' => str_repeat('a', 64), 'sent_at' => gmdate(DATE_ATOM), 'fulfillment_confirmed' => true,
    'attachment_count' => 2, 'expected_modified_gmt' => '2026-10-21T12:00:00', 'config_version' => '3'];
$proof['expected_fulfillment_revision'] = WPHub_Documents::eligibility(new Request(['id' => 42]))['fulfillment_revision'];

assert_contract(WPHub_Documents::completion_enabled(true, $orders[42]), 'Unmanaged order retains completion email');
assert_contract(! WPHub_Documents::completion_enabled(false, $orders[42]), 'Other filters can still disable email');
assert_contract(is_wp_error(WPHub_Documents::complete(new Request($proof))), 'Completion without receipt is refused');
$manager = false;
assert_contract(is_wp_error(WPHub_Documents::permission(new Request($proof))), 'Non-manager is refused');
$manager = true;
assert_contract(is_wp_error(WPHub_Documents::permission(new Request($proof, 'POST', 'different-website'))), 'Different website is refused');
assert_contract(WPHub_Documents::permission(new Request($proof)) === true, 'Paired authorized website accepted');
$orderPermission = false;
assert_contract(is_wp_error(WPHub_Documents::permission(new Request($proof))), 'Order edit permission is required');
$orderPermission = true;
assert_contract(is_wp_error(WPHub_Documents::receipt(new Request(array_replace($proof, ['fulfillment_confirmed' => false])))), 'Partial delivery cannot prepare completion');
assert_contract(is_wp_error(WPHub_Documents::receipt(new Request(array_replace($proof, ['attachment_count' => 0])))), 'Empty delivery cannot prepare completion');
$orders[42]->paid = false;
assert_contract(is_wp_error(WPHub_Documents::receipt(new Request($proof))), 'Unpaid order cannot prepare completion');
$orders[42]->paid = true;
assert_contract(WPHub_Documents::receipt(new Request($proof))['verified'] === true, 'Receipt is persisted and verified');
assert_contract(WPHub_Documents::completion_enabled(true, $orders[42]), 'Receipt preparation alone does not suppress a later unrelated transition');
assert_contract(WPHub_Documents::receipt(new Request($proof))['verified'] === true, 'Repeated receipt write is idempotent');
assert_contract(is_wp_error(WPHub_Documents::receipt(new Request(array_replace($proof, ['receipt_id' => 'ddc176d4-59f8-47cb-b023-c18d27fcfeaa'])))), 'Conflicting delivery cannot replace receipt');
foreach (['cancelled', 'refunded', 'on-hold'] as $status) {
    $orders[42]->status = $status;
    assert_contract(is_wp_error(WPHub_Documents::complete(new Request($proof))), $status.' order is not overwritten');
}
$orders[42]->status = 'processing';
$orders[42]->modified = '2026-10-21T12:01:00';
assert_contract(is_wp_error(WPHub_Documents::complete(new Request($proof))), 'Changed order is not overwritten');
$orders[42]->modified = $proof['expected_modified_gmt'];
$result = WPHub_Documents::complete(new Request($proof));
assert_contract($result['status'] === 'completed', 'Successful fulfillment completes order');
assert_contract($orders[42]->notificationEnabled === false, 'Completion email sees the previously persisted marker');
assert_contract(count($orders[42]->notes) === 1 && $orders[42]->notes[0]['customer'] === false, 'Fulfillment note is private');
WPHub_Documents::complete(new Request($proof));
assert_contract(count($orders[42]->notes) === 1, 'Repeat completion does not add another note');
$orders[43] = new WC_Order(43);
$orders[43]->meta = $orders[42]->meta;
assert_contract(WPHub_Documents::completion_enabled(true, $orders[43]), 'Copied marker from another order cannot suppress email');
$options[WPHub_Documents::OPTION]['website_identity'] = 'different-site';
assert_contract(WPHub_Documents::completion_enabled(true, $orders[42]), 'Marker from another paired website cannot suppress email');
$options[WPHub_Documents::OPTION]['website_identity'] = $identity;
$email = (object) ['id' => 'customer_processing_order'];
ob_start(); WPHub_Documents::acknowledgement($orders[43], false, false, $email); $html = ob_get_clean();
assert_contract(str_contains($html, 'payment has been received'), 'Verified paid acknowledgement uses paid wording');
assert_contract(! str_contains($html, '<script>'), 'Customer-controlled text is HTML escaped');
$orders[43]->paid = false;
ob_start(); WPHub_Documents::acknowledgement($orders[43], false, true, $email); $plain = ob_get_clean();
assert_contract(str_contains($plain, 'Payment has not yet been confirmed') && ! str_contains($plain, 'payment has been received'), 'Unpaid wording does not claim payment');
$orders[43]->meta['_order_locale'] = 'fr_FR';
ob_start(); WPHub_Documents::acknowledgement($orders[43], false, true, $email); $plain = ob_get_clean();
assert_contract(str_contains($plain, 'paiement n’est pas encore confirmé'), 'Recorded French locale is used');
ob_start(); WPHub_Documents::acknowledgement($orders[43], true, false, $email); $html = ob_get_clean();
assert_contract($html === '', 'Administrator notification is unchanged');
ob_start(); WPHub_Documents::acknowledgement($orders[43], false, false, (object) ['id' => 'customer_refunded_order']); $html = ob_get_clean();
assert_contract($html === '', 'Refund notification is unchanged');
$originalCss = '@media screen and (max-width: 600px) { #body_content_inner { font-size:10px !important; } }';
$options[WPHub_Documents::OPTION]['primary_color'] = '#123456';
$options[WPHub_Documents::OPTION]['text_color'] = '#222222';
$brandedCss = WPHub_Documents::styles($originalCss, $email);
assert_contract(str_ends_with($brandedCss, '@media screen and (max-width: 600px) { #body_content_inner { font-size:16px !important; line-height:1.6 !important; } }'), 'Acknowledgement mobile body overrides the stock tiny font after its media query');
assert_contract(str_contains($brandedCss, '#body_content_inner h2, #body_content_inner h3, #body_content_inner a, #body_content_inner .link { color:#123456; }'), 'Acknowledgement headings and links use configured brand color');
assert_contract(str_contains($brandedCss, '#body_content_inner, #body_content_inner .td, #body_content_inner .address { color:#222222; }'), 'Acknowledgement order details use configured readable text color');
foreach (['#334155' => '#ffffff', '#ffffff' => '#000000', '#00cc61' => '#000000', '#fff' => '#000000', '#777777' => '#000000'] as $headerColor => $expectedText) {
    $options[WPHub_Documents::OPTION]['primary_color'] = $headerColor;
    foreach (['customer_processing_order', 'customer_on_hold_order'] as $acknowledgementId) {
        $css = WPHub_Documents::styles($originalCss, (object) ['id' => $acknowledgementId]);
        assert_contract(str_contains($css, '#template_header, #template_header h1, #template_header h1 a { color:'.$expectedText.'; }'), $acknowledgementId.' heading chooses stronger contrast for '.$headerColor);
    }
}
$options[WPHub_Documents::OPTION]['primary_color'] = '#123456';
foreach (['customer_completed_order', 'customer_refunded_order', 'cancelled_order', 'new_order'] as $emailId) {
    assert_contract(WPHub_Documents::styles($originalCss, (object) ['id' => $emailId]) === $originalCss, $emailId.' styling remains unchanged');
}
assert_contract(is_wp_error(WPHub_Documents::brand(new Request(['enabled' => true, 'templates' => ['en' => ['body' => '{{execute_php}}']]]))), 'Unsupported placeholders rejected');
$orders[44] = new WC_Order(44);
$proof44 = array_replace($proof, ['id' => 44]);
$wpdb->lockAvailable = false;
assert_contract(is_wp_error(WPHub_Documents::receipt_route(new Request($proof44))), 'Concurrent plugin operations fail closed when lock is busy');
$wpdb->lockAvailable = true;
assert_contract(WPHub_Documents::receipt_route(new Request($proof44))['verified'] === true, 'Locked receipt persists successfully');
assert_contract(in_array('start', $transactions, true) && in_array('commit', $transactions, true), 'Receipt operation uses a transaction');
assert_contract($cacheCleared, 'Order cache is invalidated after acquiring row lock');
assert_contract((bool) array_filter($wpdb->queries, fn ($query) => str_contains($query, 'FOR UPDATE')), 'Order header row is locked before CRUD');
assert_contract(empty($filters['woocommerce_orders_table_datastore_should_save_after_meta_change']), 'HPOS metadata filter is scoped and removed');
$orders[44]->status = 'cancelled';
assert_contract(is_wp_error(WPHub_Documents::complete_route(new Request($proof44))) && end($transactions) === 'rollback', 'Rejected transition rolls back transaction');
$languageKeys = ['_wphub_language', '_order_locale', '_order_language', 'wpml_language', '_wpml_language', 'pll_language'];
foreach ($languageKeys as $key) {
    $orders[43]->meta = [$key => 'fr_FR'];
    ob_start(); WPHub_Documents::acknowledgement($orders[43], false, true, $email); $plain = ob_get_clean();
    assert_contract(str_contains($plain, 'paiement n’est pas encore confirmé'), $key.' supplies recorded language');
}
$orders[43]->meta = ['_wphub_language' => 'de', '_order_locale' => 'fr_CA'];
ob_start(); WPHub_Documents::acknowledgement($orders[43], false, true, $email); $plain = ob_get_clean();
assert_contract(str_contains($plain, 'paiement n’est pas encore confirmé'), 'Unsupported first locale does not hide a later valid recorded locale');
$orders[43]->meta = ['_wphub_language' => 'en_GB', '_order_locale' => 'fr_FR'];
ob_start(); WPHub_Documents::acknowledgement($orders[43], false, true, $email); $plain = ob_get_clean();
assert_contract(str_contains($plain, 'Payment has not yet been confirmed'), 'First supported recorded locale wins');
$orders[43]->meta = ['_wphub_language' => 'de'];
ob_start(); WPHub_Documents::acknowledgement($orders[43], false, true, $email); $plain = ob_get_clean();
assert_contract(str_contains($plain, 'Payment has not yet been confirmed'), 'Unsupported recorded locales use website fallback');
WPHub_Documents::brand(new Request(['enabled' => true, 'brand_name' => 'Example Brand', 'support_email' => 'support@example.test',
    'service_label' => 'flight reservation', 'default_language' => 'en', 'config_version' => '3',
    'templates' => ['en' => ['body' => 'We are preparing your {{service_label}} documents.', 'preheader' => 'Preview text', 'optional_block' => 'Optional text']]]));
$orders[43]->paid = true;
ob_start(); WPHub_Documents::acknowledgement($orders[43], false, false, $email); $html = ob_get_clean();
assert_contract(str_contains($html, 'flight reservation') && str_contains($html, 'Preview text') && str_contains($html, 'Optional text'), 'Paid acknowledgement renders synchronized service, preheader and optional block');

$orders[45] = new WC_Order(45);
$proof45 = array_replace($proof, ['id' => 45]);
WPHub_Documents::receipt_route(new Request($proof45));
$orders[45]->statusSaveSucceeds = false;
$cacheCleared = false;
assert_contract(is_wp_error(WPHub_Documents::complete_route(new Request($proof45))), 'WooCommerce false status-save result is not reported as completed');
assert_contract(end($transactions) === 'rollback', 'Failed status save rolls back the suppression flag');
assert_contract(count($orders[45]->notes) === 0, 'Failed status save does not create a fulfillment success note');
assert_contract($cacheCleared, 'Rollback evicts unsaved order cache state');
$wpdb->nonTransactionalTable = 'wp_postmeta';
assert_contract(WPHub_Documents::health(new Request)['requirements']['compatible'] === false, 'Nontransactional metadata disables health capability');
assert_contract(is_wp_error(WPHub_Documents::receipt_route(new Request($proof44))), 'Nontransactional metadata refuses receipt writes');
$wpdb->nonTransactionalTable = 'wp_commentmeta';
assert_contract(WPHub_Documents::health(new Request)['requirements']['compatible'] === false, 'Nontransactional private-note metadata disables completion capability');
$wpdb->nonTransactionalTable = null;
$orders[46] = new WC_Order(46);
$proof46 = array_replace($proof, ['id' => 46]);
$orders[46]->email = 'changed@example.test';
assert_contract(is_wp_error(WPHub_Documents::receipt_route(new Request($proof46))), 'Same-second customer change invalidates receipt proof even when timestamp is unchanged');
$orders[46]->email = 'fixture@example.test';
assert_contract(WPHub_Documents::receipt_route(new Request($proof46))['verified'], 'Matching strong revision permits receipt');
$orders[46]->email = 'changed@example.test';
assert_contract(is_wp_error(WPHub_Documents::complete_route(new Request($proof46))), 'Same-second customer change after receipt is rejected before completion');
echo 'PASS: '.$checks." isolated plugin contract assertions. Real WooCommerce staging remains required.\n";
