<?php
/**
 * ตาราง route: path => [ไฟล์ใน app/pages, บทบาทที่เข้าได้ (null = ไม่ต้องล็อกอิน)]
 */
const ALL_ROLES = ['student', 'teacher', 'registrar'];
const STAFF = ['teacher', 'registrar'];

return [
    ''                          => ['home.php', null],
    'login'                     => ['auth/login.php', null],
    'logout'                    => ['auth/logout.php', null],

    // ใช้ร่วมกันทุกบทบาท
    'profile'                   => ['common/profile.php', ALL_ROLES],
    'notifications'             => ['common/notifications.php', ALL_ROLES],
    'print/form'                => ['common/print_form.php', ALL_ROLES],
    'students/view'             => ['common/student_detail.php', STAFF],
    'ajax/students'             => ['common/ajax_students.php', STAFF],

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
    'teacher/signature'         => ['teacher/signature.php', ['teacher']],

    // ฝ่ายทะเบียน
    'registrar'                 => ['registrar/dashboard.php', ['registrar']],
    'registrar/students'        => ['common/student_search.php', ['registrar']],
    'registrar/export'          => ['registrar/export.php', ['registrar']],
    'registrar/users'           => ['registrar/users.php', ['registrar']],
    'registrar/user/edit'       => ['registrar/user_form.php', ['registrar']],
    'registrar/import'          => ['registrar/import.php', ['registrar']],
    'registrar/skills'          => ['registrar/skills.php', ['registrar']],
    'registrar/semesters'       => ['registrar/semesters.php', ['registrar']],
    'registrar/settings'        => ['registrar/settings.php', ['registrar']],
    'registrar/audit'           => ['registrar/audit.php', ['registrar']],
    'registrar/api-keys'        => ['registrar/api_keys.php', ['registrar']],
    'registrar/university-sync' => ['registrar/university_sync.php', ['registrar']],
];
