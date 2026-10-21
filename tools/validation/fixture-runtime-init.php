<?php

/** Install real Woo schema/roles in a separate process before loading the fixture test manager. */
$fixtureRoot = realpath((string) getenv('WPHUB_FIXTURE_ROOT'));
$storageMode = getenv('WPHUB_FIXTURE_STORAGE');
if (PHP_SAPI !== 'cli' || function_exists('mail') || ! $fixtureRoot || ! is_file($fixtureRoot.'/FICTIONAL-ONLY')
    || ! in_array($storageMode, ['hpos', 'legacy'], true) || getenv('WPHUB_VALIDATION_MAIL_TRANSPORT') !== 'capture') {
    throw new RuntimeException('Only the new isolated capture fixture may initialize.');
}
define('WPHUB_FIXTURE_ROOT', $fixtureRoot);
require dirname(__DIR__, 2).'/integrations/wphub-documents/tests/real/windows-readability.php';
define('WP_USE_THEMES', false);
define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
$_SERVER['HTTP_HOST'] = 'fixture.example.invalid';
$_SERVER['REQUEST_URI'] = '/';
require $fixtureRoot.'/wordpress/wp-load.php';
global $wpdb;
if (DB_HOST !== '127.0.0.1:33177' || 'wphub_fixture_'.$storageMode !== DB_NAME
    || strtolower(rtrim(str_replace('\\', '/', $wpdb->get_var('SELECT @@datadir')), '/').'/')
        !== strtolower(str_replace('\\', '/', $fixtureRoot).'/data/')) {
    throw new RuntimeException('Exact owned fixture database required.');
}
WC_Install::install();
WC_Install::create_roles();
// WP_Roles::add_cap persists roles but does not rebuild existing WP_Role
// objects in this bootstrap. Reload through the native API before inspecting
// the normal installed manager, as the next HTTP request would do naturally.
wp_roles()->for_site();
WC_Install::create_tables();
wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class)->create_database_tables();
update_option('woocommerce_feature_email_improvements_enabled', 'yes');
$manager = get_user_by('login', 'fixture_admin');
if (! $manager || (int) $manager->ID !== 1 || ! in_array('administrator', $manager->roles, true)) {
    throw new RuntimeException('Expected original fictional installer manager missing.');
}
// Refresh only the fixture user's role-derived capability cache after normal
// Woo installation. No capability is granted or authorization bypassed.
$manager->get_role_caps();
if (! $manager->has_cap('manage_woocommerce')) {
    throw new RuntimeException('Normal Woo installation did not establish the manager capability.');
}
echo "Actual Woo schema and normal administrator capability initialized for fresh test bootstrap.\n";
