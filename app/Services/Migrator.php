<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * รันไฟล์ migration ใน database/migrations/*.sql ที่ยังไม่เคยรัน (เรียงตามชื่อไฟล์)
 * - ถูกเรียกอัตโนมัติจาก public/index.php ทำให้ฐานข้อมูลเดิมได้รับการอัปเดตโดยข้อมูลไม่หาย
 * - จำว่ารันแล้วในตาราง schema_migrations
 * - ไฟล์ marker ใน /tmp ทำให้ request ปกติไม่ต้องถามฐานข้อมูลซ้ำ
 */
final class Migrator
{
    public static function run(): void
    {
        $files = glob(ROOT_PATH . '/database/migrations/*.sql') ?: [];
        if (!$files) {
            return;
        }
        sort($files);
        $marker = sys_get_temp_dir() . '/skillhours_migrated_' . md5(implode('|', array_map('basename', $files)));
        if (is_file($marker)) {
            return;
        }

        $pdo = db();
        // กันสอง request รัน migration พร้อมกัน
        if ((int) $pdo->query("SELECT GET_LOCK('skillhours_migrate', 30)")->fetchColumn() !== 1) {
            throw new RuntimeException('ไม่สามารถล็อกเพื่อรัน migration ได้');
        }
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
                version    VARCHAR(190) PRIMARY KEY,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $done = array_flip($pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));

            foreach ($files as $file) {
                $version = basename($file);
                if (isset($done[$version])) {
                    continue;
                }
                foreach (self::statements((string) file_get_contents($file)) as $sql) {
                    $pdo->exec($sql);
                }
                q('INSERT INTO schema_migrations (version) VALUES (?)', [$version]);
                error_log("Migration applied: $version");
            }
            @file_put_contents($marker, date('c'));
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('skillhours_migrate')");
        }
    }

    /** แยกไฟล์ SQL เป็นทีละคำสั่ง (ตัด comment -- ทิ้ง, จบคำสั่งด้วย ; ท้ายบรรทัด) */
    private static function statements(string $sql): array
    {
        $lines = array_filter(preg_split('/\R/', $sql), fn($l) => !preg_match('/^\s*--/', $l));
        $parts = preg_split('/;\s*(\R|$)/', implode("\n", $lines));
        return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
    }
}
