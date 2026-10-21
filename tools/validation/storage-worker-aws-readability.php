<?php

namespace Aws;

// Fixture-only workaround for Windows sandbox metadata false negatives.
// The real SDK model is still opened and loaded; no request or response is replaced here.
function is_readable(string $path): bool
{
    if (\is_readable($path)) {
        return true;
    }
    $base = realpath(dirname(__DIR__, 2).'/vendor/aws/aws-sdk-php/src/data');
    $real = realpath($path);
    if ($base === false || $real === false || ! str_starts_with(strtolower(str_replace('\\', '/', $real)), strtolower(str_replace('\\', '/', $base)).'/')) {
        return false;
    }
    $handle = @fopen($real, 'rb');
    if ($handle === false) {
        return false;
    }
    fclose($handle);
    return true;
}
