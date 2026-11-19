<?php
/**
 * Global configuration: env, session, CORS, error handling, autoloader.
 */

declare(strict_types=1);

// ---------- Database credentials (override via environment) ----------
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'poultry_farm');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'root');

// ---------- App ----------
define('APP_ENV', getenv('APP_ENV') ?: 'development');
define('BASE_PATH', dirname(__DIR__));

// Origins allowed to call the API with credentials (session cookie).
// The Vite dev server proxies /api, so same-origin is the common case;
// list extra origins here if the SPA is hosted on another port/domain.
define('ALLOWED_ORIGINS', array_filter(array_map('trim', explode(',',
    getenv('ALLOWED_ORIGINS') ?: 'http://localhost:5173,http://127.0.0.1:5173'
))));

// ---------- Error handling ----------
error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e) {
    error_log('[API] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'success' => false,
        'error'   => APP_ENV === 'development' ? $e->getMessage() : 'Internal server error',
    ]);
    exit;
});

// ---------- CORS ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && (in_array($origin, ALLOWED_ORIGINS, true) || APP_ENV === 'development')) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------- Session ----------
if (session_status() === PHP_SESSION_NONE) {
    // keep sessions inside the project — php.ini's save_path may not exist
    $sessDir = BASE_PATH . '/storage/sessions';
    if (!is_dir($sessDir)) {
        @mkdir($sessDir, 0775, true);
    }
    if (is_dir($sessDir) && is_writable($sessDir)) {
        session_save_path($sessDir);
    }
    session_name('POULTRY_SESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
    session_start();
}

// ---------- Autoloader ----------
spl_autoload_register(function (string $class) {
    $dirs = ['controllers', 'models', 'helpers', 'middleware', 'config'];
    foreach ($dirs as $dir) {
        $file = BASE_PATH . '/' . $dir . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
