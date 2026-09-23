<?php
declare(strict_types=1);

namespace App\Services;

/**
 * งานเกี่ยวกับกิจกรรม: อ่าน/บันทึกวันเวลา, บันทึกผล, ลายเซ็น
 */
final class ActivityService
{
    public static function find(int $id): ?array
    {
        return q_one(
            "SELECT a.*, sk.name AS skill_name, sk.code AS skill_code,
                    CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS teacher_name,
                    sem.term, sem.academic_year,
                    (SELECT COUNT(*) FROM participations p WHERE p.activity_id = a.id AND p.status IN ('approved','completed')) AS joined_count,
                    (SELECT COUNT(*) FROM participations p WHERE p.activity_id = a.id AND p.status = 'pending') AS pending_count
               FROM activities a
               JOIN skills sk ON sk.id = a.skill_id
               JOIN users t   ON t.id = a.teacher_id
               LEFT JOIN semesters sem ON sem.id = a.semester_id
              WHERE a.id = ?",
            [$id]
        );
    }

    public static function sessions(int $activityId): array
    {
        return q_all('SELECT * FROM activity_sessions WHERE activity_id = ? ORDER BY session_date, start_time', [$activityId]);
    }

    /** โหลด sessions ของหลายกิจกรรมทีเดียว → [activity_id => [...]] */
    public static function sessionsFor(array $activityIds): array
    {
        $out = [];
        if (!$activityIds) {
            return $out;
        }
        $in = implode(',', array_fill(0, count($activityIds), '?'));
        foreach (q_all("SELECT * FROM activity_sessions WHERE activity_id IN ($in) ORDER BY session_date, start_time", array_values($activityIds)) as $s) {
            $out[$s['activity_id']][] = $s;
        }
        return $out;
    }

    /** อ่านข้อมูลวันเวลาจากฟอร์ม (sessions[date][], sessions[start][] ...) */
    public static function sessionsFromRequest(): array
    {
        $in = $_POST['sessions'] ?? [];
        $rows = [];
        foreach (($in['date'] ?? []) as $i => $date) {
            $date = trim((string) $date);
            if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $start = trim((string) ($in['start'][$i] ?? ''));
            $end = trim((string) ($in['end'][$i] ?? ''));
            $rows[] = [
                'session_date' => $date,
                'start_time'   => preg_match('/^\d{2}:\d{2}/', $start) ? $start : null,
                'end_time'     => preg_match('/^\d{2}:\d{2}/', $end) ? $end : null,
                'detail'       => mb_substr(trim((string) ($in['detail'][$i] ?? '')), 0, 255) ?: null,
            ];
        }
        return $rows;
    }

    public static function saveSessions(int $activityId, array $sessions): void
    {
        q('DELETE FROM activity_sessions WHERE activity_id = ?', [$activityId]);
        foreach ($sessions as $s) {
            q(
                'INSERT INTO activity_sessions (activity_id, session_date, start_time, end_time, detail) VALUES (?, ?, ?, ?, ?)',
                [$activityId, $s['session_date'], $s['start_time'], $s['end_time'], $s['detail']]
            );
        }
    }

    /**
     * เพิ่มนักศึกษาเข้ากิจกรรมแบบมอบหมาย (สถานะอนุมัติทันที)
     * @return int จำนวนคนที่เพิ่มใหม่
     */
    public static function assignStudents(array $activity, array $studentIds, ?string $note = null): int
    {
        $added = 0;
        foreach (array_unique(array_map('intval', $studentIds)) as $sid) {
            if (!q_val("SELECT 1 FROM students WHERE user_id = ?", [$sid])) {
                continue;
            }
            $exists = q_one('SELECT id, status FROM participations WHERE activity_id = ? AND student_id = ?', [$activity['id'], $sid]);
            if ($exists) {
                if (in_array($exists['status'], ['pending', 'rejected', 'cancelled'], true)) {
                    q("UPDATE participations SET status = 'approved', source = 'assigned', teacher_note = ? WHERE id = ?", [$note, $exists['id']]);
                    $added++;
                } else {
                    continue;
                }
            } else {
                q(
                    "INSERT INTO participations (activity_id, student_id, source, status, teacher_note) VALUES (?, ?, 'assigned', 'approved', ?)",
                    [$activity['id'], $sid, $note]
                );
                $added++;
            }
            notify(
                $sid,
                'คุณได้รับมอบหมายกิจกรรมใหม่',
                sprintf('%s มอบหมายกิจกรรม "%s" จำนวน %s ชม.', full_name(current_user()), $activity['title'], fmt_hours($activity['hours'])),
                '/student/activity?id=' . $activity['id']
            );
        }
        return $added;
    }

    /**
     * บันทึกผลการฝึก + ลายเซ็นอาจารย์ผู้ควบคุม ให้กับหลายรายการพร้อมกัน
     */
    public static function recordResults(array $activity, array $participationIds, array $data): int
    {
        $count = 0;
        foreach ($participationIds as $pid) {
            $p = q_one('SELECT * FROM participations WHERE id = ? AND activity_id = ?', [(int) $pid, $activity['id']]);
            if (!$p || !in_array($p['status'], ['approved', 'completed'], true)) {
                continue;
            }
            $hours = $data['hours'][$pid] ?? $activity['hours'];
            $hours = max(0, min(999, round((float) $hours, 1)));
            q(
                "UPDATE participations
                    SET status = 'completed', result = ?, hours_awarded = ?, remark = ?,
                        sign_method = ?, signature_data = ?, signer_name = ?, signed_by = ?, signed_at = NOW()
                  WHERE id = ?",
                [
                    $data['result'],
                    $hours,
                    $data['remark'] ?: null,
                    $data['sign_method'],
                    $data['sign_method'] === 'online' ? $data['signature_data'] : null,
                    $data['signer_name'],
                    user_id(),
                    $p['id'],
                ]
            );
            notify(
                (int) $p['student_id'],
                'บันทึกผลการฝึกทักษะแล้ว',
                sprintf('กิจกรรม "%s" ผล: %s (%s ชม.)', $activity['title'], $data['result'] === 'pass' ? 'ผ่าน' : 'ไม่ผ่าน', fmt_hours($hours)),
                '/student/history'
            );
            $count++;
        }
        return $count;
    }
}
