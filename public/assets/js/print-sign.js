/* ===== ลงชื่อท้ายแบบบันทึก (ช่อง "ลงชื่อ" และ "( )") — เฉพาะอาจารย์ / ฝ่ายทะเบียน / admin =====
 * แต่ละช่องเลือกได้: เว้นว่าง / ลายเซ็น (ลายเซ็นที่บันทึกไว้ หรือเซ็นใหม่) / พิมพ์ชื่อ
 * ผลจะถูกวางลงทุกหน้าของเอกสาร และจำไว้ใน sessionStorage (เปลี่ยนภาค/ตัวเลือกแล้วไม่หาย)
 */
(function () {
    'use strict';

    const cfg = window.PRINT_SIGN;
    const dialog = document.getElementById('signDialog');
    if (!cfg || !dialog) return;

    const SLOTS = ['sign', 'paren'];
    const defaults = () => ({
        sign: { mode: 'none', value: '' },
        paren: { mode: 'name', value: cfg.headName || '' },
    });

    const load = () => {
        try {
            const raw = sessionStorage.getItem(cfg.storageKey);
            return raw ? { ...defaults(), ...JSON.parse(raw) } : defaults();
        } catch (e) {
            return defaults();
        }
    };
    const save = s => {
        try { sessionStorage.setItem(cfg.storageKey, JSON.stringify(s)); } catch (e) { /* ไม่เป็นไร */ }
    };

    // วางผลลงในเอกสารทุกหน้า
    const render = state => {
        SLOTS.forEach(slot => {
            document.querySelectorAll(`[data-slot="${slot}"]`).forEach(el => {
                el.textContent = '';
                const s = state[slot];
                if (s.mode === 'signature' && s.value) {
                    const img = document.createElement('img');
                    img.src = s.value;
                    img.alt = 'ลายเซ็น';
                    img.className = 'slot-sig';
                    el.appendChild(img);
                } else if (s.mode === 'name') {
                    el.textContent = s.value;
                }
            });
        });
    };

    // ---------- ตัวแก้ไขในกล่อง dialog ----------
    const editors = {};
    SLOTS.forEach(slot => {
        const box = dialog.querySelector(`[data-slot-editor="${slot}"]`);
        const canvas = box.querySelector('[data-pad]');
        const pad = new window.SignaturePad(canvas, () => {});
        const draw = box.querySelector('[data-draw]');
        const text = box.querySelector(`[name="${slot}_text"]`);

        const mode = () => box.querySelector(`[name="${slot}_mode"]:checked`)?.value || 'none';
        const src = () => box.querySelector(`[name="${slot}_src"]:checked`)?.value || 'draw';
        const refresh = () => {
            box.querySelectorAll('[data-panel]').forEach(p => { p.hidden = p.dataset.panel !== mode(); });
            draw.hidden = src() === 'saved';
        };

        box.querySelectorAll('input[type=radio]').forEach(r => r.addEventListener('change', refresh));
        box.querySelector('[data-clear]').addEventListener('click', () => pad.clear());

        editors[slot] = {
            fill(s) {
                box.querySelector(`[name="${slot}_mode"][value="${s.mode}"]`).checked = true;
                text.value = s.mode === 'name' ? s.value : (slot === 'paren' || !text.value ? cfg.headName || '' : text.value);
                pad.clear();
                refresh();
            },
            /** คืนค่าที่เลือก หรือ null ถ้ายังไม่ครบ */
            read() {
                const m = mode();
                if (m === 'name') {
                    const v = text.value.trim();
                    if (!v) { alert('กรุณาพิมพ์ชื่อ'); text.focus(); return null; }
                    return { mode: 'name', value: v };
                }
                if (m === 'signature') {
                    const v = src() === 'saved' ? cfg.savedSignature : pad.toDataURL();
                    if (!v) { alert('กรุณาเซ็นชื่อในกรอบ หรือเลือกใช้ลายเซ็นที่บันทึกไว้'); return null; }
                    return { mode: 'signature', value: v };
                }
                return { mode: 'none', value: '' };
            },
        };
    });

    let state = load();
    render(state);

    document.querySelector('[data-open-sign]').addEventListener('click', () => {
        SLOTS.forEach(slot => editors[slot].fill(state[slot]));
        dialog.showModal();
    });

    dialog.querySelector('[data-apply]').addEventListener('click', () => {
        const next = {};
        for (const slot of SLOTS) {
            const v = editors[slot].read();
            if (!v) return;
            next[slot] = v;
        }
        state = next;
        save(state);
        render(state);
        dialog.close();
    });

    dialog.querySelector('[data-reset]').addEventListener('click', () => {
        state = defaults();
        save(state);
        render(state);
        SLOTS.forEach(slot => editors[slot].fill(state[slot]));
    });
})();
