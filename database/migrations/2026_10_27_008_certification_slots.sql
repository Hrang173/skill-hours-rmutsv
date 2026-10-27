-- =====================================================================
--  Migration 008
--  หัวหน้าหลักสูตรเซ็นยืนยันได้ทั้ง 3 บรรทัดท้ายแบบบันทึก (ลงชื่อ / ( ) / หัวหน้าหลักสูตร)
--  slots = JSON {"sign": {"mode": "signature|name|none", "value": "..."}, "paren": {...}, "pos": {...}}
-- =====================================================================

ALTER TABLE student_certifications ADD COLUMN IF NOT EXISTS slots MEDIUMTEXT NULL COMMENT 'ลายเซ็น/ชื่อ ทั้ง 3 บรรทัด (JSON)' AFTER signer_name;
