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
        Hold the barcode across the red line, about 15&ndash;25&nbsp;cm away.
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

    // The 1D retail symbologies a pharmacy shelf carries, plus QR. Fewer
    // formats is less work per frame.
    const WANTED = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf', 'qr_code'];
    const SCAN_EVERY_MS = 120;
    const REPEAT_GAP_MS = 1500;

    let stream = null;
    let detector = null;
    let running = false;
    let starting = false;
    let loopTimer = null;
    let lastCode = '';
    let lastAt = 0;
    let hitTimer = null;

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
        const work = ready ? detector.detect(preview) : Promise.resolve([]);
        work.then(function (codes) {
            if (running && codes && codes.length) onDecoded(codes[0].rawValue);
        }).catch(function () { /* a frame that could not be read */ })
          .then(function () {
              if (running) loopTimer = setTimeout(scanLoop, SCAN_EVERY_MS);
          });
    }

    async function start() {
        if (running || starting) return;
        if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showStatus('Camera scanning needs the https:// address (or localhost). This address has no camera access.', false);
            return;
        }

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
            // for because a webcam's 640x480 default is too coarse for thin bars.
            stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } },
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

            starting = false;
            running = true;
            hideStatus();
            previewWrap.hidden = false;
            scanLoop();
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
        previewWrap.hidden = true;
        releaseStream();
        return Promise.resolve();
    }

    if (statusRetry) statusRetry.addEventListener('click', function () { hideStatus(); start(); });

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

    window.REMEDI_CAMERA_SCAN = { start: start, stop: stop };
})();
</script>
@endif
