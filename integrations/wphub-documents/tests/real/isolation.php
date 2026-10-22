<?php
/** Local-only MU plugin: never install this file on a customer store. */
defined('ABSPATH') || exit;
if (getenv('WPHUB_VALIDATION_MODE') !== 'true' || getenv('WPHUB_VALIDATION_MAIL_TRANSPORT') !== 'capture'
    || ! defined('DISABLE_WP_CRON') || ! DISABLE_WP_CRON
    || ! defined('WPHUB_FIXTURE_ROOT') || ! is_file(WPHUB_FIXTURE_ROOT.'/FICTIONAL-ONLY')) {
    throw new RuntimeException('The isolated WooCommerce fixture is not armed for capture-only validation.');
}
$GLOBALS['wphub_fixture_mail'] = [];
$GLOBALS['wphub_fixture_http'] = [];
add_filter('pre_http_request', static function ($response, $arguments, $url) {
    $GLOBALS['wphub_fixture_http'][] = $url;
    return new WP_Error('fixture_network_blocked', 'All WordPress HTTP requests are blocked in this fictional fixture.');
}, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', static function ($return, $mail) {
    $allowed = array_filter(array_map('trim', explode(',', (string) getenv('WPHUB_VALIDATION_RECIPIENTS'))));
    $recipients = is_array($mail['to']) ? $mail['to'] : explode(',', $mail['to']);
    foreach ($recipients as $recipient) {
        if (! in_array(trim($recipient), $allowed, true)) {
            throw new RuntimeException('A test-store recipient is absent from the explicit validation allowlist.');
        }
    }
    if (preg_match('/^(?:cc|bcc):/mi', is_array($mail['headers']) ? implode("\n", $mail['headers']) : $mail['headers'])) {
        throw new RuntimeException('CC/BCC is prohibited in the isolated store fixture.');
    }
    $GLOBALS['wphub_fixture_mail'][] = $mail;
    return true; // Capture locally; never execute PHPMailer or SMTP.
}, PHP_INT_MAX, 2);
add_filter('woocommerce_payment_gateways', static fn () => []);
add_filter('woocommerce_webhook_should_deliver', '__return_false', PHP_INT_MAX);
add_filter('action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX);
add_filter('woocommerce_allow_tracking', '__return_false', PHP_INT_MAX);
add_filter('automatic_updater_disabled', '__return_true', PHP_INT_MAX);
