<?php
// หน้า error ต้องไม่พึ่งฐานข้อมูล เผื่อฐานข้อมูลเป็นต้นเหตุของปัญหา
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>เกิดข้อผิดพลาด</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5 text-center">
    <div class="display-1 text-muted">500</div>
    <p class="lead">ระบบเกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง</p>
    <?php if (config('app.debug') && isset($ex)): ?>
        <pre class="text-start bg-white border rounded p-3 small"><?= e(get_class($ex) . ': ' . $ex->getMessage() . "\n" . $ex->getFile() . ':' . $ex->getLine() . "\n\n" . $ex->getTraceAsString()) ?></pre>
    <?php endif; ?>
    <a href="/" class="btn btn-primary">กลับหน้าหลัก</a>
</div>
</body>
</html>
