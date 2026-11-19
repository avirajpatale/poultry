<?php
/**
 * Router for the PHP built-in web server:
 *   php -S localhost:8000 -t backend backend/router.php
 * Static files are served directly; everything else hits index.php.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/index.php';
