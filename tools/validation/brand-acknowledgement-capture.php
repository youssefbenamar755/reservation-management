<?php

/** Actual paid-order Woo acknowledgement capture. New loopback database only; never SMTP or Gmail. */
$root = realpath((string) getenv('WPHUB_FIXTURE_ROOT'));
$profilesPath = realpath((string) ($argv[1] ?? ''));
$repo = dirname(__DIR__, 2);
if (PHP_SAPI !== 'cli' || function_exists('mail') || ! $root || ! $profilesPath
    || ! is_file($root.'/FICTIONAL-ONLY') || ! is_file($root.'/private.json')
    || getenv('WPHUB_VALIDATION_MAIL_TRANSPORT') !== 'capture'
    || is_file($root.'/brand-ack-started.json') || is_file($root.'/brand-ack-captures.json')) {
    throw new RuntimeException('Unused private capture fixture and frozen profile snapshot required. Reruns cannot create more orders.');
}
$profiles = json_decode(file_get_contents($profilesPath), true, flags: JSON_THROW_ON_ERROR);
$recipients = ['youssefbenamar755@gmail.com', 'benamar-y14@outlook.com'];
if (($profiles['format'] ?? '') !== 'wphub-brand-client-matrix-v1' || $profiles['recipients'] !== $recipients
    || array_column($profiles['profiles'], 'source_website_id') !== [8, 9, 10, 11, 12]
    || explode(',', (string) getenv('WPHUB_VALIDATION_RECIPIENTS')) !== $recipients) {
    throw new RuntimeException('Exact five-brand/two-recipient snapshot required.');
}
define('WPHUB_FIXTURE_ROOT', $root);
require $repo.'/integrations/wphub-documents/tests/real/windows-readability.php';
define('WP_USE_THEMES', false);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
$_SERVER['HTTP_HOST'] = 'fixture.example.invalid';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTPS'] = 'on';
require $root.'/wordpress/wp-load.php';
global $wpdb;
if (DB_NAME !== 'wphub_fixture_hpos' || DB_HOST !== '127.0.0.1:33177'
    || ! str_starts_with(strtolower(str_replace('\\', '/', $wpdb->get_var('SELECT @@datadir'))), strtolower(str_replace('\\', '/', $root)).'/data/')
    || ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
    || get_option('woocommerce_custom_orders_table_data_sync_enabled') !== 'no'
    || count(wc_get_orders(['limit' => 1])) !== 0) {
    throw new RuntimeException('Fresh actual HPOS fixture identity mismatch.');
}
file_put_contents($root.'/brand-ack-started.json', json_encode(['id' => $profiles['id'], 'started_at' => gmdate(DATE_ATOM)]));
update_option('admin_email', $recipients[0]);
update_option('woocommerce_new_order_settings', array_merge((array) get_option('woocommerce_new_order_settings', []), ['recipient' => $recipients[0]]));
// Same Woo feature state observed on the two newer live stores; all unrelated plugins remain absent.
update_option('woocommerce_feature_email_improvements_enabled', 'yes');
WC()->mailer();
$processingEmail = WC()->mailer()->get_emails()['WC_Email_Customer_Processing_Order'];
// The fixture has no physical shop. The saved brand footer is rendered by the
// companion; do not retain Woo's install-time California/US sample footer.
update_option('woocommerce_email_footer_text', '');
$messages = [];
$current = null;
add_filter('woocommerce_mail_callback_params', static function ($params, $email) use (&$messages, &$current) {
    if (($email->id ?? '') !== 'customer_processing_order') {
        return $params;
    }
    if (! $current || ! $email->object instanceof WC_Order || $email->object->get_id() !== $current['order_id']) {
        throw new RuntimeException('Unexpected customer email object.');
    }
    [$to, $subject, $html, $headers, $attachments] = $params;
    if ($to !== $current['recipient'] || $attachments !== [] || preg_match('/^(?:cc|bcc):/mi', (string) $headers)) {
        throw new RuntimeException('Unexpected acknowledgement recipient or attachments.');
    }
    if (! preg_match('/^Reply-To:[ \t]*([^\r\n]+)\r?$/mi', $headers, $match)) {
        throw new RuntimeException('Acknowledgement Reply-To missing.');
    }
    $messages[] = $current + ['subject' => $subject, 'html' => $html, 'text' => $email->get_content_plain(),
        'sender_email' => $email->get_from_address(), 'sender_name' => $email->get_from_name(), 'reply_to' => trim($match[1]),
        'email_id' => $email->id, 'order_number' => $email->object->get_order_number(), 'paid' => $email->object->is_paid(),
        'date_paid_present' => (bool) $email->object->get_date_paid()];

    return $params;
}, PHP_INT_MAX, 2);
try {
    foreach ($profiles['profiles'] as $entry) {
        $profile = $entry['profile'];
        $config = $profile;
        $config['enabled'] = true;
        $config['templates'] = $profile['templates']['acknowledgement'];
        $request = new WP_REST_Request('PUT', '/wc/v3/wphub-documents/brand');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(json_encode($config));
        $result = WPHub_Documents::brand($request);
        if (is_wp_error($result) || empty($result['enabled'])) {
            throw new RuntimeException('Actual companion rejected frozen brand configuration.');
        }
        update_option('woocommerce_email_from_address', $profile['sender_email']);
        update_option('woocommerce_email_from_name', $profile['sender_name']);
        update_option('blogname', $profile['brand_name']);
        foreach (['en' => $recipients[0], 'fr' => $recipients[1]] as $language => $recipient) {
            $targetLocale = $language === 'fr' ? 'fr_FR' : 'en_US';
            if ($language === 'fr' && ! in_array('fr_FR', get_available_languages(), true)) {
                throw new RuntimeException('Install official French WordPress and WooCommerce catalogues before freezing this matrix.');
            }
            update_option('WPLANG', $targetLocale);
            switch_to_locale($targetLocale);
            $orderSummary = __('Order summary', 'woocommerce');
            if (get_locale() !== $targetLocale || ($language === 'fr' && $orderSummary === 'Order summary')) {
                throw new RuntimeException('The selected WordPress/Woo locale must translate the stock order-summary heading.');
            }
            // This is normal Woo email configuration in the isolated store. Its
            // cached installation defaults otherwise keep the initial .invalid
            // address and English copy even after changing the sender/locale.
            $additionalContent = $language === 'fr'
                ? 'Merci pour votre confiance. Pour toute question, répondez à cet e-mail.'
                : 'Thank you. For any questions, reply directly to this email.';
            update_option('woocommerce_customer_processing_order_settings', array_merge(
                (array) get_option('woocommerce_customer_processing_order_settings', []),
                ['additional_content' => $additionalContent]
            ));
            $processingEmail->init_settings();
            $before = count($messages);
            $order = wc_create_order();
            $order->set_billing_email($recipient);
            $order->set_billing_first_name('Alexandria Éléonore Morgan-Van-Der-Linden de la Fontaine FICTIONAL');
            $order->set_billing_last_name('APPROVED TEST ONLY');
            $order->set_currency('USD');
            $order->set_total('15.00');
            $order->set_payment_method('fixture-no-gateway');
            $order->set_date_paid(time() - 600);
            $order->update_meta_data('_order_locale', $language);
            $item = new WC_Order_Item_Product;
            $item->set_name($language === 'fr'
                ? 'Échantillon FICTIF — aucune réservation, aucun document de voyage ni achat réel'
                : 'FICTIONAL sample only — not a reservation, travel document or real purchase');
            $item->set_quantity(1);
            $item->set_subtotal('15.00');
            $item->set_total('15.00');
            $order->add_item($item);
            $order->save();
            $current = ['source_website_id' => $entry['source_website_id'], 'language' => $language, 'stock_template_locale' => get_locale(),
                'stock_order_summary' => $orderSummary, 'recipient' => $recipient, 'order_id' => $order->get_id()];
            $order->update_status('processing');
            if (count($messages) !== $before + 1 || end($messages)['sender_email'] !== $profile['sender_email']
                || end($messages)['reply_to'] !== $profile['reply_to'] || ! end($messages)['paid']) {
                throw new RuntimeException('Expected exactly one correctly branded paid-order acknowledgement.');
            }
            $current = null;
            restore_previous_locale();
        }
    }
    $report = ['format' => 'wphub-actual-woo-brand-acks-v1', 'profile_sha256' => hash_file('sha256', $profilesPath),
        'created_at' => gmdate(DATE_ATOM), 'software' => ['wordpress' => get_bloginfo('version'), 'woocommerce' => WC_VERSION, 'php' => PHP_VERSION, 'database' => $wpdb->db_version()],
        'hpos' => true, 'compatibility_sync' => false, 'email_improvements' => get_option('woocommerce_feature_email_improvements_enabled'),
        'actual_network_emails' => 0, 'native_mail_disabled' => true,
        'payment_gateways' => count(WC()->payment_gateways()->payment_gateways()), 'customer_acknowledgements' => count($messages),
        'all_captured_notifications' => count($GLOBALS['wphub_fixture_mail']), 'messages' => $messages,
        'fixture_email_configuration' => 'Per-language Woo additional_content explicitly configured; stock footer empty because the saved brand footer supplies contact details and no physical address was provided.',
        'scope' => 'Actual Woo paid-processing triggers and companion HTML/plain content. wp_mail is intercepted by capture-only MU guard; Gmail bridge is separate and does not verify stock SMTP.'];
    file_put_contents($root.'/brand-ack-captures.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo json_encode(array_diff_key($report, ['messages' => true]), JSON_PRETTY_PRINT)."\n";
} finally {
    $disabled = get_option(WPHub_Documents::OPTION, []);
    $disabled['enabled'] = false;
    update_option(WPHub_Documents::OPTION, $disabled);
}
