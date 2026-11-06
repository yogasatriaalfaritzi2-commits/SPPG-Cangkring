<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Env;
use App\Core\ErrorHandler;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!defined('STORAGE_PATH')) {
    define('STORAGE_PATH', BASE_PATH . '/storage');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Env::load(BASE_PATH . '/.env');

$config = [
    'app' => require BASE_PATH . '/config/app.php',
    'database' => require BASE_PATH . '/config/database.php',
];

ini_set('display_errors', $config['app']['debug'] ? '1' : '0');
ini_set('zend.exception_ignore_args', '1');
error_reporting(E_ALL);

date_default_timezone_set($config['app']['timezone']);

require BASE_PATH . '/app/Helpers/url.php';
require BASE_PATH . '/app/Helpers/functions.php';
require BASE_PATH . '/app/Helpers/mbg.php';
require BASE_PATH . '/app/Helpers/validation.php';

ErrorHandler::register((bool) $config['app']['debug'], STORAGE_PATH . '/logs');

$basePath = '';
if (PHP_SAPI !== 'cli') {
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $directory = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($directory !== '' && $directory !== '.') {
        $basePath = $directory;
    }
}

if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', $basePath);
}

return new App($config, $basePath);
