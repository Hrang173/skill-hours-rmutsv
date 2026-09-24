<?php
declare(strict_types=1);

/**
 * Front controller: ทุก request (ยกเว้นไฟล์ใน assets) จะเข้ามาที่ไฟล์นี้
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

try {
    // อัปเดตโครงสร้างฐานข้อมูลอัตโนมัติ (ถ้ามี migration ใหม่)
    App\Services\Migrator::run();

    // REST API สำหรับระบบภายนอก (ไม่ใช้ session/CSRF แต่ใช้ API key)
    if ($path === 'api' || str_starts_with($path, 'api/')) {
        require APP_PATH . '/api/router.php';
        exit;
    }

    if (is_post()) {
        csrf_check();
    }

    $routes = require APP_PATH . '/routes.php';
    if (!isset($routes[$path])) {
        http_response_code(404);
        require APP_PATH . '/pages/errors/404.php';
        exit;
    }

    [$file, $roles] = $routes[$path];
    if ($roles !== null) {
        require_login($roles);
    }
    require APP_PATH . '/pages/' . $file;
} catch (Throwable $ex) {
    error_log((string) $ex);
    http_response_code(500);
    require APP_PATH . '/pages/errors/500.php';
}
