<?php

/** Fictional, loopback-only rehearsal. This cannot run against a live store. */
declare(strict_types=1);

$fixture = realpath((string) getenv('WPHUB_FIXTURE_ROOT'));
$phase = $argv[1] ?? '';
$schema = $argv[2] ?? 'wphub_fixture_hpos';
if (PHP_SAPI !== 'cli' || function_exists('mail') || ! $fixture || ! is_file($fixture.'/FICTIONAL-ONLY')
    || getenv('WPHUB_VALIDATION_MODE') !== 'true' || getenv('WPHUB_VALIDATION_MAIL_TRANSPORT') !== 'capture'
    || getenv('WPHUB_FIXTURE_STORAGE') !== 'hpos'
    || ! in_array($schema, ['wphub_fixture_hpos', 'wphub_myisam_restore'], true)
    || ! in_array($phase, ['seed', 'inspect', 'reproduce', 'convert', 'health', 'rollback'], true)) {
    throw new RuntimeException('Only the new isolated MyISAM rehearsal is permitted.');
}
if ($schema !== 'wphub_fixture_hpos' && $phase !== 'inspect') {
    throw new RuntimeException('The independent restore is read-only to this probe.');
}
$pdo = new PDO('mysql:host=127.0.0.1;port=33177;dbname='.$schema.';charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => true,
]);
$identity = $pdo->query('SELECT @@datadir AS datadir, @@port AS port, DATABASE() AS db')->fetch(PDO::FETCH_ASSOC);
$normalize = static fn (string $value): string => strtolower(str_replace('\\', '/', rtrim($value, '/\\')).'/');
if ($normalize($identity['datadir']) !== $normalize($fixture.'/data') || $identity['port'] !== '33177' || $identity['db'] !== $schema) {
    throw new RuntimeException('Exact newly owned database directory, port and schema required.');
}
$suffixes = ['wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'posts', 'postmeta', 'comments', 'commentmeta'];
$targets = array_map(static fn (string $s): string => 'fixture_'.$s, $suffixes);
$json = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
function inspect_tables(PDO $pdo): array
{
    $result = [];
    $names = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($names as $table) {
        if (! preg_match('/^fixture_[a-z0-9_]+$/D', $table)) {
            throw new RuntimeException('Unexpected table identifier.');
        }
        $columns = $pdo->query('SHOW FULL COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $keys = $pdo->query('SHOW INDEX FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($keys as &$key) {
            unset($key['Cardinality']);
        } unset($key);
        usort($keys, static fn ($a, $b) => [$a['Key_name'], (int) $a['Seq_in_index']] <=> [$b['Key_name'], (int) $b['Seq_in_index']]);
        $rows = $pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $encoded = array_map(static fn ($row) => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $rows);
        sort($encoded, SORT_STRING);
        $status = $pdo->query('SELECT ENGINE, AUTO_INCREMENT, TABLE_COLLATION, DATA_LENGTH, INDEX_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='.$pdo->quote($table))->fetch(PDO::FETCH_ASSOC);
        $result[$table] = ['rows' => count($rows), 'row_sha256' => hash('sha256', implode("\n", $encoded)),
            'columns_sha256' => hash('sha256', json_encode($columns, JSON_THROW_ON_ERROR)),
            'indexes_sha256' => hash('sha256', json_encode($keys, JSON_THROW_ON_ERROR)),
            'engine' => $status['ENGINE'], 'auto_increment' => $status['AUTO_INCREMENT'], 'collation' => $status['TABLE_COLLATION'],
            'data_bytes' => (int) $status['DATA_LENGTH'], 'index_bytes' => (int) $status['INDEX_LENGTH']];
    }

    return $result;
}
function bootstrap_fixture(string $fixture): void
{
    define('WPHUB_FIXTURE_ROOT', $fixture);
    require dirname(__DIR__, 2).'/integrations/wphub-documents/tests/real/windows-readability.php';
    define('WP_USE_THEMES', false);
    define('WP_DISABLE_FATAL_ERROR_HANDLER', true);
    $_SERVER['HTTP_HOST'] = 'fixture.example.invalid';
    $_SERVER['REQUEST_URI'] = '/';
    require $fixture.'/wordpress/wp-load.php';
    if (DB_HOST !== '127.0.0.1:33177' || DB_NAME !== 'wphub_fixture_hpos'
        || get_option('home') !== 'https://fixture.example.invalid' || get_option('siteurl') !== 'https://fixture.example.invalid'
        || ! DISABLE_WP_CRON || ! WP_HTTP_BLOCK_EXTERNAL) {
        throw new RuntimeException('WordPress isolation mismatch.');
    }
}
if ($phase === 'seed') {
    if (is_file($fixture.'/myisam-seeded.json')) {
        throw new RuntimeException('One-shot fictional seed already exists.');
    }
    bootstrap_fixture($fixture);
    $order = wc_create_order();
    $order->set_billing_email('fixture-recipient@example.invalid');
    $order->set_billing_first_name('Fictional Unicode Élodie');
    $order->set_billing_last_name('Example');
    $order->set_billing_address_1('Fictional address');
    $order->set_shipping_first_name('Fictional');
    $order->set_currency('EUR');
    $order->set_total('12.34');
    $order->set_payment_method('fixture-no-gateway');
    $order->update_meta_data('_rehearsal', ['unicode' => 'Français 日本語', 'decimal' => '12.34']);
    $order->save();
    $note = $order->add_order_note('Fictional private note — no customer notification.', false);
    add_comment_meta($note, '_rehearsal', 'Private note metadata');
    $post = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'FICTIONAL conversion fixture', 'post_content' => 'Unicode café 日本語']);
    add_post_meta($post, '_rehearsal', ['nested' => ['value' => 'Preserve serialized metadata']]);
    file_put_contents($fixture.'/myisam-seeded.json', $json(['order' => $order->get_id(), 'note' => $note, 'post' => $post]));
    echo $json(['seeded' => true, 'real_mail_sent' => 0, 'payment_gateway_used' => false]);
} elseif ($phase === 'inspect') {
    echo $json(['schema' => $schema, 'database_version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'tables' => inspect_tables($pdo)]);
} elseif ($phase === 'reproduce' || $phase === 'convert') {
    if (! is_file($fixture.'/myisam-seeded.json')) {
        throw new RuntimeException('Explicit seed required.');
    }
    $from = $phase === 'reproduce' ? 'InnoDB' : 'MyISAM';
    $to = $phase === 'reproduce' ? 'MyISAM' : 'InnoDB';
    if (is_file($fixture.'/myisam-'.$phase.'.json')) {
        throw new RuntimeException('One-shot conversion phase already ran.');
    }
    if ($phase === 'convert' && ! is_file($fixture.'/myisam-backup-restore-verified.json')) {
        throw new RuntimeException('A verified independent restore must precede conversion.');
    }
    $before = inspect_tables($pdo);
    foreach ($targets as $table) {
        if (($before[$table]['engine'] ?? '') !== $from || $before[$table]['rows'] < 1) {
            throw new RuntimeException('Expected populated source engine missing.');
        }
    }
    $pdo->exec('SET SESSION lock_wait_timeout = 5');
    $timings = [];
    foreach ($targets as $table) {
        $start = microtime(true);
        // Explicit COPY + EXCLUSIVE: the fixture is quiescent; no claim of zero downtime.
        $pdo->exec('ALTER TABLE `'.$table.'` ENGINE='.$to.', ALGORITHM=COPY, LOCK=EXCLUSIVE');
        $warnings = $pdo->query('SHOW WARNINGS')->fetchAll(PDO::FETCH_ASSOC);
        if ($warnings) {
            throw new RuntimeException('Unexpected conversion warning; inspect the fictional private log.');
        }
        $timings[] = ['table' => $table, 'seconds' => round(microtime(true) - $start, 4), 'engine' => $to];
    }
    $after = inspect_tables($pdo);
    foreach ($before as $table => $facts) {
        foreach (['rows', 'row_sha256', 'columns_sha256', 'indexes_sha256', 'auto_increment', 'collation'] as $field) {
            if ($facts[$field] !== $after[$table][$field]) {
                throw new RuntimeException('Table fact changed: '.$table.' '.$field);
            }
        }
        if ($after[$table]['engine'] !== (in_array($table, $targets, true) ? $to : $facts['engine'])) {
            throw new RuntimeException('Unexpected engine change.');
        }
    }
    $result = ['phase' => $phase, 'verified' => true, 'converted_tables' => $timings, 'all_table_data_columns_indexes_preserved' => true, 'table_count' => count($before)];
    file_put_contents($fixture.'/myisam-'.$phase.'.json', $json($result));
    echo $json($result);
} elseif ($phase === 'health') {
    bootstrap_fixture($fixture);
    $health = WPHub_Documents::health(new WP_REST_Request);
    echo $json(['health' => $health, 'real_mail_sent' => 0]);
} elseif ($phase === 'rollback') {
    $before = inspect_tables($pdo);
    foreach ($targets as $table) {
        if (($before[$table]['engine'] ?? '') !== 'InnoDB') {
            throw new RuntimeException('All target tables must already be transactional.');
        }
    }
    $fields = ['wc_orders' => 'billing_email', 'wc_orders_meta' => 'meta_value', 'wc_order_addresses' => 'first_name',
        'wc_order_operational_data' => 'order_key', 'posts' => 'post_content', 'postmeta' => 'meta_value', 'comments' => 'comment_content', 'commentmeta' => 'meta_value'];
    $pdo->beginTransaction();
    $changed = [];
    foreach ($fields as $suffix => $field) {
        $table = 'fixture_'.$suffix;
        $affected = $pdo->exec('UPDATE `'.$table.'` SET `'.$field.'`='.$pdo->quote('FICTIONAL-ROLLBACK-PROBE').' LIMIT 1');
        if ($affected !== 1) {
            throw new RuntimeException('Expected fictional row mutation absent.');
        }
        $changed[] = $table;
    }
    $pdo->rollBack();
    $after = inspect_tables($pdo);
    foreach ($before as $table => $facts) {
        if ($facts['row_sha256'] !== $after[$table]['row_sha256'] || $facts['rows'] !== $after[$table]['rows']) {
            throw new RuntimeException('Rollback failed.');
        }
    }
    echo $json(['rollback_verified' => true, 'tables_changed_and_rolled_back' => $changed, 'real_mail_sent' => 0]);
}
