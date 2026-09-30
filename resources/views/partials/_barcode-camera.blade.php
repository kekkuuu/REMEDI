{{-- Camera barcode scanning, shared by the POS and Inventory scanner cards,
     with a SMALL aiming preview (2026-09-30, at the user's request).

     The preview is a second <video> playing the SAME MediaStream as the
     hidden reader below -- not the reader itself shrunk onto the card, since
     the reader's size is the decode resolution and a 176px reader reads
     nothing (measured: 280px read nothing at any distance). It appears only
     once the camera is actually running, so a refused or missing camera still
     leaves the card exactly as it was. Mirrored for a webcam facing the
     person (moving the box left moves it left on screen), not for a phone's
     rear camera. It flashes green when a code is read.

     The camera really is watching -- hold a barcode up to it and the product
     is rung up (POS) or its Manage Product page opens (Inventory), exactly as
     if a scanner gun had typed the code. What is hidden is the VIDEO, not the
     feature: the preview is rendered off-screen rather than on the card, so
     the page looks like a plain text field and nothing is given over to a
     live picture of the counter.

     Why off-screen rather than `display:none`: the decoder reads frames from
     a real <video> element, and a display:none element stops rendering, which
     stops the scan. It is simply parked outside the viewport -- at a LARGE
     size, because the library decodes at the element's size (see below).

     Whichever way a code arrives -- gun or camera -- it is handed to the
     page's own `handleScannedCode(code)`. This file owns NO lookup logic, so
     the two cannot drift into meaning different things.

     html5-qrcode is loaded from cdnjs per-page, the same convention Chart.js
     and qrcodejs already follow here. Formats are restricted to the 1D retail
     symbologies a pharmacy shelf carries plus QR, because leaving every format
     on makes ZXing try all of them on every frame and visibly drops the frame
     rate on a mid-range phone.

     Camera access needs a SECURE CONTEXT: https, or localhost. Both deploys
     are https and local dev is localhost, so all three are fine -- a tablet
     reaching the XAMPP box over `http://<LAN-ip>` has no camera API at all.
     When the camera cannot start, one quiet line says WHY (2026-09-30, at the
     user's request -- silence made "the preview is not showing" impossible to
     tell apart from a broken page): blocked permission, no camera, camera
     busy, an http address, or the scanner script not loading. Never a pop-up:
     the text field above and a scanner gun both keep working regardless. A
     "Try again" link retries, and a permission changed to Allow in the
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

{{-- Parked off-screen: present and rendering (so frames decode), but never
     seen. Not display:none -- see the note above.

     The OUTER shell is what does the hiding, and it has to be a separate
     element: html5-qrcode rewrites its own container's `position` to
     `relative` on init, which silently undid an `absolute` set directly on
     the reader and left a 200px hole in the scanner card. The library may do
     as it likes to the inner div; the shell keeps it out of the flow.

     The SIZE is load-bearing (2026-09-30): html5-qrcode draws each frame onto
     a canvas the size of this element and decodes THAT, not the camera's own
     resolution. At the 280x200 this used to be, a 720p frame was shrunk to
     280 px wide and a 1D barcode's bars merged -- the camera ran and never
     read anything. 1280x720 decodes at roughly the stream's real resolution. --}}
<div id="camScanShell" aria-hidden="true"
     style="position:absolute; left:-10000px; top:0; width:1280px; height:720px; overflow:hidden; opacity:0; pointer-events:none;">
    <div id="camScanReader" style="width:1280px;"></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    if (!document.getElementById('camScanReader')) return;

    let scanner = null;
    let running = false;
    let starting = false;
    let lastCode = '';
    let lastAt = 0;

    const CONFIG = {
        fps: 10,
        aspectRatio: 16 / 9,
        // Ask for a sharp stream: a webcam defaults to 640x480, too coarse
        // for the thin bars of an EAN-13 unless it is almost touching the lens.
        videoConstraints: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } },
    };

    function formats() {
        // Guard the enum rather than assuming it: if the CDN ever serves a
        // build without it, passing undefined into start() would throw and
        // take the scanner out with no visible sign that anything is wrong.
        if (typeof Html5QrcodeSupportedFormats === 'undefined') return undefined;
        return [
            Html5QrcodeSupportedFormats.EAN_13,
            Html5QrcodeSupportedFormats.EAN_8,
            Html5QrcodeSupportedFormats.UPC_A,
            Html5QrcodeSupportedFormats.UPC_E,
            Html5QrcodeSupportedFormats.CODE_128,
            Html5QrcodeSupportedFormats.CODE_39,
            Html5QrcodeSupportedFormats.ITF,
            Html5QrcodeSupportedFormats.QR_CODE,
        ];
    }

    function onDecoded(text) {
        const code = (text || '').trim();
        if (!code) return;

        /* A barcode held in front of a camera decodes on EVERY frame, so
           without this one scan would ring the same item up ten times. The
           same code inside 2.5s is one physical scan, not a second one.
           This matters more here than it would behind a dialog: there is no
           preview to pull away, so an item can sit in view for seconds. */
        const now = Date.now();
        if (code === lastCode && (now - lastAt) < 2500) return;
        lastCode = code;
        lastAt = now;

        flashHit();
        if (typeof window.handleScannedCode === 'function') {
            window.handleScannedCode(code);
        }
    }

    const previewWrap = document.getElementById('camPreviewWrap');
    const previewBox = document.getElementById('camPreviewBox');
    const preview = document.getElementById('camPreview');
    let hitTimer = null;

    function flashHit() {
        if (!previewBox) return;
        previewBox.classList.add('is-hit');
        clearTimeout(hitTimer);
        hitTimer = setTimeout(function () { previewBox.classList.remove('is-hit'); }, 700);
    }

    /* Show the reader's own stream in the small box. The library creates its
       <video> inside #camScanReader during start(), so this runs after it. */
    function showPreview() {
        if (!preview || !previewWrap) return;
        const src = document.querySelector('#camScanReader video');
        const stream = src && src.srcObject;
        if (!stream) return;
        preview.srcObject = stream;
        const track = stream.getVideoTracks()[0];
        const facing = track && track.getSettings ? track.getSettings().facingMode : undefined;
        previewBox.classList.toggle('is-mirrored', facing !== 'environment');
        const played = preview.play();
        if (played && played.catch) played.catch(function () {});
        previewWrap.hidden = false;
    }

    function hidePreview() {
        if (!preview || !previewWrap) return;
        previewWrap.hidden = true;
        preview.srcObject = null;
    }

    const statusBox = document.getElementById('camStatus');
    const statusText = document.getElementById('camStatusText');
    const statusRetry = document.getElementById('camStatusRetry');

    function showStatus(text, canRetry) {
        if (!statusBox) return;
        statusText.textContent = text + ' ';
        statusRetry.hidden = !canRetry;
        statusBox.hidden = false;
    }

    function hideStatus() { if (statusBox) statusBox.hidden = true; }

    /* html5-qrcode rejects with a STRING ("Error getting userMedia, error =
       NotAllowedError: Permission denied"), not the DOMException, so the
       browser's error name is matched inside it. */
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

    function start() {
        if (running || starting) return;
        if (!window.isSecureContext || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showStatus('Camera scanning needs the https:// address (or localhost). This address has no camera access.', false);
            return;
        }
        if (typeof Html5Qrcode === 'undefined') {
            showStatus('The camera scanner could not load. Check the internet connection, then reload.', false);
            return;
        }

        starting = true;
        try {
            if (!scanner) scanner = new Html5Qrcode('camScanReader', {
                formatsToSupport: formats(),
                verbose: false,
                // The browser's built-in barcode reader where it has one (Chrome on
                // Android and macOS): faster and far better at 1D codes than ZXing.
                experimentalFeatures: { useBarCodeDetectorIfSupported: true },
            });

            // facingMode "environment" is the REAR camera on a phone -- the one
            // pointed at the shelf. A machine with one webcam ignores it.
            scanner.start({ facingMode: 'environment' }, CONFIG, onDecoded, function () { /* no barcode this frame */ })
                .then(function () { starting = false; running = true; hideStatus(); showPreview(); })
                .catch(failed);
        } catch (err) {
            // A synchronous throw would otherwise leave `starting` stuck true
            // and every later start() a silent no-op.
            failed(err);
        }
    }

    function failed(err) {
        starting = false;
        running = false;
        // A fresh instance for the next attempt: a failed start can leave the
        // library mid-transition, refusing to start again.
        scanner = null;
        const reader = document.getElementById('camScanReader');
        if (reader) reader.innerHTML = '';
        showStatus(explain(err), true);
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

    function stop() {
        hidePreview();
        if (!scanner || !running) { running = false; return Promise.resolve(); }
        running = false;
        // stop() REJECTS when it was never really running; swallow it or a
        // backgrounded tab logs an unhandled rejection.
        return scanner.stop().catch(function () {});
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
