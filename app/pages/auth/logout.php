<?php
logout_user();
session_start();
flash('success', 'ออกจากระบบเรียบร้อยแล้ว');
redirect('/login');
