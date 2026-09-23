/* ===== ลายเซ็นออนไลน์ (วาดบน canvas ด้วยเมาส์/นิ้ว/ปากกา) ===== */
(function () {
    'use strict';

    class SignaturePad {
        constructor(canvas, onChange) {
            this.canvas = canvas;
            this.ctx = canvas.getContext('2d');
            this.onChange = onChange;
            this.drawing = false;
            this.empty = true;
            this.last = null;
            this.ctx.lineCap = 'round';
            this.ctx.lineJoin = 'round';
            this.ctx.strokeStyle = '#0b1f66';
            this.ctx.lineWidth = 2.6;

            canvas.addEventListener('pointerdown', e => this.start(e));
            canvas.addEventListener('pointermove', e => this.move(e));
            ['pointerup', 'pointerleave', 'pointercancel'].forEach(t => canvas.addEventListener(t, () => this.end()));
        }

        pos(e) {
            const r = this.canvas.getBoundingClientRect();
            return {
                x: (e.clientX - r.left) * (this.canvas.width / r.width),
                y: (e.clientY - r.top) * (this.canvas.height / r.height),
                p: e.pressure && e.pointerType === 'pen' ? e.pressure : 0.5,
            };
        }

        start(e) {
            e.preventDefault();
            this.canvas.setPointerCapture?.(e.pointerId);
            this.drawing = true;
            this.last = this.pos(e);
            this.ctx.beginPath();
            this.ctx.arc(this.last.x, this.last.y, this.ctx.lineWidth / 2, 0, Math.PI * 2);
            this.ctx.fillStyle = this.ctx.strokeStyle;
            this.ctx.fill();
            this.empty = false;
        }

        move(e) {
            if (!this.drawing) return;
            e.preventDefault();
            const p = this.pos(e);
            this.ctx.lineWidth = 1.6 + p.p * 2.4;
            this.ctx.beginPath();
            this.ctx.moveTo(this.last.x, this.last.y);
            // เส้นโค้งนุ่มขึ้นเล็กน้อย
            const mx = (this.last.x + p.x) / 2, my = (this.last.y + p.y) / 2;
            this.ctx.quadraticCurveTo(this.last.x, this.last.y, mx, my);
            this.ctx.lineTo(p.x, p.y);
            this.ctx.stroke();
            this.last = p;
        }

        end() {
            if (!this.drawing) return;
            this.drawing = false;
            this.onChange(this.toDataURL());
        }

        clear() {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            this.empty = true;
            this.onChange('');
        }

        /** ตัดขอบว่างรอบลายเซ็นออก แล้วคืนเป็น PNG data URL (พื้นโปร่งใส) */
        toDataURL() {
            if (this.empty) return '';
            const { width, height } = this.canvas;
            const data = this.ctx.getImageData(0, 0, width, height).data;
            let minX = width, minY = height, maxX = -1, maxY = -1;
            for (let y = 0; y < height; y++) {
                for (let x = 0; x < width; x++) {
                    if (data[(y * width + x) * 4 + 3] > 0) {
                        if (x < minX) minX = x;
                        if (x > maxX) maxX = x;
                        if (y < minY) minY = y;
                        if (y > maxY) maxY = y;
                    }
                }
            }
            if (maxX < 0) return '';
            const pad = 8;
            minX = Math.max(0, minX - pad); minY = Math.max(0, minY - pad);
            maxX = Math.min(width - 1, maxX + pad); maxY = Math.min(height - 1, maxY + pad);
            const out = document.createElement('canvas');
            out.width = maxX - minX + 1;
            out.height = maxY - minY + 1;
            out.getContext('2d').drawImage(this.canvas, minX, minY, out.width, out.height, 0, 0, out.width, out.height);
            return out.toDataURL('image/png');
        }
    }

    // ---------- หน้า "ลายเซ็นของฉัน" / ช่องเซ็นในหน้าบันทึกผล ----------
    document.querySelectorAll('[data-signature-pad]').forEach(canvas => {
        const scope = canvas.closest('[data-signature-chooser], [data-signature-form]') || document;
        const input = scope.querySelector('[data-signature-input]');
        const pad = new SignaturePad(canvas, url => { if (input) input.value = url; });
        scope.querySelector('[data-signature-clear]')?.addEventListener('click', () => pad.clear());

        const form = canvas.closest('form');
        const chooser = canvas.closest('[data-signature-chooser]');
        form?.addEventListener('submit', e => {
            // หน้า "ลายเซ็นของฉัน"
            if (!chooser && pad.empty) {
                e.preventDefault();
                alert('กรุณาเซ็นชื่อในกรอบก่อนบันทึก');
            }
        });
    });

    // ---------- ตัวเลือก ลายเซ็นออนไลน์ / กรอกชื่อ ----------
    document.querySelectorAll('[data-signature-chooser]').forEach(ch => {
        const online = ch.querySelector('[data-sign-online]');
        const name = ch.querySelector('[data-sign-name]');
        const draw = ch.querySelector('[data-sig-draw]');
        const savedPreview = ch.querySelector('[data-sig-saved-preview]');
        const input = ch.querySelector('[data-signature-input]');
        const saved = ch.querySelector('[data-saved-signature]')?.value || '';

        ch.querySelectorAll('[name=sign_method]').forEach(r => r.addEventListener('change', () => {
            const isOnline = ch.querySelector('[name=sign_method]:checked').value === 'online';
            online.hidden = !isOnline;
            name.hidden = isOnline;
        }));

        ch.querySelectorAll('[name=sig_source]').forEach(r => r.addEventListener('change', () => {
            const useSaved = ch.querySelector('[name=sig_source]:checked')?.value === 'saved';
            draw.hidden = useSaved;
            if (savedPreview) savedPreview.hidden = !useSaved;
            input.value = useSaved ? saved : '';
        }));

        const form = ch.closest('form');
        form?.addEventListener('submit', e => {
            const submitter = e.submitter;
            if (!submitter || submitter.value !== 'record') return;
            const isOnline = ch.querySelector('[name=sign_method]:checked').value === 'online';
            if (isOnline && !input.value) {
                e.preventDefault();
                alert('กรุณาเซ็นชื่อ หรือเลือก "กรอกชื่อ"');
            }
            if (!document.querySelector('[data-check-group="active"]:checked')) {
                e.preventDefault();
                alert('กรุณาเลือกนักศึกษาในตารางก่อน');
            }
        });
    });
})();
