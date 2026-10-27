/* ===== สคริปต์ทั่วไปของระบบ ===== */
(function () {
    'use strict';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // ---------- ปุ่มแสดง/ซ่อนรหัสผ่าน ----------
    document.querySelectorAll('[data-toggle-password]').forEach(btn => {
        const input = document.querySelector(btn.dataset.togglePassword);
        if (!input) return;
        btn.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            btn.title = show ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน';
            btn.setAttribute('aria-label', btn.title);
            input.focus();
        });
    });

    // ---------- เลือกทั้งหมด (checkbox) ----------
    document.querySelectorAll('[data-check-all]').forEach(master => {
        master.addEventListener('change', () => {
            document.querySelectorAll(`[data-check-group="${master.dataset.checkAll}"]`)
                .forEach(cb => { cb.checked = master.checked; });
        });
    });

    // ---------- ตัวแก้ไขวัน/เวลา (sessions editor) ----------
    document.querySelectorAll('.sessions-editor').forEach(editor => {
        const body = editor.querySelector('[data-sessions-body]');
        const info = editor.querySelector('[data-sessions-hours]');
        const hoursInput = document.querySelector('[data-hours-input]');
        const deadline = document.querySelector('[data-register-deadline]');
        let deadlineHint = null;
        if (deadline) {
            deadlineHint = document.createElement('div');
            deadlineHint.className = 'form-text';
            deadline.after(deadlineHint);
            deadline.addEventListener('change', () => checkDeadline());
        }

        /** ปิดรับสมัครต้องไม่เกินวันเวลาเริ่มฝึกครั้งแรก (ตรวจซ้ำฝั่งเซิร์ฟเวอร์) */
        const checkDeadline = () => {
            if (!deadline) return;
            const starts = [...body.querySelectorAll('tr')]
                .map(tr => [tr.querySelector('[name="sessions[date][]"]').value, tr.querySelector('[name="sessions[start][]"]').value || '00:00'])
                .filter(([d]) => d)
                .map(([d, t]) => `${d}T${t.slice(0, 5)}`)
                .sort();
            const first = starts[0] || '';
            deadline.max = first;
            const late = first && deadline.value && deadline.value > first;
            deadline.setCustomValidity(late ? 'วันปิดรับสมัครต้องอยู่ก่อนวันเวลาเริ่มฝึก' : '');
            deadline.classList.toggle('is-invalid', !!late);
            const label = first ? new Date(first).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }) : '';
            deadlineHint.textContent = late ? `ต้องปิดรับก่อนเริ่มฝึก (${label})` : (first ? `ไม่เกิน ${label} (เริ่มฝึกวันแรก)` : '');
            deadlineHint.classList.toggle('text-danger', !!late);
        };

        const calc = () => {
            let minutes = 0;
            body.querySelectorAll('tr').forEach(tr => {
                const d = tr.querySelector('[name="sessions[date][]"]').value;
                const s = tr.querySelector('[name="sessions[start][]"]').value;
                const e = tr.querySelector('[name="sessions[end][]"]').value;
                if (d && s && e) {
                    const [sh, sm] = s.split(':').map(Number);
                    const [eh, em] = e.split(':').map(Number);
                    const diff = (eh * 60 + em) - (sh * 60 + sm);
                    if (diff > 0) minutes += diff;
                }
            });
            if (info) {
                info.textContent = minutes ? `รวมเวลาตามตาราง ${(minutes / 60).toFixed(1).replace('.0', '')} ชม.` : '';
                // เติมจำนวนชั่วโมงให้อัตโนมัติ จนกว่าอาจารย์จะพิมพ์ค่าเอง
                if (minutes && hoursInput && (!hoursInput.value || hoursInput.dataset.auto)) {
                    info.textContent += ' (ใส่จำนวนชั่วโมงให้อัตโนมัติ)';
                    hoursInput.value = (Math.round(minutes / 30) / 2).toString();
                    hoursInput.dataset.auto = '1';
                }
            }
            checkDeadline();
        };
        hoursInput?.addEventListener('input', () => { delete hoursInput.dataset.auto; });

        editor.querySelector('[data-add-session]').addEventListener('click', () => {
            const last = body.querySelector('tr:last-child');
            const tr = last.cloneNode(true);
            const dateInput = tr.querySelector('[type=date]');
            // วันถัดไปจากแถวก่อนหน้า
            if (dateInput.value) {
                const d = new Date(dateInput.value);
                d.setDate(d.getDate() + 1);
                dateInput.value = d.toISOString().slice(0, 10);
            }
            tr.querySelector('[name="sessions[detail][]"]').value = '';
            body.appendChild(tr);
            calc();
        });

        body.addEventListener('click', e => {
            const btn = e.target.closest('[data-remove-session]');
            if (!btn) return;
            const rows = body.querySelectorAll('tr');
            if (rows.length > 1) {
                btn.closest('tr').remove();
            } else {
                rows[0].querySelectorAll('input').forEach(i => { i.value = ''; });
            }
            calc();
        });
        body.addEventListener('change', calc);
        calc();
    });

    // ---------- ค้นหา + เลือกนักศึกษา ----------
    document.querySelectorAll('[data-student-picker]').forEach(picker => {
        const input = picker.querySelector('[data-picker-input]');
        const results = picker.querySelector('[data-picker-results]');
        const selected = picker.querySelector('[data-picker-selected]');
        const empty = picker.querySelector('[data-picker-empty]');
        let timer = null;

        const selectedIds = () => [...selected.querySelectorAll('[data-id]')].map(el => el.dataset.id);
        const refreshEmpty = () => { empty.hidden = selected.children.length > 0; };

        const add = s => {
            if (selectedIds().includes(String(s.id))) return;
            const chip = document.createElement('span');
            chip.className = 'badge text-bg-primary picker-chip';
            chip.dataset.id = s.id;
            chip.append(`${s.code} ${s.name}`);
            const hidden = document.createElement('input');
            hidden.type = 'hidden'; hidden.name = 'student_ids[]'; hidden.value = s.id;
            const close = document.createElement('button');
            close.type = 'button'; close.className = 'btn-close btn-close-white ms-1';
            close.setAttribute('data-picker-remove', '');
            chip.append(hidden, close);
            selected.appendChild(chip);
            refreshEmpty();
        };

        selected.addEventListener('click', e => {
            if (e.target.matches('[data-picker-remove]')) {
                e.target.closest('[data-id]').remove();
                refreshEmpty();
            }
        });

        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                results.querySelector('button')?.click();
            }
        });

        input.addEventListener('input', () => {
            clearTimeout(timer);
            const q = input.value.trim();
            if (q.length < 2) { results.innerHTML = ''; return; }
            timer = setTimeout(async () => {
                try {
                    const res = await fetch('/ajax/students?q=' + encodeURIComponent(q), { headers: { 'X-CSRF-Token': csrf } });
                    const json = await res.json();
                    results.innerHTML = '';
                    if (!json.data.length) {
                        results.innerHTML = '<div class="list-group-item small text-muted">ไม่พบนักศึกษา</div>';
                        return;
                    }
                    json.data.forEach(s => {
                        const b = document.createElement('button');
                        b.type = 'button';
                        b.className = 'list-group-item list-group-item-action d-flex justify-content-between';
                        const left = document.createElement('span');
                        left.textContent = `${s.code}  ${s.name}`;
                        const right = document.createElement('span');
                        right.className = 'small text-muted';
                        right.textContent = s.program;
                        b.append(left, right);
                        if (selectedIds().includes(String(s.id))) b.classList.add('disabled');
                        b.addEventListener('click', () => { add(s); input.value = ''; results.innerHTML = ''; input.focus(); });
                        results.appendChild(b);
                    });
                } catch (err) {
                    results.innerHTML = '<div class="list-group-item small text-danger">ค้นหาไม่สำเร็จ</div>';
                }
            }, 250);
        });
    });

    // ---------- Modal บันทึกผล: แสดงจำนวนที่เลือก + เลือกคนที่ยังไม่บันทึกอัตโนมัติ ----------
    const recordModal = document.getElementById('recordModal');
    if (recordModal) {
        recordModal.addEventListener('show.bs.modal', () => {
            const boxes = [...document.querySelectorAll('[data-check-group="active"]')];
            if (!boxes.some(cb => cb.checked)) {
                boxes.filter(cb => cb.hasAttribute('data-unrecorded')).forEach(cb => { cb.checked = true; });
            }
            const n = boxes.filter(cb => cb.checked).length;
            recordModal.querySelector('[data-selected-count]').textContent =
                n ? `จะบันทึกผลให้นักศึกษาที่เลือก ${n} คน` : 'ยังไม่ได้เลือกนักศึกษา กรุณาปิดหน้าต่างแล้วติ๊กเลือกในตาราง';
        });
    }

    // ---------- ฟอร์มกิจกรรม: แสดงทักษะที่อาจารย์รับผิดชอบในปีการศึกษาของภาคที่เลือก ----------
    document.querySelectorAll('[data-skill-fixed]').forEach(box => {
        const semester = box.closest('form')?.querySelector('[data-semester-select]');
        const names = JSON.parse(box.dataset.skills || '{}');
        const show = () => {
            const year = semester?.selectedOptions[0]?.dataset.year || box.dataset.currentYear;
            const name = names[year];
            box.textContent = name || `คุณยังไม่ได้รับมอบหมายให้รับผิดชอบทักษะในปีการศึกษา ${year} กรุณาติดต่อฝ่ายทะเบียน`;
            box.classList.toggle('text-danger', !name);
        };
        semester?.addEventListener('change', show);
        show();
    });
})();
