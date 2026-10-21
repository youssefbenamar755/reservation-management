<?php

namespace App\Services;

class PrivateStoragePath
{
    public static function isPublic(string $path): bool
    {
        if ($path === '' || (! str_starts_with($path, '/') && ! preg_match('~^[A-Za-z]:[\\\\/]~', $path))) {
            return true;
        }
        $publicRoots = [public_path(), storage_path('app/public')];
        foreach (config('filesystems.links', []) as $link => $target) {
            // Judge the served link's lexical location before following it out of public/.
            if (self::within((string) $link, public_path(), false)) {
                $publicRoots[] = $target;
            }
        }
        foreach ($publicRoots as $root) {
            if (self::within($path, (string) $root)) {
                return true;
            }
        }

        return false;
    }

    private static function within(string $path, string $root, bool $resolve = true): bool
    {
        $path = self::normalize($path, $resolve);
        $root = self::normalize($root, $resolve);

        return $path === $root || str_starts_with($path, $root.'/');
    }

    private static function normalize(string $value, bool $resolve): string
    {
        // Resolve the nearest existing ancestor too, so a new child of a public symlink is rejected.
        $remaining = [];
        $ancestor = $value;
        $resolved = false;
        if ($resolve) {
            while (($resolved = realpath($ancestor)) === false && dirname($ancestor) !== $ancestor) {
                array_unshift($remaining, basename($ancestor));
                $ancestor = dirname($ancestor);
            }
        }
        $value = str_replace('\\', '/', $resolved !== false ? $resolved.'/'.implode('/', $remaining) : $value);
        $parts = [];
        foreach (explode('/', $value) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.') {
                $parts[] = $part;
            }
        }
        $value = rtrim(implode('/', $parts), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
    }
}
