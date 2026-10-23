{{-- Camera barcode scanning, shared by the POS and Inventory scanner cards.

     Hold a barcode up to the camera and the product is rung up (POS) or its
     Manage Product page opens (Inventory), exactly as if a scanner gun had
     typed the code. Whichever way a code arrives -- gun or camera -- it is
     handed to the page's own `handleScannedCode(code)`. This file owns NO
     lookup logic, so the two cannot drift into meaning different things.

     THE DECODER (2026-09-30): the browser's BarcodeDetector where it has one
     (Chrome on Android and macOS), otherwise the `barcode-detector` ponyfill
     -- the same API over ZXing C++ compiled to WebAssembly. This REPLACED
     html5-qrcode, whose ZXing-js reader could not read printed barcodes:
     against one synthetic camera picture it read none of straight, tilted
     10deg, sideways, a bit blurry, or further away, while ZXing C++ read all
     five. html5-qrcode also decoded at its container's CSS size, which is why
     it needed a hidden 1280x720 reader; BarcodeDetector.detect(video) reads
     the stream's own frames, so the small preview below IS the source.

     The preview (176x99, red aim line, green flash on a read) shows only once
     the camera is running. Mirrored for a webcam facing the person (moving
     the box left moves it left on screen), not for a phone's rear camera.

     SHARPENING (2026-10-22): every third frame is decoded plain; the other two
     decode the strip around the red line after an unsharp mask (greyscale,
     box-blur radius 4 or 8 px, amount 2). A laptop webcam is focused for a
     face ~50 cm away, so a box held close is soft, and ZXing stops reading
     once the blur reaches about half a bar's width. Measured on a drawn
     EAN-13: plain frames read up to ~0.5 bar-widths of blur, sharpened ones
     up to ~0.7. It helps a slightly soft picture; it cannot rescue a badly
     blurred one -- a scanner gun or a phone camera is the real fix there.

     THE CAMERA'S SIZE (2026-10-22): "Camera: 1280x720" under the hint is the
     frame size the decoder actually gets (videoWidth x videoHeight), not what
     was asked for -- `ideal: 1920x1080` falls back silently, and a 640x480
     webcam gives a product barcode about half the pixels per bar.

     THE NUDGE: "Can't read it yet..." shows only while something is MOVING
     in front of the camera (a 32x18 frame difference) and nothing has been
     read for NUDGE_AFTER_MS -- never for an idle camera facing the counter.

     Camera access needs a SECURE CONTEXT: https, or localhost. When the
     camera cannot start, one quiet amber line says WHY: blocked permission,
     no camera, camera busy, an http address, or the scanner not loading.
     Never a pop-up: the text field above and a scanner gun both keep working
     regardless. "Try again" retries, and a permission changed to Allow in the
     address bar starts the camera without a reload. --}}

@php
    /* Flip to false to stop the camera being used as a scanner at all: no
       markup, no CDN script, no getUserMedia, no permission prompt. */
    $cameraScannerEnabled = true;
@endphp

