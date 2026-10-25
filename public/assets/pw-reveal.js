/* Show / hide button on every password field (2026-10-25, at the user's request).
 *
 * Only Add User and Edit User carried one (.pw-wrap / .pw-toggle, handled in
 * remedi.js); sign-in, forgot password, My Profile, Safeguard, Backup and the
 * password pop-ups had none. This adds the same button to any password input
 * not already inside a .pw-wrap, on the app layout, the guest layout and the
 * welcome page alike -- which is why it draws its own SVG eye instead of the
 * Tabler font the guest pages do not load. Opt out with data-no-reveal.
 */
(function () {
    'use strict';

    var EYE = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.6 5.1A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-2.2 3.1M6.6 6.6C3.8 8.3 2 12 2 12s3.5 7 10 7c1.9 0 3.6-.6 5-1.5"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="M3 3l18 18"/></svg>';

    var style = document.createElement('style');
    style.textContent =
        '.pw-auto{position:relative;display:block}' +
        '.pw-auto>input{padding-right:42px!important;width:100%;box-sizing:border-box}' +
        '.pw-auto-btn{position:absolute;top:50%;right:6px;transform:translateY(-50%);width:30px;height:30px;' +
        'display:grid;place-items:center;padding:0;border:0;border-radius:8px;background:transparent;' +
        'color:#94a3b8;cursor:pointer}' +
        '.pw-auto-btn:hover{background:#f1f5f9;color:#334155}' +
        '.pw-auto-btn:focus-visible{outline:2px solid #10b981;outline-offset:1px}';
    document.head.appendChild(style);

    function enhance(input) {
        if (input.dataset.pwAuto || input.hasAttribute('data-no-reveal') || input.closest('.pw-wrap, .pw-auto')) return;
        input.dataset.pwAuto = '1';

        var wrap = document.createElement('span');
        wrap.className = 'pw-auto';

        // The field's own margins move to the wrapper, or a bottom margin
        // (the guest login page has 16px) sits inside it and pulls the
        // button below the middle of the box.
        var cs = window.getComputedStyle(input);
        wrap.style.margin = cs.marginTop + ' ' + cs.marginRight + ' ' + cs.marginBottom + ' ' + cs.marginLeft;
        input.style.margin = '0';

        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-auto-btn';
        btn.setAttribute('aria-label', 'Show password');
        btn.innerHTML = EYE;
        btn.addEventListener('click', function () {
            var shown = input.type === 'text';
            input.type = shown ? 'password' : 'text';
            btn.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
            btn.innerHTML = shown ? EYE : EYE_OFF;
            input.focus();
        });
        wrap.appendChild(btn);
    }

    function scan(root) {
        var fields = root.querySelectorAll ? root.querySelectorAll('input[type="password"]') : [];
        for (var i = 0; i < fields.length; i++) enhance(fields[i]);
    }

    function start() {
        scan(document);
        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                for (var j = 0; j < records[i].addedNodes.length; j++) {
                    var node = records[i].addedNodes[j];
                    if (node.nodeType !== 1) continue;
                    if (node.matches && node.matches('input[type="password"]')) enhance(node);
                    else scan(node);
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
