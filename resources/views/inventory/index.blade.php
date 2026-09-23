@extends('layouts.app')

@section('title', 'Inventory Monitoring')

@section('content')

<style>
    /* The notification bell deep-links here as ?search=<sku>#product-row-<id>,
       so the row it targets is highlighted — arriving at a list with no idea
       which row you were sent to is barely better than arriving at the filter.

       `> td`, not the `tr`: a background on a <tr> in these border-collapse
       tables does not render at all, the same reason row hover is written
       cell-level. The tint fades out on its own so it reads as "this one" on
       arrival without permanently recolouring the row. */
    .remedi-table tbody tr:target > td {
        background: #fef9c3;
        animation: remedi-row-target 2.4s ease-out forwards;
    }

    @keyframes remedi-row-target {
        0%, 45% { background: #fef9c3; }
        100% { background: transparent; }
    }

    @media (prefers-reduced-motion: reduce) {
        .remedi-table tbody tr:target > td { animation: none; }
    }

    /* Inventory filter pills — colors mirror the exact status badges shown
       in the results table (badge-danger, badge-warning, etc.) so the
       filter you pick visually matches the rows it produces. Solid when
       active, tinted (same hues as the badges) when not. */
    .filter-btn {
        border-radius: 999px;
        padding: 8px 16px;
        font-size: 13.5px;
        font-weight: 600;
        border: 1.5px solid transparent;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        /* Glow colour as an rgb triplet so one rule below can tint the halo to
           whichever status the tab filters for. Each .active rule overrides it. */
        --glow: 148 163 184;
        transition: box-shadow .18s ease, transform .18s ease;
    }

    /* ── Selected tab glows ──
       These tabs are AJAX (see the handler further down), so clicking one
       changes the rows without a page load. The solid fill alone was a quiet
       signal for something that just replaced the whole table; the halo makes
       the tab you picked read as the thing driving what you are looking at.
       The pop runs once each time .active lands on a tab, which is exactly the
       moment of the click. */
    .filter-btn.active {
        transform: translateY(-1px);
        box-shadow: 0 0 0 3px rgb(var(--glow) / .28),
                    0 8px 20px -6px rgb(var(--glow) / .55);
        animation: filter-glow-pop .45s ease-out;
    }

    .filter-btn:not(.active):hover {
        box-shadow: 0 0 0 2px rgb(var(--glow) / .22);
    }

    @keyframes filter-glow-pop {
        0% {
            box-shadow: 0 0 0 0 rgb(var(--glow) / .6),
                        0 8px 20px -6px rgb(var(--glow) / .55);
        }
        70% {
            box-shadow: 0 0 0 10px rgb(var(--glow) / 0),
                        0 8px 20px -6px rgb(var(--glow) / .55);
        }
        100% {
            box-shadow: 0 0 0 3px rgb(var(--glow) / .28),
                        0 8px 20px -6px rgb(var(--glow) / .55);
        }
    }

    /* A halo that pulses is decoration, not information -- the fill already
       says which tab is selected -- so it is the first thing to drop. */
    @media (prefers-reduced-motion: reduce) {
        .filter-btn { transition: none; }
        .filter-btn.active { animation: none; transform: none; }
    }

    .filter-all             { background: #e2e8f0; color: #334155; border-color: #e2e8f0; }
    .filter-all.active             { background: #334155; color: #fff; border-color: #334155; --glow: 51 65 85; }

    {{-- Chips mirror the badge families exactly, so the filter you click and
         the badge you see in the Status column are the same colour.
         Low stock = amber-free yellow, expiry = rose/pink, returns = amber. --}}

    /* Each tab wears the same colour as the badge it filters for, so the tab
       and the Status column agree. Legend: low stock yellow, expiring orange,
       expired red, need-to-return blue, returned green. */

    /* Low Stock — yellow */
    .filter-low_stock        { background: #fef9c3; color: #854d0e; border-color: #fef9c3; }
    .filter-low_stock.active { background: #ca8a04; color: #fff; border-color: #ca8a04; --glow: 202 138 4; }

    /* Expiring Soon — orange (.badge-expiry-soon) */
    .filter-expiring         { background: #ffedd5; color: #9a3412; border-color: #ffedd5; }
    .filter-expiring.active  { background: #ea580c; color: #fff; border-color: #ea580c; --glow: 234 88 12; }

    /* Expired — red (.badge-expiry-expired) */
    .filter-expired          { background: #fee2e2; color: #991b1b; border-color: #fee2e2; }
    .filter-expired.active   { background: #dc2626; color: #fff; border-color: #dc2626; --glow: 220 38 38; }

    /* Need to Return — blue (.badge-return-due) */
    .filter-need_to_return        { background: #dbeafe; color: #1e40af; border-color: #dbeafe; }
    .filter-need_to_return.active { background: #1d4ed8; color: #fff; border-color: #1d4ed8; --glow: 29 78 216; }

    /* Returned — green (.badge-return-done) */
    .filter-returned        { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    .filter-returned.active { background: #16a34a; color: #fff; border-color: #16a34a; --glow: 22 163 74; }

    /* Fail to Return — deep red (.badge-return-late), outside the legend */
    .filter-fail_to_return        { background: #fecdd3; color: #881337; border-color: #fecdd3; }
    .filter-fail_to_return.active { background: #9f1239; color: #fff; border-color: #9f1239; --glow: 159 18 57; }

    .filter-btn:hover { filter: brightness(0.96); }

    /* Category banner — shown when the page was reached via one of the
       category sub-buttons under "Inventory" in the sidebar. */
    .category-banner {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        color: #3730a3;
        border-radius: 8px;
        padding: 10px 16px;
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 16px;
    }

    .category-banner a {
        font-weight: 500;
        color: #4338ca;
        text-decoration: none;
        font-size: 13px;
        margin-left: auto;
    }

    .category-banner a:hover { text-decoration: underline; }
</style>

@if($categoryId && $categoryName)
    <div class="category-banner">
        <i class="ti ti-filter" aria-hidden="true"></i>
        Showing category: {{ $categoryName }}
        <a href="{{ route('inventory.index', array_filter(['filter' => $filter, 'search' => request('search')])) }}">Clear category &times;</a>
    </div>
@endif

{{-- The Expiring tab defaults to a 90-day planning horizon; the dashboard and
     bell alerts link here with days=30, their "pull these now" window. Say
     which one is on screen, or arriving from an alert that promised 28 and
     landing on a list is unexplained. --}}
@if($filter === 'expiring' && $expiringDays === \App\Models\ProductBatch::EXPIRY_SOON_DAYS)
    <div class="category-banner">
        <i class="ti ti-clock-exclamation" aria-hidden="true"></i>
        Showing batches expiring within {{ $expiringDays }} days
        <a href="{{ route('inventory.index', array_filter(['filter' => 'expiring', 'search' => request('search'), 'category_id' => $categoryId])) }}">Widen to {{ \App\Models\ProductBatch::EXPIRY_WATCH_DAYS }} days &rarr;</a>
    </div>
@endif

{{-- Barcode scanner -- unhidden 2026-09-22 at the user's request, the same
     treatment pos/index got. `barcode-input`, `barcode-status`, the keydown
     listener and focusEntryField() below all already handle the field being
     visible (see focusEntryField()'s offsetParent check just below), so
     nothing else needed to change.

     As of 2026-09-23, a successful scan by an ADMIN navigates straight to
     that product's Manage Product page (products.edit) rather than opening
     an inline Quick Restock card -- the user's own request, and it also
     drops a second, narrower copy of "add a batch" in favour of the one
     already on that page (the Add New Batch card, same `addBatch` endpoint).
     The inline card and its `openQuickRestock`/`closeQuickRestock` functions
     are gone with it, since nothing else ever called them. A STAFF scan still
     searches the list in place -- role:admin gates products.edit, so sending
     a staff scan there would just trade one dead end for a 403; searching is
     the useful thing a non-admin scan can still do. `autofocus` stays
     dropped: it was removed for the hidden state, and this field is one of
     several entry points on a busy page, so stealing focus on every load
     would be its own kind of surprise. --}}
<div class="card" style="margin-bottom:16px; border:2px solid #4f46e5;">
    <label style="font-weight:600; font-size:.85rem;">Scan Barcode</label>
    <input
        type="text"
        id="barcode-input"
        placeholder="Click here, then scan a product's barcode to look it up..."
        autocomplete="off"
        style="width:100%; padding:12px; border:1px solid #d1d5db; border-radius:6px; font-size:1.1rem; margin-top:6px;">
    {{-- The camera scans too, with no preview shown -- hold a barcode up to it
         and it opens Manage Product like a gun scan. Renders nothing here; see
         the partial. --}}
    @include('partials._barcode-camera')
    <div id="barcode-status" style="margin-top:6px; font-size:.85rem; min-height:1.2em;"></div>
</div>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:8px;">
    {{-- No Search button: the box refreshes the list on a debounced keystroke
         (see "Real-time product search" below), so the button only ever
         re-ran a search that had already run -- same reasoning as
         products/index.blade.php. The form stays a real GET form, and stays
         working without JS: the text input is the only field that blocks
         implicit submission (the two hidden inputs don't), so Enter still
         submits it with no button present. --}}
    <form method="GET" id="search-form" style="display:flex; gap:8px; flex:1; min-width:280px;">
        <input type="text" name="search" id="search-input"
            data-suggest-url="{{ route('suggest.products') }}" placeholder="Search product by name or SKU..." value="{{ request('search') }}" style="flex:1; min-width:0; padding:9px 12px; border:1px solid #d1d5db; border-radius:7px;" autocomplete="off">
        <input type="hidden" name="filter" id="filter-input" value="{{ $filter }}">
        <input type="hidden" name="category_id" id="category-input" value="{{ $categoryId }}">
    </form>

    @php
        $filterParams = array_filter(['search' => request('search'), 'category_id' => $categoryId]);
    @endphp
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a href="{{ route('inventory.index', ['filter' => 'all'] + $filterParams) }}" data-filter="all" class="filter-btn filter-all {{ $filter === 'all' ? 'active' : '' }}">All</a>
        <a href="{{ route('inventory.index', ['filter' => 'low_stock'] + $filterParams) }}" data-filter="low_stock" class="filter-btn filter-low_stock {{ $filter === 'low_stock' ? 'active' : '' }}">Low Stock</a>
        <a href="{{ route('inventory.index', ['filter' => 'expiring'] + $filterParams) }}" data-filter="expiring" class="filter-btn filter-expiring {{ $filter === 'expiring' ? 'active' : '' }}">Expiring Soon</a>
        <a href="{{ route('inventory.index', ['filter' => 'expired'] + $filterParams) }}" data-filter="expired" class="filter-btn filter-expired {{ $filter === 'expired' ? 'active' : '' }}">Expired</a>
        <a href="{{ route('inventory.index', ['filter' => 'need_to_return'] + $filterParams) }}" data-filter="need_to_return" class="filter-btn filter-need_to_return {{ $filter === 'need_to_return' ? 'active' : '' }}">Need to Return</a>
        <a href="{{ route('inventory.index', ['filter' => 'fail_to_return'] + $filterParams) }}" data-filter="fail_to_return" class="filter-btn filter-fail_to_return {{ $filter === 'fail_to_return' ? 'active' : '' }}">Fail to Return</a>
        <a href="{{ route('inventory.index', ['filter' => 'returned'] + $filterParams) }}" data-filter="returned" class="filter-btn filter-returned {{ $filter === 'returned' ? 'active' : '' }}">Returned</a>
    </div>
</div>

<div class="card">
    <div id="results-wrapper">
        @include('inventory._rows')
        <div style="margin-top:16px;" id="pagination-wrapper">{{ $products->links() }}</div>
    </div>
</div>

<script>
    // ===== Real-time product search (preserves current filter) =====
    const searchInput = document.getElementById('search-input');
    const filterInput = document.getElementById('filter-input');
    const categoryInput = document.getElementById('category-input');
    const resultsWrapper = document.getElementById('results-wrapper');
    const inventoryBaseUrl = "{{ route('inventory.index') }}";

    let searchDebounce;
    let searchController;

    function runInventorySearch(pushState = true) {
        if (searchController) searchController.abort();
        searchController = new AbortController();

        const url = new URL(inventoryBaseUrl);
        const term = searchInput.value.trim();
        if (term) url.searchParams.set('search', term);
        url.searchParams.set('filter', filterInput.value);
        if (categoryInput.value) url.searchParams.set('category_id', categoryInput.value);

        // Swap the stale rows for a skeleton so a search/filter reads as

        // 'working' instead of leaving the previous results on screen.

        // Read before the skeleton replaces the rows, not after: resolve the
        // scroller once the page has shrunk and REMEDI.scroller() can answer
        // with a different element entirely. See REMEDI.holdScroll.
        const restoreScroll = REMEDI.holdScroll();

        REMEDI.showListSkeleton(resultsWrapper, { rows: 6 });


        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: searchController.signal,
        })
            .then(res => res.json())
            .then(data => {
                REMEDI.clearListSkeleton(resultsWrapper);
                resultsWrapper.innerHTML = data.html + `<div style="margin-top:16px;" id="pagination-wrapper">${data.pagination}</div>`;
                restoreScroll();

                if (pushState) window.history.pushState({}, '', url);
            })
            .catch(err => {
                if (err.name !== 'AbortError') console.error(err);
            });
    }

    searchInput.addEventListener('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => runInventorySearch(), 300);
    });

    // Choosing a suggestion runs the same search the field would.
    searchInput.addEventListener('suggest:live', () => { runProductSearch(); });

    document.getElementById('search-form').addEventListener('submit', (e) => {
        e.preventDefault();
        runInventorySearch();
    });

    // ===== Filter tabs, without a page load =====
    // These are real <a href> links so they keep working without JS, are
    // shareable, and open in a new tab on middle-click. But a plain click used
    // to reload the whole document: the sidebar, KPI cards and header were all
    // re-rendered for a change that only affects the rows, and the browser
    // dropped the scroll position on the floor -- click a tab half way down the
    // list and you were thrown back to the top of the page.
    //
    // Same fetch the search box already uses, so the rows swap in place and the
    // scroll position simply never moves. Modified and middle clicks fall
    // through to the browser untouched, same guards as the sidebar handler in
    // layouts/app.blade.php.
    document.querySelectorAll('.filter-btn[data-filter]').forEach((tab) => {
        tab.addEventListener('click', (e) => {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }

            e.preventDefault();

            if (tab.classList.contains('active')) {
                return; // already showing this filter
            }

            document.querySelectorAll('.filter-btn[data-filter]')
                .forEach((t) => t.classList.toggle('active', t === tab));

            filterInput.value = tab.dataset.filter;
            runInventorySearch();
        });
    });

    window.addEventListener('popstate', () => {
        const params = new URLSearchParams(window.location.search);
        searchInput.value = params.get('search') || '';
        filterInput.value = params.get('filter') || 'all';
        categoryInput.value = params.get('category_id') || '';
        // Back/forward must move the highlight too, or the tabs keep insisting
        // you are on the filter you just navigated away from.
        document.querySelectorAll('.filter-btn[data-filter]')
            .forEach((t) => t.classList.toggle('active', t.dataset.filter === filterInput.value));
        runInventorySearch(false);
    });

    // ===== Barcode Scanner Support =====
    const barcodeInput = document.getElementById('barcode-input');
    const barcodeStatus = document.getElementById('barcode-status');
    const isAdmin = @json(auth()->user()->isAdmin());
    // Only reached for an admin scan -- see the fetch handler below.
    const productEditUrlBase = "{{ url('/products') }}";

    /* Keep the scanner armed WITHOUT dragging the page around.
       This refocuses a field that sits at the top of the page, and a plain
       .focus() scrolls it into view -- so every click on a card, a row or a
       label threw the reader back to the top. preventScroll keeps the caret
       where the scanner needs it and leaves the viewport alone.

       It also bails out while a selection is live: refocusing collapses the
       selection, so highlighting a product name to copy it both wiped the
       highlight and jumped the page. */
    /* Where the caret belongs: the scanner while it is visible, the product
       search box when it is not. A hidden input cannot take focus, so without
       this the refocus calls would silently leave the caret nowhere after every
       click -- which is exactly the trap pos/index documents. */
    function focusEntryField() {
        const target = (barcodeInput && barcodeInput.offsetParent !== null)
            ? barcodeInput
            : document.getElementById('search-input');

        if (target) target.focus({ preventScroll: true });
    }

    document.addEventListener('click', function (e) {
        // With the scanner hidden there is no field that needs re-arming, and
        // stealing focus back to the search box on every click would fight the
        // person using the page.
        if (!barcodeInput || barcodeInput.offsetParent === null) return;

        const selection = window.getSelection();
        if (selection && !selection.isCollapsed) return;

        const tag = e.target.tagName;
        if (tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'BUTTON' && tag !== 'A') {
            focusEntryField();
        }
    });
    focusEntryField();

    /* One lookup path for BOTH ways a code arrives: a scanner gun typing into
       the field and pressing Enter, and a camera decode from
       partials/_barcode-camera. A second copy here is how the two would drift
       into meaning different things. */
    window.handleScannedCode = function (code) {
        {
            if (!code) return;

            barcodeStatus.textContent = 'Looking up ' + code + '...';
            barcodeStatus.style.color = '#6b7280';

            fetch(`{{ route('pos.lookup') }}?sku=${encodeURIComponent(code)}`)
                .then(function (response) {
                    if (!response.ok) throw new Error('not_found');
                    return response.json();
                })
                .then(function (data) {
                    if (data.found) {
                        if (isAdmin) {
                            window.location.href = `${productEditUrlBase}/${data.id}/edit`;
                            return;
                        }
                        barcodeStatus.textContent = '\u2705 Found: ' + data.name;
                        barcodeStatus.style.color = '#16a34a';
                        searchInput.value = data.name;
                        runInventorySearch();
                    } else {
                        barcodeStatus.textContent = '\u274C Product not found for code: ' + code;
                        barcodeStatus.style.color = '#dc2626';
                    }
                })
                .catch(function () {
                    barcodeStatus.textContent = '\u274C Product not found for code: ' + code;
                    barcodeStatus.style.color = '#dc2626';
                });
        }
    };

    barcodeInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const code = barcodeInput.value.trim();
            barcodeInput.value = '';
            window.handleScannedCode(code);
        }
    });

</script>
@endsection