@if($cameraScannerEnabled)
<style>
    .cam-preview { display: flex; align-items: center; gap: 10px; margin-top: 8px; }
    .cam-preview[hidden] { display: none; }
    .cam-preview__box { position: relative; width: 176px; height: 99px; flex: none; border-radius: 8px; overflow: hidden; background: #0f172a; border: 2px solid #cbd5e1; transition: border-color .15s ease, box-shadow .15s ease; }
    .cam-preview__box video { width: 100%; height: 100%; object-fit: cover; display: block; }
    .cam-preview__box.is-mirrored video { transform: scaleX(-1); }
    /* The aim line: where to put the barcode. */
    .cam-preview__aim { position: absolute; left: 12%; right: 12%; top: 50%; height: 2px; margin-top: -1px; background: rgba(239, 68, 68, .85); box-shadow: 0 0 4px rgba(239, 68, 68, .8); pointer-events: none; }
    .cam-preview__box.is-hit { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22, 163, 74, .35); }
    .cam-preview__box.is-hit .cam-preview__aim { background: #22c55e; box-shadow: 0 0 4px #22c55e; }
    .cam-preview__hint { font-size: .8rem; color: #64748b; line-height: 1.35; }
    .cam-preview__hint strong { color: #334155; font-weight: 600; }
    .cam-preview__nudge { display: block; margin-top: 3px; color: #b45309; }
    .cam-preview__nudge[hidden] { display: none; }
    .cam-preview__res { display: block; margin-top: 3px; font-size: .72rem; color: #94a3b8; font-variant-numeric: tabular-nums; }
    .cam-preview__res:empty { display: none; }
    .cam-status { display: flex; align-items: flex-start; gap: 6px; margin-top: 8px; padding: 6px 10px; border-radius: 6px; background: #fffbeb; border: 1px solid #fde68a; color: #92400e; font-size: .8rem; line-height: 1.35; }
    .cam-status[hidden] { display: none; }
    .cam-status i { font-size: 1rem; line-height: 1.1; flex: none; }
    .cam-status button { background: none; border: 0; padding: 0; margin-left: 4px; color: #b45309; font: inherit; font-weight: 600; text-decoration: underline; cursor: pointer; }
</style>
<div class="cam-status" id="camStatus" role="status" hidden>
    <i class="ti ti-camera-off" aria-hidden="true"></i>
    <span><span id="camStatusText"></span><button type="button" id="camStatusRetry" hidden>Try again</button></span>
</div>
<div class="cam-preview" id="camPreviewWrap" hidden>
    <div class="cam-preview__box" id="camPreviewBox">
        <video id="camPreview" muted playsinline autoplay aria-label="Camera preview for aiming a barcode"></video>
        <span class="cam-preview__aim" aria-hidden="true"></span>
    </div>
    <div class="cam-preview__hint">
        <strong><i class="ti ti-camera" aria-hidden="true"></i> Camera scanner on</strong><br>
        Hold the barcode across the red line, steady and in good light.
        <span class="cam-preview__nudge" id="camNudge" role="status" hidden>
            <i class="ti ti-focus-2" aria-hidden="true"></i>
            Can&rsquo;t read it yet. Try more light, keep it still, or move it back a little until the bars look sharp.
        </span>
        <span class="cam-preview__res" id="camRes"></span>
    </div>
</div>

{{-- Pinned version: the ponyfill in turn pins the zxing-wasm build it loads. --}}
<script src="https://cdn.jsdelivr.net/npm/barcode-detector@3.2.2/dist/iife/ponyfill.js"></script>
<script>
(function () {
    const preview = document.getElementById('camPreview');
    if (!preview) return;

    const previewWrap = document.getElementById('camPreviewWrap');
    const previewBox = document.getElementById('camPreviewBox');
    const statusBox = document.getElementById('camStatus');
    const statusText = document.getElementById('camStatusText');
    const statusRetry = document.getElementById('camStatusRetry');
    const nudge = document.getElementById('camNudge');
    const resLabel = document.getElementById('camRes');

    /* The frame size the decoder really receives -- see the note at the top.
       Re-read on resize: some cameras renegotiate a moment after starting. */
    function showResolution() {
        if (!resLabel) return;
        resLabel.textContent = preview.videoWidth
            ? 'Camera: ' + preview.videoWidth + '\u00d7' + preview.videoHeight
            : '';
    }
    preview.addEventListener('loadedmetadata', showResolution);
    preview.addEventListener('resize', showResolution);

    // The 1D retail symbologies a pharmacy shelf carries, plus QR. Fewer
    // formats is less work per frame.
    const WANTED = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code'];
    const SCAN_EVERY_MS = 120;
    // 0.8 s (was 1.5 s until 2026-10-22, at the user's request): long enough
    // that a box held in view -- seen ~6 times a second -- stays one scan,
    // short enough to show the next identical box without a long pause. The
    // trade-off: a very soft webcam that loses a code for 0.8 s while it is
    // still held can add it twice; the cart's +1 and Qty box are there for
    // several of the same item either way.
    const REPEAT_GAP_MS = 800;

    let stream = null;
    let detector = null;
    let running = false;
    let starting = false;
    let loopTimer = null;
    let lastCode = '';
    let lastAt = 0;
    let hitTimer = null;

    /* Sharpening -- see the note at the top. 0 = the raw frame (the whole
       picture, so a barcode held sideways still reads); otherwise the box-blur
       radius, in pixels, the unsharp mask subtracts. */
    const PASSES = [0, 4, 8];
    const SHARPEN_AMOUNT = 2;
    let pass = 0;
    const band = document.createElement('canvas');
    const bandCtx = band.getContext('2d', { willReadFrequently: true });
    let grey = null, scratch = null, soft = null;

    /* Box blur, horizontal then vertical, by running sums: O(pixels) whatever
       the radius. Edges repeat the border pixel. */
    function boxBlur(src, tmp, dst, w, h, r) {
        const k = 1 / (2 * r + 1);
        for (let y = 0; y < h; y++) {
            const row = y * w;
            let sum = 0;
            for (let x = -r; x <= r; x++) sum += src[row + Math.min(w - 1, Math.max(0, x))];
            for (let x = 0; x < w; x++) {
                tmp[row + x] = sum * k;
                sum += src[row + Math.min(w - 1, x + r + 1)] - src[row + Math.max(0, x - r)];
            }
        }
        for (let x = 0; x < w; x++) {
            let sum = 0;
            for (let y = -r; y <= r; y++) sum += tmp[Math.min(h - 1, Math.max(0, y)) * w + x];
            for (let y = 0; y < h; y++) {
                dst[y * w + x] = sum * k;
                sum += tmp[Math.min(h - 1, y + r + 1) * w + x] - tmp[Math.max(0, y - r) * w + x];
            }
        }
    }

    /* The middle half of the frame -- where the red line is -- in grey,
       sharpened: grey + amount * (grey - blurred). Returned as ImageData,
       which BarcodeDetector.detect() takes directly. Buffers are reused, since
       this runs several times a second on ~1M pixels. */
    function sharpenedBand(source, radius) {
        const vw = source.videoWidth || source.width, vh = source.videoHeight || source.height;
        const bh = Math.max(1, Math.round(vh * 0.5)), y0 = Math.round((vh - bh) / 2);
        if (band.width !== vw || band.height !== bh) { band.width = vw; band.height = bh; }
        bandCtx.drawImage(source, 0, y0, vw, bh, 0, 0, vw, bh);
        const img = bandCtx.getImageData(0, 0, vw, bh);
        const d = img.data, n = vw * bh;
        if (!grey || grey.length !== n) { grey = new Float32Array(n); scratch = new Float32Array(n); soft = new Float32Array(n); }
        for (let i = 0, j = 0; i < n; i++, j += 4) grey[i] = 0.299 * d[j] + 0.587 * d[j + 1] + 0.114 * d[j + 2];
        boxBlur(grey, scratch, soft, vw, bh, radius);
        for (let i = 0, j = 0; i < n; i++, j += 4) {
            const v = grey[i] + SHARPEN_AMOUNT * (grey[i] - soft[i]);
            d[j] = d[j + 1] = d[j + 2] = v;   // Uint8ClampedArray clamps and rounds
        }
        return img;
    }

    /* The nudge -- see the note at the top. A "session" starts when the
       picture changes (someone holds something up) and ends after
       SESSION_IDLE_MS of stillness; a read ends it at once, and the movement
       of taking the scanned box away does not start a new one. */
    const NUDGE_AFTER_MS = 4000;
    const SESSION_IDLE_MS = 8000;
    const AFTER_READ_QUIET_MS = 3000;
    // Mean grey-level change per pixel (0-255) at 32x18. A still camera reads
    // ~0 -- the downscale averages its noise away -- while a box moved across
    // the frame read 4.8 on a test picture, so 8 never fired.
    const MOTION_LEVEL = 3;
    const tiny = document.createElement('canvas');
    tiny.width = 32;
    tiny.height = 18;
    const tinyCtx = tiny.getContext('2d', { willReadFrequently: true });
    let prevTiny = null, sessionStart = 0, lastMotion = 0;

    function noteMotion() {
        tinyCtx.drawImage(preview, 0, 0, 32, 18);
        const d = tinyCtx.getImageData(0, 0, 32, 18).data;
        const cur = new Float32Array(576);
        let diff = 0;
        for (let i = 0, j = 0; i < 576; i++, j += 4) {
            cur[i] = (d[j] + d[j + 1] + d[j + 2]) / 3;
            if (prevTiny) diff += Math.abs(cur[i] - prevTiny[i]);
        }
        const now = Date.now();
        if (prevTiny && diff / 576 > MOTION_LEVEL && now - lastAt > AFTER_READ_QUIET_MS) {
            if (!sessionStart || now - lastMotion > SESSION_IDLE_MS) sessionStart = now;
            lastMotion = now;
        }
        prevTiny = cur;
    }

    function updateNudge() {
        if (!nudge) return;
        const now = Date.now();
        const active = sessionStart && now - lastMotion < SESSION_IDLE_MS;
        nudge.hidden = !(active && now - sessionStart > NUDGE_AFTER_MS);
    }

    /* Native where the browser has it and reads EAN-13 (Chrome's Windows
       build ships no BarcodeDetector at all), else the ZXing C++ ponyfill. */
    async function makeDetector() {
        if ('BarcodeDetector' in window && window.BarcodeDetector.getSupportedFormats) {
            try {
                const have = await window.BarcodeDetector.getSupportedFormats();
                const formats = WANTED.filter(function (f) { return have.indexOf(f) !== -1; });
                if (formats.indexOf('ean_13') !== -1) return new window.BarcodeDetector({ formats: formats });
            } catch (e) { /* fall through to the ponyfill */ }
        }
        if (window.BarcodeDetectionAPI && window.BarcodeDetectionAPI.BarcodeDetector) {
            return new window.BarcodeDetectionAPI.BarcodeDetector({ formats: WANTED });
        }
        return null;
    }

    function showStatus(text, canRetry) {
        if (!statusBox) return;
        statusText.textContent = text + ' ';
        statusRetry.hidden = !canRetry;
        statusBox.hidden = false;
    }

    function hideStatus() { if (statusBox) statusBox.hidden = true; }

    function explain(err) {
        const msg = String((err && err.name ? err.name + ': ' + err.message : err) || '');
        if (/NotAllowed|Permission|denied|SecurityError/i.test(msg)) {
            return 'Camera blocked. Click the camera icon in the address bar, choose Allow, then reload.';
        }
        if (/NotFound|DevicesNotFound|device not found|Overconstrained/i.test(msg)) {
            return 'No camera found on this device. The barcode box and a scanner gun still work.';
        }
        if (/NotReadable|TrackStart|Could not start|in use/i.test(msg)) {
            return 'The camera is being used by another app (Zoom, Messenger, Camera). Close it, then try again.';
        }
        return 'The camera could not start.';
    }

    function flashHit() {
        previewBox.classList.add('is-hit');
        clearTimeout(hitTimer);
        hitTimer = setTimeout(function () { previewBox.classList.remove('is-hit'); }, 700);
    }

    function onDecoded(text) {
        const code = (text || '').trim();
        if (!code) return;

        /* A barcode held in front of a camera decodes on EVERY frame, so
           without this one scan would ring the same item up ten times. The
           same code is one physical scan for as long as it STAYS in view --
           every sighting pushes the window on -- and a new scan only once it
           has been out of view for REPEAT_GAP_MS. A fixed window from the
           first read added an item twice when it was held for 3 seconds. */
        const now = Date.now();
        sessionStart = 0;
        if (nudge) nudge.hidden = true;
        const sameScan = code === lastCode && (now - lastAt) < REPEAT_GAP_MS;
        lastCode = code;
        lastAt = now;
        if (sameScan) return;

        flashHit();
        if (typeof window.handleScannedCode === 'function') {
            window.handleScannedCode(code);
        }
    }

    /* One detect at a time: the next is scheduled only after this one
       settles, so a slow frame can never pile work up behind it. */
    function scanLoop() {
        if (!running) return;
        const ready = preview.readyState >= 2 && preview.videoWidth > 0;
        let work = Promise.resolve([]);
        if (ready) {
            const radius = PASSES[pass];
            pass = (pass + 1) % PASSES.length;
            try {
                noteMotion();
                work = detector.detect(radius ? sharpenedBand(preview, radius) : preview);
            } catch (e) {
                work = Promise.reject(e);
            }
        }
        work.then(function (codes) {
            if (running && codes && codes.length) onDecoded(codes[0].rawValue);
        }).catch(function () { /* a frame that could not be read */ })
          .then(function () {
              updateNudge();
              if (running) loopTimer = setTimeout(scanLoop, SCAN_EVERY_MS);
          });
    }

    /* GREEN PICTURE (2026-10-23, at the user's request: "my DroidCam stays green").
       Virtual cameras -- DroidCam above all -- answer a size they cannot send
       (free DroidCam sends 640x480; we ask for 1080p) with a solid green frame,
       and also send green while their PC client is not connected to the phone.
       So after starting, the picture is sampled; if it is solid green three
       times in a row, the camera restarts one size down, and the size that worked is
       remembered (per browser; "Try again" starts from 1080p again) so the next page
       starts on it directly. Only
       GREEN triggers it: a white wall or a covered lens is a real picture. */
    const SIZES = [[1920, 1080], [1280, 720], [640, 480]];
    let sizeIndex = 0;
    let greenTimer = null;
    const SIZE_KEY = 'remedi.camSize';

    function rememberedSize() {
        try {
            const v = parseInt(localStorage.getItem(SIZE_KEY), 10);
            return v >= 0 && v < SIZES.length ? v : 0;
        } catch (e) { return 0; }
    }

    function rememberSize(index) {
        try { localStorage.setItem(SIZE_KEY, String(index)); } catch (e) { /* private window */ }
    }

    // What the camera is showing: 'green' (solid green, the virtual camera's
    // "no picture"), 'blank' (one flat colour, e.g. black while it warms up --
    // cannot tell yet), 'picture' (anything with detail), or null (no frame).
    function framePicture() {
        if (!(preview.videoWidth > 0)) return null;
        const c = document.createElement('canvas');
        c.width = 32; c.height = 18;
        const g = c.getContext('2d', { willReadFrequently: true });
        g.drawImage(preview, 0, 0, 32, 18);
        const px = g.getImageData(0, 0, 32, 18).data;
        const total = px.length / 4;
        let green = 0, sum = 0, sumSq = 0;
        for (let i = 0; i < px.length; i += 4) {
            const r = px[i], gr = px[i + 1], b = px[i + 2];
            if (gr > 30 && gr > r + 25 && gr > b + 25) green++;
            const y = 0.299 * r + 0.587 * gr + 0.114 * b;
            sum += y; sumSq += y * y;
        }
        if (green / total > 0.85) return 'green';
        const mean = sum / total;
        return Math.sqrt(Math.max(0, sumSq / total - mean * mean)) < 3 ? 'blank' : 'picture';
    }

    // Sampled every 400 ms for up to ~8 s after the camera starts: three green
    // samples in a row step down a size; a real picture is remembered and ends
    // the check; a blank frame (warming up) just waits for the next sample.
    const GREEN_SAMPLES = 3, MAX_SAMPLES = 20;
    function checkForGreen(attempt, greenRun) {
        clearTimeout(greenTimer);
        if (!running) return;
        const seen = framePicture();
        if (seen === 'picture') { rememberSize(sizeIndex); return; }
        const run = seen === 'green' ? greenRun + 1 : 0;
        if (run < GREEN_SAMPLES) {
            if (attempt < MAX_SAMPLES) {
                greenTimer = setTimeout(function () { checkForGreen(attempt + 1, run); }, 400);
            }
            return;
        }

        // Green on every sample: try the next size down.
        running = false;
        clearTimeout(loopTimer);
        releaseStream();
        if (sizeIndex < SIZES.length - 1) {
            sizeIndex++;
            start(true);
            return;
        }
        previewWrap.hidden = true;
        showStatus('The camera only shows a green picture. If it is DroidCam, open DroidCam on the PC, connect the phone, then Try again.', true);
    }

    async function start(keepSize) {
        if (running || starting) return;
        if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showStatus('Camera scanning needs the https:// address (or localhost). This address has no camera access.', false);
            return;
        }

        if (keepSize !== true) sizeIndex = rememberedSize();
        starting = true;
        try {
            if (!detector) detector = await makeDetector();
            if (!detector) {
                starting = false;
                showStatus('The camera scanner could not load. Check the internet connection, then reload.', true);
                return;
            }

            // "environment" is the REAR camera on a phone -- the one pointed at
            // the shelf; a machine with one webcam ignores it. 1080p asked
            // for first because a webcam's 640x480 default is too coarse for thin
            // bars; smaller only after a green picture (checkForGreen).
            const size = SIZES[sizeIndex];
            stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: { facingMode: { ideal: 'environment' }, width: { ideal: size[0] }, height: { ideal: size[1] } },
            });

            const track = stream.getVideoTracks()[0];
            // Continuous autofocus where the camera offers it; most webcams
            // simply ignore this.
            if (track && track.applyConstraints) {
                track.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {});
            }
            const facing = track && track.getSettings ? track.getSettings().facingMode : undefined;
            previewBox.classList.toggle('is-mirrored', facing !== 'environment');

            preview.srcObject = stream;
            await preview.play().catch(function () {});
            showResolution();

            starting = false;
            running = true;
            hideStatus();
            previewWrap.hidden = false;
            scanLoop();
            greenTimer = setTimeout(function () { checkForGreen(1, 0); }, 600);
        } catch (err) {
            starting = false;
            releaseStream();
            showStatus(explain(err), true);
        }
    }

    function releaseStream() {
        if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
        stream = null;
        preview.srcObject = null;
    }

    function stop() {
        running = false;
        clearTimeout(loopTimer);
        clearTimeout(greenTimer);
        previewWrap.hidden = true;
        sessionStart = 0;
        prevTiny = null;
        if (nudge) nudge.hidden = true;
        releaseStream();
        return Promise.resolve();
    }

    // Try again starts from 1080p: the client may be connected now, or a
    // better camera plugged in.
    if (statusRetry) statusRetry.addEventListener('click', function () { hideStatus(); sizeIndex = 0; start(true); });

    /* Allowing the camera from the address bar fires a permission change --
       start straight away rather than making the person reload. Not every
       browser supports querying "camera"; where it does not, Try again and a
       reload still work. */
    if (navigator.permissions && navigator.permissions.query) {
        navigator.permissions.query({ name: 'camera' }).then(function (perm) {
            perm.addEventListener('change', function () {
                if (perm.state === 'granted') { hideStatus(); start(); }
            });
        }).catch(function () {});
    }

    /* The camera holds the device's capture pipeline open, so leaving it
       running behind a backgrounded tab drains the battery of the very
       machine most likely to be a phone. Released on hide, picked back up on
       return -- which is what makes an always-on scanner affordable. */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) { stop(); } else { start(); }
    });

    window.addEventListener('pagehide', function () { stop(); });

    start();

    window.REMEDI_CAMERA_SCAN = {
        start: start,
        stop: stop,
        // For checking the sharpening against a drawn barcode where no camera exists.
        sharpenedBand: sharpenedBand,
    };
})();
</script>
@endif
