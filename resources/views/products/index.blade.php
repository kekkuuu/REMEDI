@extends('layouts.app')

@section('title', 'Product Management')

@section('content')
<style>
    /* Actions column: oval buttons with breathing room between them */
    /* Action buttons sit side by side on one line. The old 16px gap with
       flex-wrap let them drop onto separate lines in a narrow cell, which
       made a two-button row three lines tall. */
    .actions-cell {
        display: inline-flex;
        align-items: center;
        flex-wrap: nowrap;
        gap: 6px;
        white-space: nowrap;
    }

    .actions-cell form { margin: 0; display: inline-flex; }

    .action-btn {
        border-radius: 8px;
        padding: 6px 12px;
        font-size: 13px;
    }
</style>

<div style="display:flex; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:8px;">
    <form id="search-form" method="GET" style="display:flex; gap:8px; flex:1 1 520px; min-width:0;">
        <input
            type="text"
            name="search"
            id="search-input"
            data-suggest-url="{{ route('suggest.products') }}"
            placeholder="Search by name, SKU, or barcode"
            value="{{ request('search') }}"
            style="flex:1; min-width:0; padding:9px 12px; border:1px solid #d1d5db; border-radius:7px;"
            autocomplete="off"
        >
        <select name="category_id" id="category-select" style="padding:6px 10px; border:1px solid #d1d5db; border-radius:6px;">
            <option value="">All Categories</option>
            @foreach($categories as $cat)
                <option value="{{ $cat->id }}" {{ request('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
            @endforeach
        </select>
        {{-- No Filter button: the search box refreshes the list on a debounced
             keystroke and the category select on change, so the button only
             ever re-ran a search that had already run.

             The form element stays, and stays a real GET form. Without JS this
             page still works: it has exactly one field that blocks implicit
             submission (the text input — a <select> does not), so Enter
             submits natively even with no submit button present. --}}
    </form>
    <div>
        <a href="{{ route('categories.index') }}" class="btn btn-secondary">Manage Categories</a>
        <a href="{{ route('products.create') }}" class="btn btn-primary">+ Add Product</a>
    </div>
</div>

<div class="card">
    <div id="results-wrapper">
        @include('products._rows')
        <div style="margin-top:16px;" id="pagination-wrapper">{{ $products->links() }}</div>
    </div>
</div>

<script>
const input = document.getElementById('search-input');
const categorySelect = document.getElementById('category-select');
const wrapper = document.getElementById('results-wrapper');
const baseUrl = "{{ route('products.index') }}";

let debounceTimer;
let currentController;

function runSearch(pushState = true) {
    if (currentController) currentController.abort();
    currentController = new AbortController();

    const url = new URL(baseUrl);
    const term = input.value.trim();
    const categoryId = categorySelect.value;

    if (term) url.searchParams.set('search', term);
    if (categoryId) url.searchParams.set('category_id', categoryId);

    // Swap the stale rows for a skeleton so a search/filter reads as

    // 'working' instead of leaving the previous results on screen.

    REMEDI.showListSkeleton(wrapper, { rows: 6 });


    fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        signal: currentController.signal,
    })
        .then(res => res.json())
        .then(data => {
            REMEDI.clearListSkeleton(wrapper);
            wrapper.innerHTML = data.html + `<div style="margin-top:16px;" id="pagination-wrapper">${data.pagination}</div>`;

            if (pushState) {
                window.history.pushState({}, '', url);
            }
        })
        .catch(err => {
            if (err.name !== 'AbortError') console.error(err);
        });
}

input.addEventListener('input', () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => runSearch(), 300);
});

    // Choosing a suggestion runs the same search the field would.
    input.addEventListener('suggest:live', () => { runSearch(); });

categorySelect.addEventListener('change', () => runSearch());

document.getElementById('search-form').addEventListener('submit', (e) => {
    e.preventDefault();
    runSearch();
});

window.addEventListener('popstate', () => {
    const params = new URLSearchParams(window.location.search);
    input.value = params.get('search') || '';
    categorySelect.value = params.get('category_id') || '';
    runSearch(false);
});
</script>
@endsection
