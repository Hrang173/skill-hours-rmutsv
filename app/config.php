<?php
/**
 * ค่าตั้งค่าหลักของระบบ อ่านจาก environment variable (กำหนดใน docker-compose.yml / .env)
 * ค่าที่ฝ่ายทะเบียนแก้ได้จากหน้าเว็บ (เช่น จำนวนชั่วโมงที่ต้องเก็บ) อยู่ในตาราง settings
 */
return [
    'app' => [
        'name'  => env('APP_NAME', 'ระบบบันทึกชั่วโมงทักษะวิชาชีพ'),
        'url'   => rtrim(env('APP_URL', 'http://localhost:8080'), '/'),
        'debug' => env('APP_DEBUG', 'true') === 'true',
    ],

    'db' => [
        'host' => env('DB_HOST', 'db'),
        'port' => env('DB_PORT', '3306'),
        'name' => env('DB_NAME', 'skillhours'),
        'user' => env('DB_USER', 'skillhours'),
        'pass' => env('DB_PASS', 'skillhours_pass'),
    ],

    'auth' => [
        // local | hybrid (ดู .env.example)
        'mode' => env('AUTH_MODE', 'local'),
    ],

    // ---------------------------------------------------------------
    // การเชื่อมต่อ API มหาวิทยาลัยเทคโนโลยีราชมงคลศรีวิชัย
    // เมื่อได้เอกสาร API จริง ให้แก้ endpoints และ field_map ให้ตรง
    // ---------------------------------------------------------------
    'university_api' => [
        'driver'     => env('UNI_API_DRIVER', 'none'), // none | mock | rmutsv
        'base_url'   => rtrim(env('UNI_API_BASE_URL', ''), '/'),
        'api_key'    => env('UNI_API_KEY', ''),
        'timeout'    => (int) env('UNI_API_TIMEOUT', '10'),
        'verify_ssl' => env('UNI_API_VERIFY_SSL', 'true') === 'true',
        'endpoints'  => [
            'auth'    => env('UNI_API_AUTH_ENDPOINT', '/auth/login'),
            'student' => env('UNI_API_STUDENT_ENDPOINT', '/students/{code}'),
            'staff'   => env('UNI_API_STAFF_ENDPOINT', '/staff/{code}'),
        ],
        // key ซ้าย = ฟิลด์ในระบบเรา, ค่าขวา = ฟิลด์ใน JSON ของ API มหาวิทยาลัย (ใช้ . สำหรับ nested เช่น data.std_code)
        'field_map' => [
            'student' => [
                'student_code' => 'student_code',
                'prefix'       => 'prefix',
                'first_name'   => 'first_name',
                'last_name'    => 'last_name',
                'faculty'      => 'faculty',
                'major'        => 'major',
                'program_type' => 'program_type', // ค่าที่ API ส่งมาจะถูกแปลงด้วย program_type_map
                'entry_year'   => 'entry_year',
                'email'        => 'email',
            ],
            'staff' => [
                'username'   => 'username',
                'prefix'     => 'prefix',
                'first_name' => 'first_name',
                'last_name'  => 'last_name',
                'email'      => 'email',
            ],
        ],
        // แปลงค่าประเภทนักศึกษาจาก API → ค่าในระบบ
        'program_type_map' => [
            '4year'    => '4year',
            '4'        => '4year',
            'ปกติ'      => '4year',
            'transfer' => 'transfer',
            'เทียบโอน'  => 'transfer',
        ],
    ],
];
