<?php
/**
 * ตาราง route: path => [ไฟล์ใน app/pages, บทบาทที่เข้าได้ (null = ไม่ต้องล็อกอิน)]
 * ค่าคงที่บทบาท (ALL_ROLES, STAFF_ROLES, ...) อยู่ใน app/lib/auth.php
 */
return [
    ''                          => ['home.php', null],
    'login'                     => ['auth/login.php', null],
    'logout'                    => ['auth/logout.php', null],

    // ใช้ร่วมกันทุกบทบาท
    'profile'                   => ['common/profile.php', ALL_ROLES],
    'notifications'             => ['common/notifications.php', ALL_ROLES],
    'print/form'                => ['common/print_form.php', ALL_ROLES],
    'students/view'             => ['common/student_detail.php', STAFF_ROLES],
    'ajax/students'             => ['common/ajax_students.php', STAFF_ROLES],
    'signature'                 => ['common/signature.php', STAFF_ROLES],

    // นักศึกษา
    'student'                   => ['student/dashboard.php', ['student']],
    'student/activities'        => ['student/activities.php', ['student']],
    'student/activity'          => ['student/activity_view.php', ['student']],
    'student/history'           => ['student/history.php', ['student']],

    // อาจารย์
    'teacher'                   => ['teacher/dashboard.php', ['teacher']],
    'teacher/activities'        => ['teacher/activities.php', ['teacher']],
    'teacher/activity/new'      => ['teacher/activity_form.php', ['teacher']],
    'teacher/activity/edit'     => ['teacher/activity_form.php', ['teacher']],
    'teacher/activity'          => ['teacher/activity_view.php', ['teacher']],
    'teacher/assign'            => ['teacher/assign.php', ['teacher']],
    'teacher/students'          => ['common/student_search.php', ['teacher']],
    'teacher/signature'         => ['common/signature.php', ['teacher']],

    // ฝ่ายทะเบียน (admin เข้าได้ด้วย)
    'registrar'                 => ['registrar/dashboard.php', OFFICE_ROLES],
    'registrar/students'        => ['common/student_search.php', OFFICE_ROLES],
    'registrar/export'          => ['registrar/export.php', OFFICE_ROLES],
    'registrar/users'           => ['registrar/users.php', OFFICE_ROLES],
    'registrar/user/edit'       => ['registrar/user_form.php', OFFICE_ROLES],
    'registrar/import'          => ['registrar/import.php', OFFICE_ROLES],
    'registrar/skills'          => ['registrar/skills.php', OFFICE_ROLES],
    'registrar/semesters'       => ['registrar/semesters.php', OFFICE_ROLES],
    'registrar/program-heads'   => ['registrar/program_heads.php', OFFICE_ROLES],

    // ผู้ดูแลระบบ
    'admin'                     => ['registrar/dashboard.php', ADMIN_ROLES],
    'admin/record'              => ['admin/record_edit.php', ADMIN_ROLES],
    'admin/settings'            => ['admin/settings.php', ADMIN_ROLES],
    'admin/audit'               => ['admin/audit.php', ADMIN_ROLES],
    'admin/api-keys'            => ['admin/api_keys.php', ADMIN_ROLES],
    'admin/university-sync'     => ['admin/university_sync.php', ADMIN_ROLES],
];
