<?php

// Router for PHP's built-in dev server only (php -S ... -t public router.php).
// Without this, an explicit router script intercepts every request -- including ones for
// files that already exist on disk (CSS/JS/images) -- and hands them to Symfony's front
// controller, which then fails trying to treat the static file as if it were index.php.
// Returning false here tells the built-in server "serve this file yourself as-is".
if (PHP_SAPI === 'cli-server') {
    $path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $file = __DIR__ . '/public' . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

require __DIR__ . '/public/index.php';
