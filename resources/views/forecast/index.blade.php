@extends('layouts.app')

@section('title', 'Demand Forecasts')

@section('content')
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<div class="card">
    <h2 style="margin-top:0; margin-bottom:18px; font-size:20px; font-weight:500;">
        Demand Forecasts
    </h2>
    <p style="font-size:13px; color:#64748b; margin:0 0 18px;">
        Per-product demand forecast, modeled from sales history. For store-wide sales and revenue trends, see
        <a href="{{ route('sales-forecast.index') }}">Sales Forecasting</a>.
    </p>

    <form id="search-form" method="GET" action="{{ route('forecast.index') }}" style="margin-bottom:18px; display:flex; gap:10px; align-items:center;">
        <input
            type="text"
            name="search"
            id="search-input"
            data-suggest-url="{{ route('suggest.products') }}"
            value="{{ $search }}"
            placeholder="Search by name or SKU..."
            style="width:260px;"
            autocomplete="off"
        >
        <select name="category" id="category-select" style="width:200px;">
            <option value="">All categories</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-primary">
            <i class="ti ti-search" aria-hidden="true"></i> Search
        </button>
        <a href="{{ route('forecast.index') }}" id="clear-link" class="btn btn-secondary" style="{{ ($search || $categoryId) ? '' : 'display:none' }}">
            <i class="ti ti-x" aria-hidden="true"></i> Clear
        </a>
    </form>

    <div id="results-wrapper">
        @if ($forecasts->isEmpty())
            <p id="empty-message" style="color:#64748b;">
                @if ($search || $categoryId)
                    No forecasts found matching those filters.
                @else
                    No forecasts generated yet. Run
                    <code style="background:#f1f5f9; padding:2px 6px; border-radius:4px;">php artisan forecast:generate --source=csv ...</code>
                @endif
            </p>
        @else
            @include('forecast._rows')
        @endif

        <div style="margin-top:18px;" id="pagination-wrapper">
            {{ $forecasts->links() }}
        </div>
    </div>
</div>

<script>
function renderSparklines() {
    document.querySelectorAll('canvas.sparkline').forEach(canvas => {
        if (canvas.dataset.rendered) return;
        const labels = JSON.parse(canvas.dataset.labels);
        const values = JSON.parse(canvas.dataset.values);
        new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    data: values,
                    borderColor: '#4f46e5',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.3,
                    fill: false,
                }],
            },
            options: {
                responsive: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        callbacks: {
                            label: (item) => Math.round(item.parsed.y),
                        },
                    },
                },
                scales: { x: { display: false }, y: { display: false } },
            },
        });
        canvas.dataset.rendered = "1";
    });
}
renderSparklines();

const input = document.getElementById('search-input');
const categorySelect = document.getElementById('category-select');
const wrapper = document.getElementById('results-wrapper');
const clearLink = document.getElementById('clear-link');
const baseUrl = "{{ route('forecast.index') }}";

let debounceTimer;
let currentController;

function runSearch(term, category, pushState = true) {
    if (currentController) currentController.abort();
    currentController = new AbortController();

    const url = new URL(baseUrl);
    if (term) {
        url.searchParams.set('search', term);
    }
    if (category) {
        url.searchParams.set('category', category);
    }

    // Swap the stale rows for a skeleton so a search/filter reads as

    // 'working' instead of leaving the previous results on screen.

    REMEDI.showListSkeleton(wrapper, { rows: 6 });


    fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        signal: currentController.signal,
    })
        .then(res => res.json())
        .then(data => {
            wrapper.innerHTML = data.empty
                ? `<p id="empty-message" style="color:#64748b;">No forecasts found matching those filters.</p>`
                : data.html + `<div style="margin-top:18px;" id="pagination-wrapper">${data.pagination}</div>`;

            clearLink.style.display = (term || category) ? '' : 'none';
            renderSparklines();

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
    const term = input.value.trim();
    debounceTimer = setTimeout(() => runSearch(term, categorySelect.value), 300);
});

    // Choosing a suggestion runs the same search the field would.
    input.addEventListener('suggest:live', () => { runSearch(term, categorySelect.value); });

categorySelect.addEventListener('change', () => {
    runSearch(input.value.trim(), categorySelect.value);
});

document.getElementById('search-form').addEventListener('submit', (e) => {
    e.preventDefault();
    runSearch(input.value.trim(), categorySelect.value);
});

clearLink.addEventListener('click', (e) => {
    e.preventDefault();
    input.value = '';
    categorySelect.value = '';
    runSearch('', '');
});

window.addEventListener('popstate', () => {
    const params = new URLSearchParams(window.location.search);
    const term = params.get('search') || '';
    const category = params.get('category') || '';
    input.value = term;
    categorySelect.value = category;
    runSearch(term, category, false);
});
</script>
@endsection
