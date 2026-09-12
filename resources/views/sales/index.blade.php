@extends('layouts.app')

@section('title', 'Sales history')

@section('content')

{{-- Today's summary --}}
<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 24px;">
    <div class="card">
        <p style="margin: 0 0 6px; font-size: 13px; color: #64748b;">Total sales today</p>
        <p style="margin: 0; font-size: 26px; font-weight: 500; color: #1e293b;">₱{{ number_format($todayTotal, 2) }}</p>
    </div>
    <div class="card">
        <p style="margin: 0 0 6px; font-size: 13px; color: #64748b;">Transactions today</p>
        <p style="margin: 0; font-size: 26px; font-weight: 500; color: #1e293b;">{{ $todayCount }}</p>
    </div>
</div>

{{-- Filters --}}
<form method="GET" id="search-form" style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;">
    <div style="position: relative;">
        <i class="ti ti-search" aria-hidden="true"
           style="position: absolute; left: 11px; top: 50%; transform: translateY(-50%); font-size: 16px; color: #94a3b8;"></i>
        <input type="text" name="search" id="search-input" placeholder="Transaction no."
               value="{{ request('search') }}" autocomplete="off"
               style="padding: 9px 12px 9px 34px; border: 0.5px solid #d1d5db; border-radius: 7px; font-size: 15px; font-family: inherit; width: 200px;" data-suggest-url="{{ route('suggest.sales') }}">
    </div>

    {{-- max=today: a sale cannot have been rung up on a day that has not
         happened, so those dates are not selectable. Without it the picker
         offered future days that could only ever return an empty list. --}}
    <input type="date" name="start_date" id="start-date-input" value="{{ $startDate ?? request('start_date') }}"
           max="{{ now()->toDateString() }}"
           style="padding: 9px 12px; border: 0.5px solid #d1d5db; border-radius: 7px; font-size: 15px; font-family: inherit;">

    <input type="date" name="end_date" id="end-date-input" value="{{ $endDate ?? request('end_date') }}"
           max="{{ now()->toDateString() }}"
           style="padding: 9px 12px; border: 0.5px solid #d1d5db; border-radius: 7px; font-size: 15px; font-family: inherit;">

    {{-- No Apply button: the search box debounces and both dates re-run on
         change (see the script below), so it only ever re-submitted a filter
         that had already applied -- same redundancy already removed from
         Products/Inventory's search boxes and Users' filter panel.
         Unlike those, this form has THREE fields that block a browser's
         implicit Enter-to-submit (search + two dates -- the spec only grants
         that with exactly one), so a no-JS visitor loses the ability to
         apply a filter at all; accepted as the same tradeoff as the login
         page's skeleton, since real usage here has JS on. --}}
    <a href="{{ route('sales.index') }}" id="reset-link" class="btn btn-secondary">
        <i class="ti ti-refresh" aria-hidden="true"></i> Reset
    </a>
    {{-- The page defaults to today (see SaleController::index); this is the
         explicit way back to the full history. --}}
    <button type="button" id="show-all-link" class="btn btn-secondary">
        <i class="ti ti-list" aria-hidden="true"></i> Show All
    </button>
</form>

{{-- Sales table --}}
<div class="card">
    <div id="results-wrapper">
        @include('sales._rows')
        <div style="margin-top: 18px;" id="pagination-wrapper">{{ $sales->links() }}</div>
    </div>
</div>

<script>
    // ===== Real-time sales history search (search + date filters) =====
    const searchInput = document.getElementById('search-input');
    const startDateInput = document.getElementById('start-date-input');
    const endDateInput = document.getElementById('end-date-input');
    const resultsWrapper = document.getElementById('results-wrapper');
    const resetLink = document.getElementById('reset-link');
    const showAllLink = document.getElementById('show-all-link');
    const salesBaseUrl = "{{ route('sales.index') }}";

    // The page defaults to today (see SaleController::index) unless this is
    // set -- sticky across searches and date changes so typing into the
    // search box while "Show All" is active doesn't silently snap back to
    // today. Restored from the URL below so a reload or back/forward keeps it.
    let showingAll = new URLSearchParams(window.location.search).get('all') === '1';

    let searchDebounce;
    let searchController;

    function runSalesSearch(pushState = true) {
        if (searchController) searchController.abort();
        searchController = new AbortController();

        const url = new URL(salesBaseUrl);
        const term = searchInput.value.trim();
        if (term) url.searchParams.set('search', term);
        if (startDateInput.value) url.searchParams.set('start_date', startDateInput.value);
        if (endDateInput.value) url.searchParams.set('end_date', endDateInput.value);
        if (showingAll) url.searchParams.set('all', '1');

        // Swap the stale rows for a skeleton so a search/filter reads as

        // 'working' instead of leaving the previous results on screen.

        // See REMEDI.holdScroll: read the offset before the rows are gone.
        const restoreScroll = REMEDI.holdScroll();

        REMEDI.showListSkeleton(resultsWrapper, { rows: 6 });


        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: searchController.signal,
        })
            .then(res => res.json())
            .then(data => {
                REMEDI.clearListSkeleton(resultsWrapper);
                resultsWrapper.innerHTML = data.html + `<div style="margin-top:18px;" id="pagination-wrapper">${data.pagination}</div>`;
                restoreScroll();

                // The server puts a reversed date range the right way round,
                // so adopt the range it actually queried. Otherwise the two
                // fields keep showing the reversed pair while the table below
                // them lists a different period, and the page states something
                // untrue about its own contents.
                if (data.range) {
                    if (data.range.start) {
                        startDateInput.value = data.range.start;
                        url.searchParams.set('start_date', data.range.start);
                    }
                    if (data.range.end) {
                        endDateInput.value = data.range.end;
                        url.searchParams.set('end_date', data.range.end);
                    }
                }
                if (pushState) window.history.pushState({}, '', url);
            })
            .catch(err => {
                if (err.name !== 'AbortError') console.error(err);
            });
    }

    // Choosing a suggestion runs the same search the field would.
    searchInput.addEventListener('suggest:live', () => { runSalesSearch(); });

    searchInput.addEventListener('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => runSalesSearch(), 300);
    });

    // Date pickers fire far less often than typing, so no debounce needed
    // — filter as soon as a date is picked or cleared.
    startDateInput.addEventListener('change', () => runSalesSearch());
    endDateInput.addEventListener('change', () => runSalesSearch());

    document.getElementById('search-form').addEventListener('submit', (e) => {
        e.preventDefault();
        runSalesSearch();
    });

    resetLink.addEventListener('click', (e) => {
        e.preventDefault();
        searchInput.value = '';
        startDateInput.value = '';
        endDateInput.value = '';
        showingAll = false;
        runSalesSearch();
    });

    // Show All: the explicit way past the today default. Sticky (see
    // `showingAll` above) so it survives a later search or pagination.
    showAllLink.addEventListener('click', (e) => {
        e.preventDefault();
        searchInput.value = '';
        startDateInput.value = '';
        endDateInput.value = '';
        showingAll = true;
        runSalesSearch();
    });

    // Intercept pagination link clicks so paging doesn't reload the page.
    resultsWrapper.addEventListener('click', (e) => {
        const link = e.target.closest('#pagination-wrapper a');
        if (link && link.href) {
            e.preventDefault();
            fetch(link.href, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            })
                .then(res => res.json())
                .then(data => {
                    resultsWrapper.innerHTML = data.html + `<div style="margin-top:18px;" id="pagination-wrapper">${data.pagination}</div>`;
                    window.history.pushState({}, '', link.href);
                })
                .catch(err => console.error(err));
        }
    });

    window.addEventListener('popstate', () => {
        const params = new URLSearchParams(window.location.search);
        searchInput.value = params.get('search') || '';
        startDateInput.value = params.get('start_date') || '';
        endDateInput.value = params.get('end_date') || '';
        showingAll = params.get('all') === '1';
        runSalesSearch(false);
    });
</script>

@endsection
