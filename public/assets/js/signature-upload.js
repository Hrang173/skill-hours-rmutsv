/* ===== อัปโหลดลายเซ็นจากรูป / PDF แล้วครอบตัด (หน้า "ลายเซ็นของฉัน") =====
 * - รูป: เปิดตรง ๆ · PDF: แปลงหน้าที่เลือกเป็นรูปด้วย pdf.js (โหลดเมื่อเลือก PDF เท่านั้น)
 * - ครอบตัดด้วย Cropper.js แล้วแสดงตัวอย่างในกรอบขนาดเท่าช่องจริงบนแบบบันทึก
 * - ลบพื้นหลังสีขาวให้โปร่งใส, ย่อให้ไม่เกิน 900x300 px แล้วบันทึกเป็น PNG
 */
(function () {
    'use strict';

    const form = document.querySelector('[data-signature-upload]');
    if (!form || !window.Cropper) return;

    const PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
    const MAX_W = 900, MAX_H = 300, MAX_FILE = 15 * 1024 * 1024;

    const $ = sel => form.querySelector(sel);
    const fileInput = $('[data-upload-file]');
    const editor = $('[data-upload-editor]');
    const image = $('[data-upload-image]');
    const pageWrap = $('[data-pdf-page-wrap]');
    const pageSelect = $('[data-pdf-page]');
    const transparent = $('[data-opt-transparent]');
    const threshold = $('[data-opt-threshold]');
    const previews = form.querySelectorAll('[data-preview]');
    const output = $('[data-upload-output]');
    const saveBtn = $('[data-upload-save]');
    const status = $('[data-upload-status]');

    let cropper = null;
    let pdfDoc = null;
    let objectUrl = null;

    const setStatus = (msg, isError) => {
        status.textContent = msg || '';
        status.classList.toggle('text-danger', !!isError);
    };

    /** โหลด pdf.js จาก CDN ครั้งเดียว */
    let pdfjsReady = null;
    const loadPdfJs = () => pdfjsReady ??= new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = PDFJS + 'pdf.min.js';
        s.onload = () => {
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js';
            resolve(window.pdfjsLib);
        };
        s.onerror = () => { pdfjsReady = null; reject(new Error('โหลดตัวอ่าน PDF ไม่ได้ (ต้องต่ออินเทอร์เน็ต)')); };
        document.head.appendChild(s);
    });

    /** แปลงหน้า PDF เป็นรูป (ความละเอียดประมาณ 200 dpi) */
    const renderPdfPage = async n => {
        const page = await pdfDoc.getPage(n);
        const viewport = page.getViewport({ scale: 200 / 72 });
        const canvas = document.createElement('canvas');
        canvas.width = Math.floor(viewport.width);
        canvas.height = Math.floor(viewport.height);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvasContext: ctx, viewport }).promise;
        return canvas.toDataURL('image/png');
    };

    const showImage = src => {
        cropper?.destroy();
        editor.hidden = false;
        image.src = src;
        cropper = new Cropper(image, {
            viewMode: 1,
            autoCropArea: 0.6,
            background: true,
            zoomOnWheel: true,
            responsive: true,
            ready() { updatePreview(); saveBtn.disabled = false; },
            crop: schedulePreview,
        });
    };

    /** ภาพที่ครอบตัดแล้ว (ย่อ + ลบพื้นหลังขาวถ้าเลือก) */
    const croppedCanvas = () => {
        if (!cropper) return null;
        const src = cropper.getCroppedCanvas({ maxWidth: MAX_W * 2, maxHeight: MAX_H * 2, imageSmoothingQuality: 'high' });
        if (!src || !src.width || !src.height) return null;
        const scale = Math.min(1, MAX_W / src.width, MAX_H / src.height);
        const out = document.createElement('canvas');
        out.width = Math.max(1, Math.round(src.width * scale));
        out.height = Math.max(1, Math.round(src.height * scale));
        const ctx = out.getContext('2d');
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(src, 0, 0, out.width, out.height);
        if (transparent.checked) {
            const img = ctx.getImageData(0, 0, out.width, out.height);
            const d = img.data, t = +threshold.value, soft = 40;
            for (let i = 0; i < d.length; i += 4) {
                const lum = 0.299 * d[i] + 0.587 * d[i + 1] + 0.114 * d[i + 2];
                if (lum >= t) {
                    d[i + 3] = 0;
                } else if (lum > t - soft) {
                    // ขอบเส้นค่อย ๆ จางลง ไม่ให้เป็นหยัก
                    d[i + 3] = Math.round(d[i + 3] * (t - lum) / soft);
                }
            }
            ctx.putImageData(img, 0, 0);
        }
        return out;
    };

    const updatePreview = () => {
        const c = croppedCanvas();
        const url = c ? c.toDataURL('image/png') : '';
        previews.forEach(p => { p.src = url; p.hidden = !url; });
    };
    let raf = 0;
    function schedulePreview() {
        cancelAnimationFrame(raf);
        raf = requestAnimationFrame(updatePreview);
    }

    fileInput.addEventListener('change', async () => {
        const file = fileInput.files[0];
        saveBtn.disabled = true;
        setStatus('');
        pageWrap.hidden = true;
        pdfDoc = null;
        if (!file) return;
        if (file.size > MAX_FILE) {
            setStatus('ไฟล์ใหญ่เกิน 15 MB', true);
            return;
        }
        try {
            if (file.type === 'application/pdf' || /\.pdf$/i.test(file.name)) {
                setStatus('กำลังเปิด PDF...');
                const pdfjsLib = await loadPdfJs();
                pdfDoc = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
                pageSelect.innerHTML = '';
                for (let i = 1; i <= pdfDoc.numPages; i++) pageSelect.add(new Option(String(i), String(i)));
                pageWrap.hidden = pdfDoc.numPages < 2;
                showImage(await renderPdfPage(1));
                setStatus('');
            } else if (file.type.startsWith('image/')) {
                if (objectUrl) URL.revokeObjectURL(objectUrl);
                objectUrl = URL.createObjectURL(file);
                showImage(objectUrl);
            } else {
                setStatus('รองรับเฉพาะไฟล์รูป (JPG, PNG) หรือ PDF', true);
            }
        } catch (e) {
            setStatus(e.message || 'เปิดไฟล์ไม่ได้', true);
        }
    });

    pageSelect.addEventListener('change', async () => {
        if (!pdfDoc) return;
        setStatus('กำลังเปิดหน้า ' + pageSelect.value + '...');
        showImage(await renderPdfPage(+pageSelect.value));
        setStatus('');
    });

    form.querySelectorAll('[data-rotate]').forEach(b => b.addEventListener('click', () => cropper?.rotate(+b.dataset.rotate)));
    $('[data-crop-reset]').addEventListener('click', () => cropper?.reset());
    transparent.addEventListener('change', () => { threshold.disabled = !transparent.checked; updatePreview(); });
    threshold.addEventListener('input', schedulePreview);

    form.addEventListener('submit', e => {
        const c = croppedCanvas();
        if (!c) {
            e.preventDefault();
            alert('กรุณาเลือกไฟล์และครอบตัดลายเซ็นก่อนบันทึก');
            return;
        }
        output.value = c.toDataURL('image/png');
    });
})();
