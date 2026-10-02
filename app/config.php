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
    // POST https://api.rmutsv.ac.th/elogin  body JSON {"username","password"}
    //   → {"status":"ok", "username", "name", "firstname", "lastname", "type", "facname", "depname", ...}
    //   status อื่น: "password" = รหัสผิด, "weakpassword" = รหัสไม่ผ่านนโยบาย, "json" = รูปแบบคำขอผิด
    // ---------------------------------------------------------------
    'university_api' => [
        'driver'     => env('UNI_API_DRIVER', 'none'), // none | mock | rmutsv
        'base_url'   => rtrim(env('UNI_API_BASE_URL', 'https://api.rmutsv.ac.th'), '/'),
        'api_key'    => env('UNI_API_KEY', ''),
        'timeout'    => (int) env('UNI_API_TIMEOUT', '10'),
        'verify_ssl' => env('UNI_API_VERIFY_SSL', 'true') === 'true',
        'endpoints'  => [
            'auth'    => env('UNI_API_AUTH_ENDPOINT', '/elogin'),
            // มหาวิทยาลัยยังไม่มี API ค้นหาข้อมูลนักศึกษา/บุคลากร — ว่างไว้ = ไม่ใช้ ({code} = รหัส)
            'student' => env('UNI_API_STUDENT_ENDPOINT', ''),
            'staff'   => env('UNI_API_STAFF_ENDPOINT', ''),
        ],
        // ค่า "type" จาก /elogin ที่ถือว่าเป็นนักศึกษา (คั่นด้วย ,) — ชื่อผู้ใช้ที่เป็นตัวเลขล้วน 10-13 หลักก็ถือเป็นนักศึกษาเช่นกัน
        'student_types' => array_filter(array_map(
            fn($t) => mb_strtolower(trim($t)),
            explode(',', env('UNI_API_STUDENT_TYPES', 'student,students,std,นักศึกษา'))
        )),
        // รับเฉพาะนักศึกษาคณะเหล่านี้ (faccode คั่นด้วย ,) — ว่าง = รับทุกคณะ
        'allowed_faccodes' => array_filter(array_map('trim', explode(',', env('UNI_API_ALLOW_FACCODES', '')))),
        // ช่องที่ใช้เป็น "สาขาวิชา" ของนักศึกษา (RMUTSV ส่งสาขามาใน secname, depname เป็น "-")
        'major_field' => env('UNI_API_MAJOR_FIELD', 'secname'),
        // แปลงค่าประเภทนักศึกษา (ใช้กับ API รับข้อมูลเข้า /api/v1/students/sync)
        'program_type_map' => [
            '4year'    => '4year',
            '4'        => '4year',
            'ปกติ'      => '4year',
            'transfer' => 'transfer',
            'เทียบโอน'  => 'transfer',
        ],
    ],
];
