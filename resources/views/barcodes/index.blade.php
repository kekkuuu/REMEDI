@extends('layouts.app')

@section('title', 'Print Barcodes')

@section('content')
{{-- Print Barcodes (2026-09-30). The bars are DRAWN here as CODE 128 of each
     SKU exactly as stored -- see BarcodeController for why not EAN-13 and why
     not a barcode font. Everything happens in the browser: nothing is saved
     except the viewer's own sheet (localStorage), so a half-built sheet
     survives a reload. --}}
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.6/JsBarcode.all.min.js"></script>
<style>
    .bc-layout { display: grid; grid-template-columns: minmax(300px, 380px) 1fr; gap: 20px; align-items: start; }
    @media (max-width: 1000px) { .bc-layout { grid-template-columns: 1fr; } }
    .bc-controls { display: flex; flex-direction: column; gap: 18px; }
    .bc-block > label, .bc-block > .bc-label { display: block; font-weight: 600; font-size: 13px; color: #334155; margin-bottom: 6px; }
    .bc-hint { font-size: 12px; color: #64748b; margin-top: 4px; }
    .bc-search { position: relative; }
    .bc-search input, .bc-row-inline select { width: 100%; padding: 9px 11px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; background: #fff; }
    .bc-suggest { position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 30; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 10px 24px rgba(15, 23, 42, .12); max-height: 300px; overflow-y: auto; }
    .bc-suggest[hidden] { display: none; }
    .bc-suggest button { display: block; width: 100%; text-align: left; padding: 8px 12px; border: 0; background: none; cursor: pointer; font: inherit; }
    .bc-suggest button:hover, .bc-suggest button.is-active { background: #f1f5f9; }
    .bc-suggest .bc-s-name { display: block; font-size: 13.5px; font-weight: 600; color: #0f172a; }
    .bc-suggest .bc-s-sku { display: block; font-size: 12px; color: #64748b; }
    .bc-suggest .bc-s-empty { padding: 10px 12px; font-size: 13px; color: #64748b; }
    .bc-row-inline { display: flex; gap: 8px; }
    .bc-row-inline select { flex: 1; min-width: 0; }
    .bc-sizes { display: inline-flex; border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden; }
    .bc-sizes label { padding: 7px 14px; font-size: 13px; cursor: pointer; border-right: 1px solid #d1d5db; user-select: none; }
    .bc-sizes label:last-child { border-right: 0; }
    .bc-sizes input { position: absolute; opacity: 0; pointer-events: none; }
    .bc-sizes label:has(input:checked) { background: var(--brand, #10b981); color: #fff; }
    .bc-sizes label:has(input:focus-visible) { outline: 2px solid #0f766e; outline-offset: -2px; }
    .bc-check { display: inline-flex; align-items: center; gap: 8px; font-size: 13.5px; cursor: pointer; }
    .bc-list { border: 1px solid #e2e8f0; border-radius: 8px; max-height: 360px; overflow-y: auto; }
    .bc-list-empty { padding: 14px; font-size: 13px; color: #64748b; text-align: center; }
    .bc-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-bottom: 1px solid #f1f5f9; }
    .bc-item:last-child { border-bottom: 0; }
    .bc-item-text { flex: 1; min-width: 0; }
    .bc-item-name { font-size: 13px; font-weight: 600; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .bc-item-sku { font-size: 11.5px; color: #64748b; }
    .bc-item input { width: 58px; padding: 5px 6px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; text-align: center; }
    .bc-item .bc-remove { border: 0; background: none; color: #94a3b8; cursor: pointer; padding: 4px; font-size: 17px; line-height: 1; }
    .bc-item .bc-remove:hover { color: #dc2626; }
    .bc-list-foot { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; font-size: 12.5px; color: #475569; }
    .bc-list-foot button { border: 0; background: none; color: #b91c1c; font: inherit; font-weight: 600; cursor: pointer; padding: 0; }
    .bc-list-foot button[hidden] { display: none; }

    .bc-preview-card { background: #e2e8f0; border-radius: 12px; padding: 18px; }
    .bc-preview-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 10px; font-size: 13px; color: #334155; }
    .bc-preview-head strong { font-size: 14px; }
    /* One A4 page's width on screen, so the preview is the printout. */
    .bc-page { background: #fff; width: 100%; max-width: 210mm; margin: 0 auto; padding: 8mm; box-shadow: 0 2px 10px rgba(15, 23, 42, .12); min-height: 120px; }
    .bc-empty { text-align: center; color: #64748b; font-size: 13.5px; padding: 40px 10px; }

    /* The sheet itself -- sizes in mm so the paper matches the preview. */
    .bc-sheet { display: grid; grid-template-columns: repeat(var(--bc-cols, 3), 1fr); gap: 3mm; }
    .bc-sheet.size-small { --bc-cols: 5; }
    .bc-sheet.size-medium { --bc-cols: 3; }
    .bc-sheet.size-large { --bc-cols: 2; }
    .bc-tag { border: 1px dashed #cbd5e1; border-radius: 1.5mm; padding: 2mm 2.5mm; text-align: center; break-inside: avoid; page-break-inside: avoid; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 1mm; color: #000; background: #fff; }
    .bc-tag-name { font: 700 8.5pt/1.2 Arial, Helvetica, sans-serif; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; word-break: break-word; }
    .size-small .bc-tag-name { font-size: 6.5pt; -webkit-line-clamp: 1; }
    .size-large .bc-tag-name { font-size: 11pt; }
    .bc-tag svg { display: block; width: 100%; height: auto; max-width: 75mm; }
    .bc-tag-price { font: 700 9pt Arial, Helvetica, sans-serif; }
    .size-small .bc-tag-price { font-size: 7pt; }
    .size-large .bc-tag-price { font-size: 12pt; }
    .bc-tag-error { font-size: 8pt; color: #b91c1c; }

    #bcPrintRoot { display: none; }
    @media print {
        @page { size: A4; margin: 8mm; }
        html, body { background: #fff !important; margin: 0 !important; padding: 0 !important; height: auto !important; overflow: visible !important; }
        body > *:not(#bcPrintRoot) { display: none !important; }
        #bcPrintRoot { display: block !important; }
        /* Cut lines stay faint on paper. */
        #bcPrintRoot .bc-tag { border-color: #d4d4d8; }
    }
</style>

<div class="page-head">
    <span class="form-chip" aria-hidden="true"><i class="ti ti-barcode"></i></span>
    <div class="page-head-text">
        <h3>Print Barcodes</h3>
        <p>Choose products and how many labels of each, then print. The labels scan with the camera or a scanner gun at the till.</p>
    </div>
    <div class="page-head-actions">
        <button type="button" class="btn btn-primary btn-lg" id="bcPrint" disabled>
            <i class="ti ti-printer" aria-hidden="true"></i> Print labels
        </button>
    </div>
</div>

<div class="bc-layout">
    <div class="form-card bc-controls">
        <div class="bc-block">
            <label for="bcSearch">Add a product</label>
            <div class="bc-search">
                <input type="text" id="bcSearch" placeholder="Type a name or SKU, or scan a barcode" autocomplete="off"
                       role="combobox" aria-expanded="false" aria-controls="bcSuggest" aria-autocomplete="list">
                <div class="bc-suggest" id="bcSuggest" role="listbox" hidden></div>
            </div>
        </div>

        <div class="bc-block">
            <label for="bcCategory">Or a whole category</label>
            <div class="bc-row-inline">
                <select id="bcCategory">
                    <option value="">Choose a category</option>
                    @foreach($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }} ({{ number_format($category->products_count) }})</option>
                    @endforeach
                </select>
                <button type="button" class="btn btn-secondary" id="bcAddCategory">Add all</button>
            </div>
            <div class="bc-hint">Up to {{ number_format($maxProducts) }} products at a time.</div>
        </div>

        <div class="bc-block">
            <span class="bc-label" id="bcSizeLabel">Label size</span>
            <div class="bc-sizes" role="radiogroup" aria-labelledby="bcSizeLabel">
                <label><input type="radio" name="bcSize" value="small"> Small</label>
                <label><input type="radio" name="bcSize" value="medium" checked> Medium</label>
                <label><input type="radio" name="bcSize" value="large"> Large</label>
            </div>
            <div class="bc-hint">Small fits 5 across an A4 page, Medium 3, Large 2.</div>
        </div>

        <div class="bc-block">
            <label class="bc-check"><input type="checkbox" id="bcShowPrice"> Show the price on each label</label>
        </div>

        <div class="bc-block">
            <span class="bc-label">Products on the sheet</span>
            <div class="bc-list" id="bcList"></div>
            <div class="bc-list-foot">
                <span id="bcTotal">0 labels</span>
                <button type="button" id="bcClear" hidden>Clear all</button>
            </div>
        </div>
    </div>

    <div class="bc-preview-card">
        <div class="bc-preview-head">
            <strong>Preview</strong>
            <span>A4 paper. Dashed lines are for cutting.</span>
        </div>
        <div class="bc-page">
            <div id="bcSheet" class="bc-sheet size-medium"></div>
            <div class="bc-empty" id="bcEmpty">Add products on the left to see their labels here.</div>
        </div>
    </div>
</div>

@php
    // Built here, not inline: @json splits its argument on commas, so an
    // array literal with a route() call inside it does not compile.
    $bcSeed = [
        'preselected' => $preselected,
        'suggestUrl' => route('suggest.products', [], false),
        'categoryUrl' => route('barcodes.products', [], false),
        'maxProducts' => $maxProducts,
    ];
@endphp
<script type="application/json" id="bcSeed">@json($bcSeed)</script>
<script>
(function () {
    const seed = JSON.parse(document.getElementById('bcSeed').textContent);
    const STORE_KEY = 'remedi.barcodeSheet';
    const MAX_COPIES = 100;
    const MAX_LABELS = 1000;

    const els = {
        search: document.getElementById('bcSearch'),
        suggest: document.getElementById('bcSuggest'),
        category: document.getElementById('bcCategory'),
        addCategory: document.getElementById('bcAddCategory'),
        showPrice: document.getElementById('bcShowPrice'),
        list: document.getElementById('bcList'),
        total: document.getElementById('bcTotal'),
        clear: document.getElementById('bcClear'),
        sheet: document.getElementById('bcSheet'),
        empty: document.getElementById('bcEmpty'),
        print: document.getElementById('bcPrint'),
    };

    // [{id, name, sku, price, copies}]
    let items = [];
    let size = 'medium';

    function load() {
        try {
            const saved = JSON.parse(localStorage.getItem(STORE_KEY) || 'null');
            if (saved && Array.isArray(saved.items)) {
                items = saved.items.filter(function (i) { return i && i.id && i.sku; });
                if (['small', 'medium', 'large'].indexOf(saved.size) !== -1) size = saved.size;
                els.showPrice.checked = !!saved.showPrice;
            }
        } catch (e) { /* no storage: start empty */ }
    }

    function save() {
        try {
            localStorage.setItem(STORE_KEY, JSON.stringify({ items: items, size: size, showPrice: els.showPrice.checked }));
        } catch (e) { /* storage blocked: the sheet just won't survive a reload */ }
    }

    function peso(n) {
        return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function totalLabels() {
        return items.reduce(function (sum, i) { return sum + i.copies; }, 0);
    }

    function add(product, copies) {
        const existing = items.find(function (i) { return i.id === product.id; });
        if (existing) {
            existing.copies = Math.min(MAX_COPIES, existing.copies + (copies || 1));
        } else {
            if (items.length >= seed.maxProducts) return false;
            items.push({ id: product.id, name: product.name, sku: String(product.sku), price: product.price, copies: copies || 1 });
        }
        return true;
    }

    function renderList() {
        els.list.innerHTML = '';
        if (!items.length) {
            const p = document.createElement('div');
            p.className = 'bc-list-empty';
            p.textContent = 'Nothing yet. Search above, or add a category.';
            els.list.appendChild(p);
        }
        items.forEach(function (item) {
            const row = document.createElement('div');
            row.className = 'bc-item';

            const text = document.createElement('div');
            text.className = 'bc-item-text';
            const name = document.createElement('div');
            name.className = 'bc-item-name';
            name.textContent = item.name;
            name.title = item.name;
            const sku = document.createElement('div');
            sku.className = 'bc-item-sku';
            sku.textContent = 'SKU ' + item.sku;
            text.appendChild(name);
            text.appendChild(sku);

            const copies = document.createElement('input');
            copies.type = 'number';
            copies.min = '1';
            copies.max = String(MAX_COPIES);
            copies.value = String(item.copies);
            copies.setAttribute('aria-label', 'Labels of ' + item.name);
            copies.title = 'How many labels';
            copies.addEventListener('change', function () {
                const n = Math.max(1, Math.min(MAX_COPIES, parseInt(copies.value, 10) || 1));
                copies.value = String(n);
                item.copies = n;
                update(false);
            });

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'bc-remove';
            remove.setAttribute('aria-label', 'Remove ' + item.name);
            remove.innerHTML = '<i class="ti ti-x" aria-hidden="true"></i>';
            remove.addEventListener('click', function () {
                items = items.filter(function (i) { return i !== item; });
                update(true);
            });

            row.appendChild(text);
            row.appendChild(copies);
            row.appendChild(remove);
            els.list.appendChild(row);
        });
    }

    function drawLabel(item, showPrice) {
        const tag = document.createElement('div');
        tag.className = 'bc-tag';

        const name = document.createElement('div');
        name.className = 'bc-tag-name';
        name.textContent = item.name;
        tag.appendChild(name);

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        try {
            // CODE 128 of the SKU exactly as stored. margin 10 = the quiet
            // zone a reader needs either side; digits printed underneath.
            JsBarcode(svg, item.sku, {
                format: 'CODE128', width: 2, height: 60, margin: 10,
                displayValue: true, fontSize: 16, textMargin: 2, font: 'Arial',
            });
            // Scale with the label rather than at a fixed pixel size.
            const w = svg.getAttribute('width'), h = svg.getAttribute('height');
            svg.setAttribute('viewBox', '0 0 ' + parseFloat(w) + ' ' + parseFloat(h));
            svg.removeAttribute('width');
            svg.removeAttribute('height');
            tag.appendChild(svg);
        } catch (e) {
            const err = document.createElement('div');
            err.className = 'bc-tag-error';
            err.textContent = 'Cannot draw a barcode for SKU "' + item.sku + '"';
            tag.appendChild(err);
        }

        if (showPrice) {
            const price = document.createElement('div');
            price.className = 'bc-tag-price';
            price.textContent = peso(item.price);
            tag.appendChild(price);
        }
        return tag;
    }

    function renderSheet() {
        els.sheet.className = 'bc-sheet size-' + size;
        els.sheet.innerHTML = '';
        const showPrice = els.showPrice.checked;
        let drawn = 0;
        items.forEach(function (item) {
            // Draw one, clone the rest: the same SVG a hundred times over
            // costs a hundred barcode encodes otherwise.
            const first = drawLabel(item, showPrice);
            for (let i = 0; i < item.copies && drawn < MAX_LABELS; i++, drawn++) {
                els.sheet.appendChild(i === 0 ? first : first.cloneNode(true));
            }
        });
        els.empty.hidden = drawn > 0;
        els.print.disabled = drawn === 0;
    }

    function update(listToo) {
        if (listToo) renderList();
        const n = totalLabels();
        els.total.textContent = n.toLocaleString() + (n === 1 ? ' label' : ' labels')
            + (n > MAX_LABELS ? ' (only the first ' + MAX_LABELS.toLocaleString() + ' are printed)' : '');
        els.clear.hidden = !items.length;
        renderSheet();
        save();
    }

    /* ---- Search (the same suggest endpoint the list pages use) ---- */
    let suggestTimer = null;
    let results = [];
    let active = -1;
    let lastQuery = '';

    function closeSuggest() {
        els.suggest.hidden = true;
        els.search.setAttribute('aria-expanded', 'false');
        active = -1;
    }

    function paintSuggest() {
        els.suggest.innerHTML = '';
        if (!results.length) {
            const p = document.createElement('div');
            p.className = 'bc-s-empty';
            p.textContent = 'No product matches "' + lastQuery + '".';
            els.suggest.appendChild(p);
        }
        results.forEach(function (r, idx) {
            const b = document.createElement('button');
            b.type = 'button';
            b.setAttribute('role', 'option');
            if (idx === active) b.classList.add('is-active');
            const n = document.createElement('span');
            n.className = 'bc-s-name';
            n.textContent = r.label;
            const s = document.createElement('span');
            s.className = 'bc-s-sku';
            s.textContent = 'SKU ' + r.sku;
            b.appendChild(n);
            b.appendChild(s);
            // mousedown, not click: the search box's blur would close the
            // list before a click landed.
            b.addEventListener('mousedown', function (e) { e.preventDefault(); pick(idx); });
            els.suggest.appendChild(b);
        });
        els.suggest.hidden = false;
        els.search.setAttribute('aria-expanded', 'true');
    }

    function pick(idx) {
        const r = results[idx];
        if (!r) return;
        add({ id: r.id, name: r.label, sku: r.sku, price: r.price }, 1);
        els.search.value = '';
        results = [];
        closeSuggest();
        update(true);
        els.search.focus();
    }

    function query(q) {
        lastQuery = q;
        return fetch(seed.suggestUrl + '?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(function (r) { return r.ok ? r.json() : { results: [] }; })
          .then(function (data) {
              if (q !== els.search.value.trim()) return; // an older answer
              results = (data && data.results) || [];
              active = results.length ? 0 : -1;
              paintSuggest();
          })
          .catch(function () { /* offline: leave the box as it is */ });
    }

    els.search.addEventListener('input', function () {
        clearTimeout(suggestTimer);
        const q = els.search.value.trim();
        if (q.length < 2) { results = []; closeSuggest(); return; }
        suggestTimer = setTimeout(function () { query(q); }, 200);
    });

    els.search.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!results.length) return;
            e.preventDefault();
            active = (active + (e.key === 'ArrowDown' ? 1 : -1) + results.length) % results.length;
            paintSuggest();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            // A scanner gun types the SKU and presses Enter at once, before
            // the debounced search has run -- search now, then take the
            // exact SKU match (or the first result).
            const q = els.search.value.trim();
            if (q.length < 2) return;
            clearTimeout(suggestTimer);
            const takeBest = function () {
                const exact = results.findIndex(function (r) { return r.sku === q; });
                pick(exact !== -1 ? exact : (active !== -1 ? active : 0));
            };
            if (q === lastQuery && results.length) takeBest(); else query(q).then(takeBest);
        } else if (e.key === 'Escape') {
            closeSuggest();
        }
    });

    els.search.addEventListener('blur', function () { setTimeout(closeSuggest, 100); });

    /* ---- Whole category ---- */
    els.addCategory.addEventListener('click', function () {
        const id = els.category.value;
        if (!id) { els.category.focus(); return; }
        els.addCategory.disabled = true;
        fetch(seed.categoryUrl + '?category_id=' + encodeURIComponent(id), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        }).then(function (r) { return r.ok ? r.json() : { products: [] }; })
          .then(function (data) {
              let skipped = 0;
              // Only what is missing: a product already on the sheet keeps
              // the count it was given rather than gaining one.
              ((data && data.products) || []).forEach(function (p) {
                  if (items.some(function (i) { return i.id === p.id; })) return;
                  if (!add(p, 1)) skipped++;
              });
              update(true);
              if (skipped && window.REMEDI && REMEDI.showMessage) {
                  REMEDI.showMessage({
                      title: 'Sheet is full',
                      body: skipped + ' products were not added: a sheet holds up to ' + seed.maxProducts + ' products.',
                      icon: 'ti-alert-circle', tone: 'neutral',
                  });
              }
          })
          .catch(function () {})
          .then(function () { els.addCategory.disabled = false; });
    });

    /* ---- Options ---- */
    document.querySelectorAll('input[name="bcSize"]').forEach(function (radio) {
        radio.addEventListener('change', function () { if (radio.checked) { size = radio.value; update(false); } });
    });
    els.showPrice.addEventListener('change', function () { update(false); });
    els.clear.addEventListener('click', function () { items = []; update(true); els.search.focus(); });

    /* ---- Print: only the sheet ----
       The sheet is copied into a direct child of <body> for the length of the
       print, and print CSS hides every other child -- so the sidebar, header
       and controls never reach the paper, whatever the layout nests them in. */
    function buildPrintRoot() {
        let root = document.getElementById('bcPrintRoot');
        if (!root) {
            root = document.createElement('div');
            root.id = 'bcPrintRoot';
            document.body.appendChild(root);
        }
        root.innerHTML = '';
        root.appendChild(els.sheet.cloneNode(true)).removeAttribute('id');
    }
    window.addEventListener('beforeprint', buildPrintRoot);
    window.addEventListener('afterprint', function () {
        const root = document.getElementById('bcPrintRoot');
        if (root) root.innerHTML = '';
    });
    els.print.addEventListener('click', function () {
        if (els.print.disabled) return;
        buildPrintRoot();
        window.print();
    });

    /* ---- Start ---- */
    load();
    (seed.preselected || []).forEach(function (p) {
        if (!items.some(function (i) { return i.id === p.id; })) add(p, 1);
    });
    document.querySelectorAll('input[name="bcSize"]').forEach(function (radio) { radio.checked = radio.value === size; });
    if (typeof JsBarcode === 'undefined') {
        els.empty.textContent = 'The barcode drawer could not load. Check the internet connection, then reload.';
    }
    update(true);
})();
</script>
@endsection
