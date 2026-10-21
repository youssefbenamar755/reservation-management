<?php
/**
 * Plugin Name: WP Hub Documents
 * Description: Per-store acknowledgements and verified, order-specific document completion receipts for WP Hub.
 * Version: 1.0.0
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * WC requires at least: 8.8
 * License: MIT
 */

defined('ABSPATH') || exit;

require_once __DIR__.'/class-wphub-documents.php';

add_action('before_woocommerce_init', static function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
add_action('plugins_loaded', static function () {
    if (class_exists('WooCommerce')) {
        WPHub_Documents::boot();
    }
});
