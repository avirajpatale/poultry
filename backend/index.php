<?php
/**
 * Front controller — every /api/* request is routed here
 * (via .htaccess on Apache, or router.php under `php -S`).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

// Normalise: strip any directory prefix up to and including "/api"
$pos = strpos($path, '/api');
$route = $pos === false ? '/' : substr($path, $pos + 4);
$route = '/' . trim($route, '/');

// Body for JSON requests
$body = [];
$raw  = file_get_contents('php://input');
if ($raw !== '' && $raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $body = $decoded;
}
$request = [
    'method' => $method,
    'route'  => $route,
    'query'  => $_GET,
    'body'   => $body,
];

$routes = require __DIR__ . '/routes/api.php';

try {
    foreach ($routes as [$rMethod, $pattern, $handler, $permission]) {
        if ($rMethod !== $method) continue;

        // /sales/{id}/cancel → regex
        $regex = preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern);
        if (!preg_match('#^' . $regex . '$#', $route, $m)) continue;

        $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
        AuthMiddleware::handle($permission);

        [$class, $fn] = $handler;
        $controller = new $class();
        $result = $controller->$fn($request, $params);
        if ($result !== null) {
            Response::ok($result);
        }
        // controllers that already responded (Response::ok/created/csv) never reach here
        Response::ok(null);
    }

    Response::error('Not found: ' . $method . ' ' . $route, 404);
} catch (ApiException $e) {
    Response::error($e->getMessage(), $e->getStatus(), $e->getPayload());
} catch (PDOException $e) {
    error_log('[DB] ' . $e->getMessage());
    $msg = APP_ENV === 'development' ? $e->getMessage() : 'Database error';
    Response::error($msg, 500);
}
