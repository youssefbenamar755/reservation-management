<?php
/** Initializes only the separately created fictional database, never a staging copy. */
$fixture = realpath((string) getenv('WPHUB_FIXTURE_ROOT'));
$mode = getenv('WPHUB_FIXTURE_STORAGE');
if (PHP_SAPI !== 'cli' || getenv('WPHUB_VALIDATION_MODE') !== 'true' || getenv('WPHUB_VALIDATION_MAIL_TRANSPORT') !== 'capture'
    || ! $fixture || ! is_file($fixture.'/FICTIONAL-ONLY') || ! in_array($mode, ['hpos', 'legacy'], true)) {
    throw new RuntimeException('Only the explicitly initialized fictional fixture is allowed.');
}
define('WPHUB_FIXTURE_ROOT', $fixture);
if (DIRECTORY_SEPARATOR === '\\') {
    require __DIR__.'/windows-readability.php';
}
define('WP_INSTALLING', true);
define('WP_USE_THEMES', false);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require $fixture.'/wordpress/wp-load.php';
if (DB_HOST !== '127.0.0.1:33177' || DB_NAME !== 'wphub_fixture_'.$mode) {
    throw new RuntimeException('Fixture database guard rejected an unexpected database.');
}
require_once ABSPATH.'wp-admin/includes/upgrade.php';
if (! is_blog_installed()) {
    wp_install('FICTIONAL WP Hub validation', 'fixture_admin', 'fixture-recipient@example.invalid', false, '', bin2hex(random_bytes(24)));
}
$pluginDirectory = ABSPATH.'wp-content/plugins/wphub-documents';
if (! is_dir($pluginDirectory)) {
    mkdir($pluginDirectory, 0700, true);
}
foreach (['wphub-documents.php', 'class-wphub-documents.php'] as $file) {
    copy(dirname(__DIR__, 2).'/'.$file, $pluginDirectory.'/'.$file);
}
update_option('active_plugins', ['woocommerce/woocommerce.php', 'wphub-documents/wphub-documents.php']);
update_option('woocommerce_custom_orders_table_enabled', $mode === 'hpos' ? 'yes' : 'no');
update_option('woocommerce_custom_orders_table_data_sync_enabled', 'no');
update_option('woocommerce_email_from_address', 'fixture-sender@example.invalid');
update_option('woocommerce_email_from_name', 'Fictional fixture');
update_option('admin_email', 'fixture-recipient@example.invalid');
echo "Fictional $mode installation prepared with only WooCommerce and WP Hub Documents active.\n";
