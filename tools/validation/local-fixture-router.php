<?php

// php -d disable_functions=mail -S 127.0.0.1:8799 tools/validation/local-fixture-router.php
// Always browse http://localhost:8799; keep stdout/stderr private because OAuth URLs are sensitive.
// WPHUB_LOCAL_FIXTURE_PATH must point to a freshly created, private fixture directory.

if (PHP_SAPI !== 'cli-server' || ($_SERVER['HTTP_HOST'] ?? '') !== 'localhost:8799'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This validation fixture is available only at http://localhost:8799.');
}

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$repository = dirname(__DIR__, 2);
// A requested existing repository PHP file can change the development server's SCRIPT_NAME.
// Do not route source/config paths into Laravel, regardless of how PHP interprets that script.
if (str_contains($path, "\0") || str_contains($path, '\\')
    || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path)
    || preg_match('#^/(?:app|bootstrap|config|database|docs|integrations|node_modules|public|resources|routes|scripts|storage|tests|tools|vendor)(?:/|$)#i', $path)
    || preg_match('#\.(?:php[0-9]*|phtml|phar|json|lock|env|ini|sql|sqlite|log|md|ya?ml|toml)(?:/|$)#i', $path)
    || str_starts_with($path, '/.')) {
    http_response_code(404);
    exit;
}
// Never hand the request back to PHP's static server: its working directory contains source/secrets.
// Only built client assets and two known icons can be served directly.
if (preg_match('#^/build/assets/[A-Za-z0-9_.-]+\.(?:js|css|woff2?|png|jpe?g|svg|webp)$#', $path)
    || in_array($path, ['/favicon.ico', '/favicon.svg'], true)) {
    $file = realpath($repository.'/public'.$path);
    $public = str_replace('\\', '/', realpath($repository.'/public')).'/';
    if (! $file || ! str_starts_with(str_replace('\\', '/', $file), $public) || ! is_file($file)) {
        http_response_code(404);
        exit;
    }
    $types = ['js' => 'application/javascript', 'css' => 'text/css', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];
    header('Content-Type: '.$types[pathinfo($file, PATHINFO_EXTENSION)]);
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}
// Preserve REQUEST_URI/query parameters while always using the normal front-controller identity.
// Otherwise Symfony can treat the requested existing source path as its base URL and route '/'.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $repository.'/public/index.php';
$_SERVER['DOCUMENT_ROOT'] = $repository.'/public';
unset($_SERVER['PATH_INFO'], $_SERVER['ORIG_PATH_INFO']);
require __DIR__.'/local-fixture-bootstrap.php';
[$app] = wphubLocalFixture((string) getenv('WPHUB_LOCAL_FIXTURE_PATH'));
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = \Illuminate\Http\Request::capture();
$response = $kernel->handle($request);
// Compiled assets may contain an old browser telemetry key. Prevent all browser connections off-origin.
$response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'");
$response->headers->set('X-WPHub-Validation', 'fictional-local-fixture');
$response->headers->set('Cache-Control', 'private, no-store');
$response->send();
$kernel->terminate($request, $response);
