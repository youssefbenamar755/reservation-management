<?php

defined('ABSPATH') || exit;

final class WPHub_Documents
{
    public const OPTION = 'wphub_documents_configuration';
    public const META = '_wphub_documents_receipt';

    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_filter('woocommerce_email_enabled_customer_completed_order', [self::class, 'completion_enabled'], 100, 3);
        foreach (['customer_processing_order', 'customer_on_hold_order'] as $email) {
            add_filter('woocommerce_email_subject_'.$email, [self::class, 'subject'], 20, 3);
            add_filter('woocommerce_email_heading_'.$email, [self::class, 'heading'], 20, 3);
        }
        add_action('woocommerce_email_before_order_table', [self::class, 'acknowledgement'], 10, 4);
        add_filter('woocommerce_email_styles', [self::class, 'styles'], 20, 2);
        add_filter('woocommerce_email_headers', [self::class, 'headers'], 20, 4);
        add_filter('woocommerce_email_from_name', [self::class, 'from_name'], 20, 2);
    }

    public static function routes(): void
    {
        // The wc/v3 namespace deliberately retains WooCommerce's API-key authentication.
        foreach ([
            '/health' => ['GET', 'health'],
            '/brand' => ['PUT', 'brand'],
            '/orders/(?P<id>\d+)/eligibility' => ['GET', 'eligibility'],
            '/orders/(?P<id>\d+)/receipt' => [['POST', 'GET'], 'receipt_route'],
            '/orders/(?P<id>\d+)/complete' => ['POST', 'complete_route'],
        ] as $path => [$methods, $callback]) {
            register_rest_route('wc/v3', '/wphub-documents'.$path, [
                'methods' => $methods,
                'permission_callback' => [self::class, 'permission'],
                'callback' => [self::class, $callback],
            ]);
        }
    }

    public static function permission($request)
    {
        if (! current_user_can('manage_woocommerce')) {
            return new WP_Error('wphub_permission', 'A WooCommerce manager credential is required.', ['status' => 403]);
        }
        $identity = (string) (get_option(self::OPTION, [])['website_identity'] ?? '');
        if ($identity === '' || ! hash_equals($identity, (string) $request->get_header('X-WPHub-Website'))) {
            return new WP_Error('wphub_pairing', 'This store is not paired with this WP Hub website.', ['status' => 403]);
        }
        if ($request['id'] && ! wc_rest_check_post_permissions('shop_order', 'edit', (int) $request['id'])) {
            return new WP_Error('wphub_order_permission', 'Order permission is required.', ['status' => 403]);
        }

        return true;
    }

    public static function health($request): array
    {
        global $wpdb;
        $config = get_option(self::OPTION, []);
        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        $compatible = defined('WC_VERSION') && version_compare(WC_VERSION, '8.8', '>=') && self::transactional_tables($hpos);

        return ['version' => '1.0.0', 'website_identity' => $config['website_identity'] ?? '',
            'enabled' => (bool) ($config['enabled'] ?? false), 'config_version' => $config['config_version'] ?? null,
            'capabilities' => $compatible ? ['order_specific_suppression', 'receipt_verification', 'optimistic_completion', 'order_revision', 'local_acknowledgement', 'private_notes', 'hpos'] : [],
            'requirements' => ['woocommerce_minimum' => '8.8', 'compatible' => $compatible]];
    }

    private static function transactional_tables(bool $hpos): bool
    {
        global $wpdb;
        $tables = $hpos
            ? [$wpdb->prefix.'wc_orders', $wpdb->prefix.'wc_orders_meta', $wpdb->prefix.'wc_order_addresses', $wpdb->prefix.'wc_order_operational_data']
            : [$wpdb->posts, $wpdb->postmeta];
        $tables = array_merge($tables, [$wpdb->comments, $wpdb->commentmeta]);
        foreach ($tables as $table) {
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table));
            if (strtolower((string) $engine) !== 'innodb') {
                return false;
            }
        }

        return true;
    }

    public static function brand($request)
    {
        $body = $request->get_json_params();
        $config = get_option(self::OPTION, []);
        if (! is_array($body) || ! is_bool($body['enabled'] ?? null)) {
            return new WP_Error('wphub_brand', 'An explicit enabled flag is required.', ['status' => 422]);
        }
        $clean = ['website_identity' => $config['website_identity'], 'enabled' => $body['enabled']];
        foreach (['brand_name', 'sender_name', 'support_phone', 'service_label', 'config_version'] as $key) {
            $clean[$key] = sanitize_text_field((string) ($body[$key] ?? ''));
        }
        $clean['footer'] = sanitize_textarea_field((string) ($body['footer'] ?? ''));
        foreach (['support_email', 'reply_to'] as $key) {
            $clean[$key] = sanitize_email((string) ($body[$key] ?? ''));
        }
        foreach (['logo_url', 'website_url'] as $key) {
            $url = (string) ($body[$key] ?? '');
            if ($url !== '' && (wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || ! filter_var($url, FILTER_VALIDATE_URL))) {
                return new WP_Error('wphub_url', 'Brand URLs must use HTTPS.', ['status' => 422]);
            }
            $clean[$key] = esc_url_raw($url, ['https']);
        }
        foreach (['primary_color', 'accent_color', 'background_color', 'text_color'] as $key) {
            $clean[$key] = sanitize_hex_color($body[$key] ?? '') ?: '';
        }
        $clean['default_language'] = in_array($body['default_language'] ?? '', ['fr', 'en'], true) ? $body['default_language'] : 'en';
        $clean['templates'] = [];
        foreach (['fr', 'en'] as $language) {
            foreach (['subject', 'heading', 'body', 'preheader', 'optional_block'] as $key) {
                $value = (string) ($body['templates'][$language][$key] ?? '');
                preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $value, $matches);
                if (array_diff($matches[1], ['brand_name', 'customer_first_name', 'order_number', 'document_count', 'document_list', 'support_email', 'website_url', 'service_label'])) {
                    return new WP_Error('wphub_template', 'Unsupported acknowledgement placeholder.', ['status' => 422]);
                }
                $clean['templates'][$language][$key] = sanitize_textarea_field($value);
            }
        }
        if ($clean['enabled'] && ($clean['brand_name'] === '' || $clean['support_email'] === '')) {
            return new WP_Error('wphub_brand', 'Brand and support email are required.', ['status' => 422]);
        }
        update_option(self::OPTION, $clean, false);

        return self::health($request);
    }

    private static function order($id)
    {
        $order = wc_get_order((int) $id);

        return $order ?: new WP_Error('wphub_order', 'Order not found.', ['status' => 404]);
    }

    private static function modified($order): string
    {
        $date = $order->get_date_modified();

        return $date ? gmdate('Y-m-d\TH:i:s', $date->getTimestamp()) : '';
    }

    public static function eligibility($request)
    {
        $order = self::order($request['id']);
        if (is_wp_error($order)) {
            return $order;
        }

        return ['id' => $order->get_id(), 'status' => $order->get_status(), 'date_modified_gmt' => self::modified($order),
            'date_paid_gmt' => $order->get_date_paid() ? gmdate('Y-m-d\TH:i:s', $order->get_date_paid()->getTimestamp()) : null,
            'billing' => ['email' => $order->get_billing_email()], 'fulfillment_revision' => self::fulfillment_revision($order)];
    }

    private static function fulfillment_revision($order): string
    {
        $fields = ['status', 'currency', 'customer_id', 'billing', 'shipping', 'payment_method', 'transaction_id',
            'discount_total', 'discount_tax', 'shipping_total', 'shipping_tax', 'cart_tax', 'total', 'total_tax', 'customer_note'];
        $state = array_intersect_key($order->get_data(), array_flip($fields));
        $state['date_paid'] = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : null;
        $state['items'] = [];
        foreach ($order->get_items(['line_item', 'fee', 'shipping', 'coupon', 'tax']) as $id => $item) {
            $data = $item->get_data();
            $data['meta_data'] = array_map(static fn ($meta) => $meta->get_data(), $item->get_meta_data());
            $state['items'][(string) $id] = $data;
        }
        // Canonical keys avoid data-store array ordering differences. Integration
        // order metadata and modified dates are deliberately not part of this hash.
        $canonicalize = static function ($value) use (&$canonicalize) {
            if (! is_array($value)) {
                return $value;
            }
            ksort($value);

            return array_map($canonicalize, $value);
        };

        return hash('sha256', wp_json_encode($canonicalize($state)));
    }

    private static function eligible($order, string $expected, string $revision)
    {
        if ($order->get_status() !== 'processing' || ! $order->is_paid() || ! $order->get_date_paid()) {
            return new WP_Error('wphub_changed_order', 'Only verified paid processing orders can be completed.', ['status' => 409]);
        }
        if ($expected === '' || ! hash_equals(self::modified($order), $expected)) {
            return new WP_Error('wphub_changed_order', 'The store order changed. Refresh it and review before retrying.', ['status' => 409]);
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $revision) || ! hash_equals(self::fulfillment_revision($order), $revision)) {
            return new WP_Error('wphub_changed_order', 'The order customer, items or payment details changed. Refresh and review before retrying.', ['status' => 409]);
        }

        return true;
    }

    public static function receipt_route($request)
    {
        return $request->get_method() === 'GET' ? self::receipt($request)
            : self::with_order_lock((int) $request['id'], static fn () => self::receipt($request));
    }

    public static function complete_route($request)
    {
        return self::with_order_lock((int) $request['id'], static fn () => self::complete($request));
    }

    private static function with_order_lock(int $id, callable $callback)
    {
        global $wpdb;
        // MySQL advisory locks serialize this plugin's retries without writing postmeta
        // directly; both HPOS and legacy order stores continue using WC CRUD below.
        $key = 'wphub-docs-'.substr(hash('sha256', $wpdb->prefix.':'.$id), 0, 40);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $key)) !== '1') {
            return new WP_Error('wphub_busy', 'This order is already synchronizing. Retry shortly.', ['status' => 409]);
        }
        $previous_timeout = null;
        try {
            if (! defined('WC_VERSION') || version_compare(WC_VERSION, '8.8', '<')) {
                return new WP_Error('wphub_version', 'WooCommerce 8.8 or newer is required.', ['status' => 409]);
            }
            $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
                && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            $table = $hpos ? $wpdb->prefix.'wc_orders' : $wpdb->posts;
            if (! self::transactional_tables($hpos)) {
                return new WP_Error('wphub_database', 'Transactional InnoDB order, metadata and note tables are required for safe completion.', ['status' => 409]);
            }
            $previous_timeout = (int) $wpdb->get_var('SELECT @@SESSION.innodb_lock_wait_timeout');
            $wpdb->query('SET SESSION innodb_lock_wait_timeout = 5');
            wc_transaction_query('start', true);
            if ($wpdb->last_error) {
                throw new RuntimeException('Order transaction could not start.');
            }
            // Row locking also serializes ordinary WooCommerce writes. Metadata and
            // status writes below always use WooCommerce CRUD, never SQL/postmeta writes.
            $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE id = %d FOR UPDATE", $id));
            if ($wpdb->last_error) {
                throw new RuntimeException('Order row lock failed.');
            }
            self::forget_order_cache($id, $hpos);
            $result = $callback();
            wc_transaction_query(is_wp_error($result) ? 'rollback' : 'commit', true);
            if ($wpdb->last_error) {
                throw new RuntimeException('Order transaction could not finish.');
            }
            if (is_wp_error($result)) {
                // CRUD can cache an unsaved status or receipt before a transaction fails.
                self::forget_order_cache($id, $hpos);
            }

            return $result;
        } catch (Throwable $exception) {
            wc_transaction_query('rollback', true);
            if (isset($hpos)) {
                self::forget_order_cache($id, $hpos);
            }

            return new WP_Error('wphub_sync', 'The store could not finish order synchronization. Review and retry.', ['status' => 500]);
        } finally {
            if ($previous_timeout !== null) {
                $wpdb->query('SET SESSION innodb_lock_wait_timeout = '.max(1, $previous_timeout));
            }
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
        }
    }

    private static function forget_order_cache(int $id, bool $hpos): void
    {
        if (class_exists(\Automattic\WooCommerce\Caches\OrderCache::class)) {
            wc_get_container()->get(\Automattic\WooCommerce\Caches\OrderCache::class)->remove($id);
        }
        if ($hpos && class_exists(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class)) {
            $store = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::class);
            if (is_callable([$store, 'clear_cached_data'])) {
                $store->clear_cached_data([$id]);
            }
        } else {
            clean_post_cache($id);
        }
    }

    private static function save_receipt_meta($order): void
    {
        // HPOS 8.8+ normally promotes metadata-only changes to a full order save.
        // These integration receipts must not change the optimistic order timestamp.
        $skip = static fn () => false;
        add_filter('woocommerce_orders_table_datastore_should_save_after_meta_change', $skip, PHP_INT_MAX);
        try {
            $order->save_meta_data();
        } finally {
            remove_filter('woocommerce_orders_table_datastore_should_save_after_meta_change', $skip, PHP_INT_MAX);
        }
    }

    public static function receipt($request)
    {
        $order = self::order($request['id']);
        if (is_wp_error($order)) {
            return $order;
        }
        $stored = $order->get_meta(self::META, true);
        if ($request->get_method() === 'GET') {
            return self::receipt_response($order, $stored);
        }
        $config = get_option(self::OPTION, []);
        if (empty($config['enabled'])) {
            return new WP_Error('wphub_disabled', 'The document workflow is not enabled on this store.', ['status' => 409]);
        }
        $body = $request->get_json_params();
        if ((string) ($body['config_version'] ?? '') !== (string) ($config['config_version'] ?? '') || empty($config['config_version'])) {
            return new WP_Error('wphub_changed_configuration', 'The store brand configuration changed. Review before completing.', ['status' => 409]);
        }
        if (! is_array($body) || ! preg_match('/^[a-zA-Z0-9-]{20,80}$/', (string) ($body['receipt_id'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($body['document_set_version'] ?? ''))
            || empty($body['provider_message_id']) || strlen((string) $body['provider_message_id']) > 255
            || empty($body['sent_at']) || ! strtotime($body['sent_at']) || strtotime($body['sent_at']) > time() + 300
            || ($body['fulfillment_confirmed'] ?? false) !== true || (int) ($body['attachment_count'] ?? 0) < 1) {
            return new WP_Error('wphub_receipt', 'A successful full document delivery receipt is required.', ['status' => 422]);
        }
        if (is_array($stored) && ! empty($stored['receipt_id'])) {
            if ($stored['receipt_id'] === $body['receipt_id'] && $stored['document_set_version'] === $body['document_set_version']
                && $stored['provider_message_id'] === $body['provider_message_id'] && $stored['website_identity'] === $config['website_identity']) {
                return self::receipt_response($order, $stored);
            }

            return new WP_Error('wphub_receipt_conflict', 'Another fulfillment receipt already exists. Review the order.', ['status' => 409]);
        }
        $eligible = self::eligible($order, (string) ($body['expected_modified_gmt'] ?? ''), (string) ($body['expected_fulfillment_revision'] ?? ''));
        if (is_wp_error($eligible)) {
            return $eligible;
        }
        $receipt = [
            'schema' => 1, 'website_identity' => $config['website_identity'], 'order_id' => $order->get_id(),
            'receipt_id' => $body['receipt_id'], 'provider_message_id' => sanitize_text_field($body['provider_message_id']),
            'sent_at' => sanitize_text_field($body['sent_at']), 'document_set_version' => $body['document_set_version'],
            'attachment_count' => (int) $body['attachment_count'], 'fulfillment_confirmed' => true,
            'expected_modified_gmt' => $body['expected_modified_gmt'], 'complete' => false,
            'expected_fulfillment_revision' => $body['expected_fulfillment_revision'],
            'config_version' => $body['config_version'],
        ];
        $order->update_meta_data(self::META, $receipt);
        self::save_receipt_meta($order);
        $order->read_meta_data(true);

        return self::receipt_response($order, $order->get_meta(self::META, true));
    }

    private static function receipt_response($order, $receipt): array
    {
        return ['verified' => self::valid_receipt($order, $receipt), 'receipt_id' => is_array($receipt) ? ($receipt['receipt_id'] ?? null) : null,
            'document_set_version' => is_array($receipt) ? ($receipt['document_set_version'] ?? null) : null,
            'status' => $order->get_status(), 'modified_gmt' => self::modified($order)];
    }

    private static function valid_receipt($order, $receipt): bool
    {
        $config = get_option(self::OPTION, []);

        return is_array($receipt) && ($receipt['schema'] ?? null) === 1
            && ! empty($config['website_identity']) && ($receipt['website_identity'] ?? '') === $config['website_identity']
            && (int) ($receipt['order_id'] ?? 0) === $order->get_id()
            && ! empty($receipt['receipt_id']) && ! empty($receipt['provider_message_id']) && ! empty($receipt['sent_at'])
            && ($receipt['fulfillment_confirmed'] ?? false) === true && (int) ($receipt['attachment_count'] ?? 0) > 0
            && preg_match('/^[a-f0-9]{64}$/', (string) ($receipt['document_set_version'] ?? '')) === 1;
    }

    public static function complete($request)
    {
        $order = self::order($request['id']);
        if (is_wp_error($order)) {
            return $order;
        }
        $receipt = $order->get_meta(self::META, true);
        if (! self::valid_receipt($order, $receipt) || $receipt['receipt_id'] !== (string) $request['receipt_id']) {
            return new WP_Error('wphub_receipt_required', 'The verified document receipt must be saved first.', ['status' => 409]);
        }
        if ($order->get_status() === 'completed' && ! empty($receipt['complete'])) {
            return self::receipt_response($order, $receipt);
        }
        if (empty(get_option(self::OPTION, [])['enabled'])) {
            return new WP_Error('wphub_disabled', 'The document workflow is disabled.', ['status' => 409]);
        }
        if ((string) ($receipt['config_version'] ?? '') !== (string) (get_option(self::OPTION, [])['config_version'] ?? '')) {
            return new WP_Error('wphub_changed_configuration', 'The store brand configuration changed. Review before completing.', ['status' => 409]);
        }
        $eligible = self::eligible($order, (string) $receipt['expected_modified_gmt'], (string) ($receipt['expected_fulfillment_revision'] ?? ''));
        if (is_wp_error($eligible)) {
            return $eligible;
        }
        // Persist and read the authorization BEFORE WooCommerce fires completion email hooks.
        $receipt['complete'] = true;
        $order->update_meta_data(self::META, $receipt);
        self::save_receipt_meta($order);
        $order->read_meta_data(true);
        if (! self::valid_receipt($order, $order->get_meta(self::META, true))) {
            return new WP_Error('wphub_receipt_required', 'Receipt verification failed.', ['status' => 409]);
        }
        if (! $order->update_status('completed')) {
            // WooCommerce catches save exceptions and returns false; do not commit
            // the suppression flag or announce success from the in-memory status.
            return new WP_Error('wphub_transition_failed', 'The order status could not be saved. Review and retry synchronization.', ['status' => 500]);
        }
        // Some WooCommerce CRUD save exceptions are caught internally and still
        // return an ID/true. Confirm the persisted state, not the mutated object.
        $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class)
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        self::forget_order_cache($order->get_id(), $hpos);
        $order = self::order($order->get_id());
        if (is_wp_error($order) || $order->get_status() !== 'completed'
            || ! self::valid_receipt($order, $order->get_meta(self::META, true))
            || empty($order->get_meta(self::META, true)['complete'])) {
            return new WP_Error('wphub_transition_failed', 'The persisted order status could not be verified. Review and retry synchronization.', ['status' => 500]);
        }
        if (! $order->get_meta('_wphub_documents_note_'.$receipt['receipt_id'], true)) {
            $order->add_order_note(sprintf('WP Hub documents sent. Receipt %s; document set %s; %d PDF attachment(s).',
                $receipt['receipt_id'], $receipt['document_set_version'], $receipt['attachment_count']), false, true);
            $order->update_meta_data('_wphub_documents_note_'.$receipt['receipt_id'], true);
            self::save_receipt_meta($order);
        }

        return self::receipt_response($order, $receipt);
    }

    public static function completion_enabled($enabled, $order, $email = null): bool
    {
        if (! $enabled || ! is_object($order) || ! is_a($order, 'WC_Order')) {
            return (bool) $enabled;
        }
        $receipt = $order->get_meta(self::META, true);

        return ! (self::valid_receipt($order, $receipt) && ! empty($receipt['complete']));
    }

    private static function context($order, $email): ?array
    {
        $config = get_option(self::OPTION, []);
        if (empty($config['enabled']) || ! is_object($order) || ! is_a($order, 'WC_Order')
            || ! in_array($email->id ?? '', ['customer_processing_order', 'customer_on_hold_order'], true)) {
            return null;
        }
        $language = $config['default_language'] ?? 'en';
        foreach (['_wphub_language', '_order_locale', '_order_language', 'wpml_language', '_wpml_language', 'pll_language'] as $key) {
            $recorded = $order->get_meta($key, true);
            $candidate = is_string($recorded) ? strtolower(substr($recorded, 0, 2)) : '';
            if (in_array($candidate, ['fr', 'en'], true)) {
                $language = $candidate;
                break;
            }
        }
        $paid = $order->is_paid() && (bool) $order->get_date_paid();
        $values = ['brand_name' => $config['brand_name'] ?? '', 'customer_first_name' => $order->get_billing_first_name(),
            'order_number' => $order->get_order_number(), 'support_email' => $config['support_email'] ?? '', 'website_url' => $config['website_url'] ?? '',
            'document_count' => '0', 'document_list' => '', 'service_label' => $config['service_label'] ?? ''];

        return compact('config', 'language', 'paid', 'values');
    }

    private static function replace(string $text, array $values): string
    {
        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn ($match) => (string) ($values[$match[1]] ?? ''), $text);
    }

    public static function subject($subject, $order, $email = null): string
    {
        $context = self::context($order, $email);
        if (! $context) {
            return $subject;
        }
        extract($context);
        $text = $config['templates'][$language]['subject'] ?? '';
        if (! $paid || $text === '') {
            $text = $language === 'fr' ? '{{brand_name}} — Commande {{order_number}} reçue' : '{{brand_name}} — Order {{order_number}} received';
        }

        return preg_replace('/[\x00-\x1f\x7f]/u', ' ', self::replace($text, $values));
    }

    public static function heading($heading, $order, $email = null): string
    {
        $context = self::context($order, $email);
        if (! $context) {
            return $heading;
        }
        extract($context);
        $text = $paid ? ($config['templates'][$language]['heading'] ?? '') : '';

        return esc_html(self::replace($text ?: ($language === 'fr' ? 'Votre commande est reçue' : 'Your order has been received'), $values));
    }

    public static function acknowledgement($order, $sent_to_admin, $plain_text, $email): void
    {
        $context = self::context($order, $email);
        if ($sent_to_admin || ! $context) {
            return;
        }
        extract($context);
        if ($paid) {
            $text = $config['templates'][$language]['body'] ?? '';
            $text = $text ?: ($language === 'fr'
                ? "Bonjour {{customer_first_name}},\n\nVotre paiement a bien été reçu. Nous préparons vos documents.\n\nCet e-mail confirme votre commande. Il ne contient pas encore vos documents. Vous les recevrez dans un prochain e-mail intitulé « {{brand_name}} — Vos documents PDF | Commande {{order_number}} ».\n\nUne question ? Contactez {{support_email}}."
                : "Hello {{customer_first_name}},\n\nYour payment has been received. We are preparing your documents.\n\nThis email confirms your order. Your documents are not attached yet. They will arrive in a separate email titled “{{brand_name}} — Your PDF documents | Order {{order_number}}”.\n\nQuestions? Contact {{support_email}}.");
        } else {
            $text = $language === 'fr'
                ? "Bonjour {{customer_first_name}},\n\nVotre commande a été reçue. Le paiement n’est pas encore confirmé. Cet e-mail ne contient pas vos documents. Consultez les informations de paiement de votre commande ci-dessous.\n\nUne question ? Contactez {{support_email}}."
                : "Hello {{customer_first_name}},\n\nYour order has been received. Payment has not yet been confirmed. This email does not contain your documents. Please review the order and payment information below.\n\nQuestions? Contact {{support_email}}.";
        }
        $text = self::replace($text, $values);
        $footer = self::replace((string) ($config['footer'] ?? ''), $values);
        $optional = $paid ? self::replace((string) ($config['templates'][$language]['optional_block'] ?? ''), $values) : '';
        $preheader = $paid ? self::replace((string) ($config['templates'][$language]['preheader'] ?? ''), $values) : '';
        if ($plain_text) {
            echo $config['brand_name']."\n\n".$text.($optional !== '' ? "\n\n".$optional : '').($footer !== '' ? "\n\n".$footer : '')."\n\n";

            return;
        }
        echo '<div style="margin-bottom:24px;font-family:Arial,sans-serif;line-height:1.6">';
        if ($preheader !== '') {
            echo '<div style="display:none;max-height:0;overflow:hidden;opacity:0">'.esc_html($preheader).'</div>';
        }
        if (! empty($config['logo_url'])) {
            echo '<img src="'.esc_url($config['logo_url']).'" alt="'.esc_attr($config['brand_name']).'" style="max-width:180px;max-height:72px;margin-bottom:16px" />';
        }
        echo '<p><strong>'.esc_html($config['brand_name']).'</strong></p><p>'.nl2br(esc_html($text)).'</p>';
        if ($optional !== '') {
            echo '<p>'.nl2br(esc_html($optional)).'</p>';
        }
        if ($footer !== '') {
            echo '<p style="font-size:12px">'.nl2br(esc_html($footer)).'</p>';
        }
        echo '</div>';
    }

    public static function styles($css, $email): string
    {
        $config = get_option(self::OPTION, []);
        if (empty($config['enabled']) || ! in_array($email->id ?? '', ['customer_processing_order', 'customer_on_hold_order'], true)) {
            return $css;
        }
        $color = sanitize_hex_color($config['primary_color'] ?? '');
        $background = sanitize_hex_color($config['background_color'] ?? '');
        $text = sanitize_hex_color($config['text_color'] ?? '');
        $headerText = '#ffffff';
        if ($color) {
            $hex = ltrim($color, '#');
            $hex = strlen($hex) === 3 ? preg_replace('/(.)/', '$1$1', $hex) : $hex;
            $channels = array_map(static function ($channel) {
                $value = hexdec($channel) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }, str_split($hex, 2));
            $luminance = $channels[0] * 0.2126 + $channels[1] * 0.7152 + $channels[2] * 0.0722;
            // Choose the stronger WCAG contrast. Woo's newer templates otherwise
            // retain their dark heading text when a branded dark header is used.
            $headerText = ($luminance + 0.05) / 0.05 >= 1.05 / ($luminance + 0.05) ? '#000000' : '#ffffff';
        }

        // Appended after WooCommerce's defaults, and only for our two acknowledgement
        // types. Its classic mobile stylesheet otherwise shrinks the body to 10px.
        return $css.'#body_content_inner { font-size:16px; line-height:1.6; }'
            .($color ? '#template_header { background-color:'.$color.'; }'
                .'#template_header, #template_header h1, #template_header h1 a { color:'.$headerText.'; }'
                .'#body_content_inner h2, #body_content_inner h3, #body_content_inner a, #body_content_inner .link { color:'.$color.'; }' : '')
            .($background ? '#wrapper { background-color:'.$background.'; }' : '')
            .($text ? '#body_content_inner, #body_content_inner .td, #body_content_inner .address { color:'.$text.'; }' : '')
            .'@media screen and (max-width: 600px) { #body_content_inner { font-size:16px !important; line-height:1.6 !important; } }';
    }

    public static function headers($headers, $email_id, $order, $email = null): string
    {
        $config = get_option(self::OPTION, []);
        if (empty($config['enabled']) || ! in_array($email_id, ['customer_processing_order', 'customer_on_hold_order'], true)) {
            return $headers;
        }
        $reply = sanitize_email($config['reply_to'] ?? '');
        if ($reply !== '') {
            $headers = preg_replace('/^Reply-To:.*(?:\r?\n|$)/mi', '', $headers);
            $headers = rtrim($headers)."\r\nReply-To: ".$reply."\r\n";
        }

        return $headers;
    }

    public static function from_name($name, $email): string
    {
        $config = get_option(self::OPTION, []);

        return ! empty($config['enabled']) && in_array($email->id ?? '', ['customer_processing_order', 'customer_on_hold_order'], true)
            && ! empty($config['sender_name']) ? $config['sender_name'] : $name;
    }

    public static function admin_menu(): void
    {
        add_submenu_page('woocommerce', 'WP Hub Documents', 'WP Hub Documents', 'manage_woocommerce', 'wphub-documents', [self::class, 'settings']);
    }

    public static function settings(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $config = get_option(self::OPTION, []);
        if (isset($_POST['wphub_pairing'])) {
            check_admin_referer('wphub_documents_pairing');
            $identity = sanitize_text_field(wp_unslash($_POST['wphub_pairing']));
            if (preg_match('/^[a-f0-9-]{36}$/i', $identity)) {
                // Re-pairing resets branding and activation; it never enables a store implicitly.
                if (($config['website_identity'] ?? '') !== $identity) {
                    $config = ['website_identity' => $identity, 'enabled' => false];
                    update_option(self::OPTION, $config, false);
                }
                echo '<div class="notice notice-success"><p>Pairing saved. Verify and activate this website from WP Hub.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Enter the website pairing UUID shown in WP Hub.</p></div>';
            }
        }
        echo '<div class="wrap"><h1>WP Hub Documents</h1><p>Copy the pairing ID from this website’s Email &amp; Documents settings in WP Hub. This identifies the website; requests still require a WooCommerce API key.</p><form method="post">';
        wp_nonce_field('wphub_documents_pairing');
        echo '<label>Website pairing ID <input name="wphub_pairing" class="regular-text" value="'.esc_attr($config['website_identity'] ?? '').'" required /></label>';
        submit_button('Save pairing');
        echo '</form><p>Workflow: '.(! empty($config['enabled']) ? 'Enabled' : 'Disabled').'. Brand configuration is stored locally; checkout emails do not contact WP Hub.</p></div>';
    }
}
