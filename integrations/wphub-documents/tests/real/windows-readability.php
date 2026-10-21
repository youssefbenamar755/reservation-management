<?php
/**
 * Windows sandbox PHP reports is_readable=false even when fopen succeeds.
 * CLI must disable the native predicate before loading this file. The replacement
 * proves actual read access through fopen/opendir, instead of changing WooCommerce.
 * No CRUD, HTTP, mail, cache, transaction or permission implementation is mocked.
 * Official downloaded WordPress/WooCommerce files remain unmodified.
 */
if (function_exists('is_readable')) {
    throw new RuntimeException('Windows fixture requires -d disable_functions=mail,is_readable.');
}
if (! function_exists('is_readable')) {
function is_readable($path): bool {
    if (! is_string($path) || ! str_starts_with(strtolower(str_replace('\\', '/', $path)), strtolower(str_replace('\\', '/', WPHUB_FIXTURE_ROOT)).'/')) {
        return false;
    }
    if (is_dir($path)) {
        $directory = @opendir($path);
        if (! $directory) {
            return false;
        }
        closedir($directory);
        return true;
    }
    $stream = @fopen($path, 'rb');
    if (! $stream) {
        return false;
    }
    fclose($stream);
    return true;
}
}
