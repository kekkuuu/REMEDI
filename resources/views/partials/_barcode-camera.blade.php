{{-- Camera barcode scanning, RUNNING but with no visible preview, shared by
     the POS and Inventory scanner cards.

     The camera really is watching -- hold a barcode up to it and the product
     is rung up (POS) or its Manage Product page opens (Inventory), exactly as
     if a scanner gun had typed the code. What is hidden is the VIDEO, not the
     feature: the preview is rendered off-screen rather than on the card, so
     the page looks like a plain text field and nothing is given over to a
     live picture of the counter.

     Why off-screen rather than `display:none`: the decoder reads frames from
     a real <video> element, and a display:none element stops rendering, which
     stops the scan. It keeps its true size (the library sizes its capture
     from the element, and the stream's own resolution is unaffected by CSS)
     and is simply parked outside the viewport.

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
     That, a refused permission and a machine with no camera are all SILENT
     here on purpose: there is no widget to put a message in, and none of them
     is a failure worth interrupting anyone over, because the text field above
     and a scanner gun both keep working regardless. The browser's own
     camera-in-use indicator is what tells the person it is on -- deliberately
     not suppressed. --}}

@php
    /* Flip to false to stop the camera being used as a scanner at all: no
       markup, no CDN script, no getUserMedia, no permission prompt. */
    $cameraScannerEnabled = true;
@endphp

@if($cameraScannerEnabled)
{{-- Parked off-screen: present and rendering (so frames decode), but never
     seen. Not display:none -- see the note above.

     The OUTER shell is what does the hiding, and it has to be a separate
     element: html5-qrcode rewrites its own container's `position` to
     `relative` on init, which silently undid an `absolute` set directly on
     the reader and left a 200px hole in the scanner card. The library may do
     as it likes to the inner div; the shell keeps it out of the flow. --}}
<div id="camScanShell" aria-hidden="true"
     style="position:absolute; left:-10000px; top:0; width:280px; height:200px; overflow:hidden; opacity:0; pointer-events:none;">
    <div id="camScanReader" style="width:280px;"></div>
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

    const CONFIG = { fps: 10, aspectRatio: 1.4 };

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

        if (typeof window.handleScannedCode === 'function') {
            window.handleScannedCode(code);
        }
    }

    function start() {
        if (running || starting) return;
        if (typeof Html5Qrcode === 'undefined') return;
        if (!navigator.mediaDevices || !window.isSecureContext) return;

        starting = true;
        if (!scanner) scanner = new Html5Qrcode('camScanReader', { formatsToSupport: formats(), verbose: false });

        // facingMode "environment" is the REAR camera on a phone -- the one
        // pointed at the shelf. A machine with one webcam ignores it.
        scanner.start({ facingMode: 'environment' }, CONFIG, onDecoded, function () { /* no barcode this frame */ })
            .then(function () { starting = false; running = true; })
            .catch(function () {
                // Refused, missing, or already in use by something else. The
                // text field and a scanner gun both still work, so there is
                // nothing here worth surfacing.
                starting = false;
                running = false;
            });
    }

    function stop() {
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
