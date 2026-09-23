<?php
declare(strict_types=1);

/**
 * จุดเริ่มต้นของแอป: โหลด config, helper, autoload คลาส และเปิด session
 */

define('APP_PATH', __DIR__);
define('ROOT_PATH', dirname(__DIR__));

date_default_timezone_set('Asia/Bangkok');
mb_internal_encoding('UTF-8');

require APP_PATH . '/lib/helpers.php';

$GLOBALS['config'] = require APP_PATH . '/config.php';

if (!config('app.debug')) {
    ini_set('display_errors', '0');
}

require APP_PATH . '/lib/auth.php';
require APP_PATH . '/lib/view.php';
require APP_PATH . '/lib/components.php';

// Autoload คลาสใน namespace App\ → โฟลเดอร์ app/
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = APP_PATH . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
    session_name('SKILLHOURS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
    ]);
    session_start();
}
