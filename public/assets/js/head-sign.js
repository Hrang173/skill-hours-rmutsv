/* ===== หัวหน้าหลักสูตรเซ็นท้ายแบบบันทึก (หน้า "เซ็นยืนยัน") =====
 * ตัวอย่างขนาดจริง 3 บรรทัด: ลงชื่อ / ( ) / หัวหน้าหลักสูตร — คลิกบรรทัดเพื่อเลือก แล้วเลือกวิธี
 *   ลายเซ็น: เซ็นตรงบรรทัด (canvas ทับบรรทัดในตัวอย่าง) / ลายเซ็นที่บันทึกไว้ / เซ็นในกรอบใหญ่
 *   พิมพ์ชื่อ / เว้นว่าง
 * ตอนส่งฟอร์ม รวมเป็น JSON ใส่ input[name=slots]
 */
(function () {
    'use strict';

    const root = document.querySelector('[data-head-sign]');
    if (!root || !window.SignaturePad) return;

    const cfg = JSON.parse(root.dataset.config);
    const SLOTS = Object.keys(cfg.labels);
    const controls = root.querySelector('.cert-controls');
    const nameInput = root.querySelector('[data-name-input]');
    const padWrap = root.querySelector('[data-pad-wrap]');
    const srcHint = root.querySelector('[data-src-hint]');
    const defaultSrc = cfg.saved ? 'saved' : 'line';

    const state = {
        sign:  { mode: 'signature', src: defaultSrc, name: cfg.headName, lineUrl: '', padUrl: '' },
        paren: { mode: 'name', src: defaultSrc, name: cfg.headName, lineUrl: '', padUrl: '' },
        pos:   { mode: 'signature', src: defaultSrc, name: cfg.headName, lineUrl: '', padUrl: '' },
    };
    let active = 'sign';

    const lines = {}, linePads = {}, bigPads = {};
    const firstBig = padWrap.querySelector('canvas');

    SLOTS.forEach(slot => {
        const line = root.querySelector(`[data-line="${slot}"]`);
        lines[slot] = line;
        line.addEventListener('click', () => select(slot));

        // canvas ทับบรรทัด: สูงกว่าเส้นเล็กน้อยเพื่อให้มีที่เซ็น
        const c = document.createElement('canvas');
        c.className = 'line-pad';
        c.hidden = true;
        line.appendChild(c);
        linePads[slot] = { canvas: c, pad: null };

        // กรอบใหญ่ แยกต่อบรรทัด
        const big = slot === SLOTS[0] ? firstBig : firstBig.cloneNode();
        if (big !== firstBig) padWrap.appendChild(big);
        big.hidden = true;
        bigPads[slot] = new window.SignaturePad(big, url => { state[slot].padUrl = url; render(slot); });
    });

    /** สร้าง SignaturePad ของบรรทัดเมื่อแสดงครั้งแรก (ต้องรู้ขนาดจริงก่อน) */
    const ensureLinePad = slot => {
        const lp = linePads[slot];
        if (lp.pad) return;
        lp.canvas.hidden = false;
        const r = lp.canvas.getBoundingClientRect();
        lp.canvas.width = Math.max(200, Math.round(r.width * 1.5));
        lp.canvas.height = Math.max(60, Math.round(r.height * 1.5));
        lp.pad = new window.SignaturePad(lp.canvas, url => { state[slot].lineUrl = url; });
    };

    const value = slot => {
        const s = state[slot];
        if (s.mode === 'name') return s.name.trim();
        if (s.mode !== 'signature') return '';
        return s.src === 'saved' ? cfg.saved : s.src === 'line' ? s.lineUrl : s.padUrl;
    };

    /** แสดงผลในตัวอย่าง: บรรทัดที่กำลังเซ็นตรงบรรทัด → แสดง canvas, อื่น ๆ → แสดงผลแบบที่จะพิมพ์จริง */
    function render(slot) {
        const line = lines[slot], s = state[slot], lp = linePads[slot];
        const drawingHere = slot === active && s.mode === 'signature' && s.src === 'line';
        [...line.childNodes].forEach(n => { if (n !== lp.canvas) n.remove(); });
        lp.canvas.hidden = !drawingHere;
        if (drawingHere) {
            ensureLinePad(slot);
        } else if (s.mode === 'signature' && value(slot)) {
            const img = document.createElement('img');
            img.src = value(slot);
            img.className = 'slot-sig';
            line.insertBefore(img, lp.canvas);
        } else if (s.mode === 'name') {
            line.insertBefore(document.createTextNode(s.name), lp.canvas);
        } else if (s.mode === 'signature') {
            line.insertBefore(Object.assign(document.createElement('span'), { className: 'hint', textContent: 'คลิกเพื่อเซ็น' }), lp.canvas);
        }
        line.classList.toggle('empty', !value(slot) && !drawingHere);
    }

    const renderControls = () => {
        const s = state[active];
        root.querySelector('[data-active-label]').textContent = cfg.labels[active];
        controls.querySelector(`[name=hs_mode][value=${s.mode}]`).checked = true;
        const srcRadio = controls.querySelector(`[name=hs_src][value=${s.src}]`) || controls.querySelector('[name=hs_src][value=line]');
        srcRadio.checked = true;
        s.src = srcRadio.value;
        controls.querySelectorAll('[data-panel]').forEach(p => { p.hidden = p.dataset.panel !== s.mode; });
        padWrap.hidden = s.src !== 'pad';
        SLOTS.forEach(slot => { bigPads[slot].canvas.hidden = slot !== active; });
        srcHint.textContent = s.src === 'line' ? 'เซ็นด้วยเมาส์/นิ้วตรงบรรทัดในตัวอย่างด้านบน' : s.src === 'pad' ? 'เซ็นในกรอบ ระบบย่อให้พอดีบรรทัด' : '';
        nameInput.value = s.name;
    };

    function select(slot) {
        active = slot;
        SLOTS.forEach(sl => lines[sl].classList.toggle('active', sl === slot));
        renderControls();
        SLOTS.forEach(render);
    }

    controls.querySelectorAll('[name=hs_mode]').forEach(r => r.addEventListener('change', () => {
        state[active].mode = r.value;
        renderControls();
        render(active);
    }));
    controls.querySelectorAll('[name=hs_src]').forEach(r => r.addEventListener('change', () => {
        state[active].src = r.value;
        renderControls();
        render(active);
    }));
    nameInput.addEventListener('input', () => { state[active].name = nameInput.value; render(active); });
    root.querySelector('[data-clear]').addEventListener('click', () => {
        const s = state[active];
        if (s.src === 'line') { linePads[active].pad?.clear(); s.lineUrl = ''; }
        if (s.src === 'pad') { bigPads[active].clear(); s.padUrl = ''; }
        render(active);
    });

    root.closest('form').addEventListener('submit', e => {
        const out = {};
        for (const slot of SLOTS) {
            const s = state[slot], v = value(slot);
            if (s.mode !== 'none' && !v) {
                e.preventDefault();
                select(slot);
                alert(`กรุณา${s.mode === 'name' ? 'พิมพ์ชื่อ' : 'เซ็นชื่อ'}ในบรรทัด "${cfg.labels[slot]}" หรือเลือก "เว้นว่าง"`);
                return;
            }
            out[slot] = { mode: s.mode, value: v || '' };
        }
        if (out.sign.mode === 'none') {
            e.preventDefault();
            select('sign');
            alert('กรุณาเซ็นหรือพิมพ์ชื่อในบรรทัด "ลงชื่อ"');
            return;
        }
        if (!root.closest('form').querySelector('[name="student_ids[]"]:checked')) {
            e.preventDefault();
            alert('กรุณาเลือกนักศึกษาอย่างน้อย 1 คน');
            return;
        }
        root.querySelector('[data-slots-output]').value = JSON.stringify(out);
    });

    select('sign');
})();
