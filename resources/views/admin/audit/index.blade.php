@extends('layouts.app')

@section('content')
<div style="padding: 1.5rem 0;">

  {{-- Page Header --}}
  <div style="display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:1.5rem;">
    <div>
      <div style="font-size:20px; font-weight:500;">
        <i class="ti ti-shield-check" style="font-size:18px; vertical-align:-2px; margin-right:7px;"></i>Audit Trail
      </div>
      <div style="font-size:13px; color:#6b7280; margin-top:2px;">System activity log — all user actions recorded</div>
    </div>
    <a href="{{ route('audit.export') }}" class="btn btn-primary btn-sm" data-no-skeleton>
      <i class="ti ti-download" style="font-size:14px;"></i> Export CSV
    </a>
  </div>

  {{-- Stat Cards --}}
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(120px, 1fr)); gap:10px; margin-bottom:1.5rem;">
    <div style="background:#f9fafb; border-radius:8px; padding:12px 14px;">
      <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Total logs</div>
      <div style="font-size:22px; font-weight:500;">{{ $logs->total() }}</div>
    </div>
    <div style="background:#f9fafb; border-radius:8px; padding:12px 14px;">
      <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Logins</div>
      <div style="font-size:22px; font-weight:500; color:#185FA5;">{{ $loginCount }}</div>
    </div>
    <div style="background:#f9fafb; border-radius:8px; padding:12px 14px;">
      <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Logouts</div>
      <div style="font-size:22px; font-weight:500; color:#854F0B;">{{ $logoutCount }}</div>
    </div>
    <div style="background:#f9fafb; border-radius:8px; padding:12px 14px;">
      <div style="font-size:12px; color:#6b7280; margin-bottom:4px;">Views / Reports</div>
      <div style="font-size:22px; font-weight:500; color:#3B6D11;">{{ $viewCount }}</div>
    </div>
  </div>

  {{-- Filters.

       Live: every control refreshes the table over fetch — the search box on a
       debounced keystroke, the selects and dates on change. The <form> stays a
       real GET form so the page still works without JS, and the Filter button
       is that fallback's submit. --}}
  <form method="GET" action="{{ route('audit.index') }}" id="audit-filters" class="audit-filters">
    <input type="search" name="search" id="audit-search" value="{{ request('search') }}"
           placeholder="Search user, action, details or IP…" class="report-select"
           autocomplete="off" data-suggest-url="{{ route('suggest.audit') }}">

    <select name="action" class="report-select js-audit-filter">
      <option value="">All actions</option>
      @foreach(['Login','Logout','Viewed','Create','Update','Delete'] as $act)
        <option value="{{ $act }}" {{ request('action') == $act ? 'selected' : '' }}>{{ $act }}</option>
      @endforeach
    </select>

    <select name="role" class="report-select js-audit-filter">
      <option value="">All roles</option>
      <option value="admin" {{ request('role') == 'admin' ? 'selected' : '' }}>Admin</option>
      <option value="staff" {{ request('role') == 'staff' ? 'selected' : '' }}>Staff</option>
    </select>

    {{-- Date range. max=today on both: an audit trail cannot contain the
         future, so offering it only invites an empty result. --}}
    <label class="audit-date">
      <span>From</span>
      <input type="date" name="date_from" class="report-select js-audit-filter"
             value="{{ request('date_from') }}" max="{{ now()->toDateString() }}">
    </label>

    <label class="audit-date">
      <span>To</span>
      <input type="date" name="date_to" class="report-select js-audit-filter"
             value="{{ request('date_to') }}" max="{{ now()->toDateString() }}">
    </label>

    {{-- Presets: the ranges anyone actually asks an audit log for. --}}
    <span class="audit-presets">
      <button type="button" class="btn btn-secondary btn-sm" data-preset="today">Today</button>
      <button type="button" class="btn btn-secondary btn-sm" data-preset="7">7 days</button>
      <button type="button" class="btn btn-secondary btn-sm" data-preset="30">30 days</button>
    </span>

    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="{{ route('audit.index') }}" class="btn btn-secondary btn-sm">Reset</a>

    <span class="audit-count" id="audit-count" aria-live="polite">{{ number_format($logs->total()) }} entries</span>
  </form>

  {{-- Swapped wholesale by the live filters below. --}}
  <div id="audit-results">
    @include('admin.audit._rows')
  </div>

</div>

<style>
    .audit-filters {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
        margin-bottom: 1rem;
    }

    .audit-filters #audit-search { flex: 1 1 240px; min-width: 0; }

    .audit-date {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12px;
        color: #6b7280;
    }

    .audit-date input { min-width: 0; }
    .audit-presets { display: inline-flex; gap: 6px; }
    .audit-count { margin-left: auto; font-size: 12px; color: #6b7280; white-space: nowrap; }

    @media (max-width: 640px) {
        /* Stack rather than let seven controls fight over one line: the date
           inputs and the preset row each take the full width. */
        .audit-filters { gap: 10px; }
        .audit-filters #audit-search { flex: 1 1 100%; }
        .audit-date { flex: 1 1 calc(50% - 5px); }
        .audit-date input { flex: 1; }
        .audit-presets { flex: 1 1 100%; }
        .audit-presets .btn { flex: 1; justify-content: center; }
        .audit-count { margin-left: 0; flex: 1 1 100%; }
    }
</style>

<script>
(function () {
    const form = document.getElementById('audit-filters');
    if (!form) return;

    const search = document.getElementById('audit-search');
    const wrapper = document.getElementById('audit-results');
    const countEl = document.getElementById('audit-count');
    const base = @json(route('audit.index'));

    let debounce;
    let controller;

    function run(pushState = true) {
        if (controller) controller.abort();
        controller = new AbortController();

        const url = new URL(base);
        new FormData(form).forEach((v, k) => { if (String(v).trim() !== '') url.searchParams.set(k, v); });

        // Hold the scroll offset: a filter that returns far fewer rows shortens
        // the page, and without this the browser clamps the offset and throws
        // you back to the top mid-typing.
        const scroller = REMEDI.scroller();
        const y = scroller.scrollTop;

        REMEDI.showListSkeleton(wrapper, { rows: 8 });

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: controller.signal,
        })
            .then(r => r.json())
            .then(data => {
                REMEDI.clearListSkeleton(wrapper);
                wrapper.innerHTML = data.html;
                scroller.scrollTop = y;

                if (countEl) {
                    countEl.textContent = Number(data.total).toLocaleString() + ' entries';
                }
                if (pushState) window.history.pushState({}, '', url);
            })
            .catch(err => { if (err.name !== 'AbortError') console.error(err); });
    }

    search.addEventListener('input', () => {
        clearTimeout(debounce);
        debounce = setTimeout(run, 300);
    });

    // Choosing a suggestion runs the same search the field would.
    search.addEventListener('suggest:live', () => run());

    form.querySelectorAll('.js-audit-filter').forEach(el => {
        el.addEventListener('change', () => run());
    });

    form.addEventListener('submit', (e) => { e.preventDefault(); run(); });

    // Presets fill the two date inputs and re-run.
    form.querySelectorAll('[data-preset]').forEach(btn => {
        btn.addEventListener('click', () => {
            const to = new Date();
            const from = new Date();
            const p = btn.dataset.preset;
            if (p !== 'today') from.setDate(from.getDate() - (parseInt(p, 10) - 1));

            const iso = (d) => d.toISOString().slice(0, 10);
            form.querySelector('[name=date_from]').value = iso(from);
            form.querySelector('[name=date_to]').value = iso(to);
            run();
        });
    });

    // Paginate without leaving the page, so the filters survive a page change.
    wrapper.addEventListener('click', (e) => {
        const link = e.target.closest('a[href]');
        if (!link || !wrapper.contains(link)) return;
        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin) return;

        e.preventDefault();
        e.stopPropagation();          // keep the layout's navigation skeleton out of it

        const page = url.searchParams.get('page');
        const target = new URL(base);
        new FormData(form).forEach((v, k) => { if (String(v).trim() !== '') target.searchParams.set(k, v); });
        if (page) target.searchParams.set('page', page);

        if (controller) controller.abort();
        controller = new AbortController();
        REMEDI.showListSkeleton(wrapper, { rows: 8 });

        fetch(target, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: controller.signal,
        })
            .then(r => r.json())
            .then(data => {
                REMEDI.clearListSkeleton(wrapper);
                wrapper.innerHTML = data.html;
                window.history.pushState({}, '', target);
            })
            .catch(err => { if (err.name !== 'AbortError') console.error(err); });
    });

    window.addEventListener('popstate', () => {
        const p = new URLSearchParams(window.location.search);
        search.value = p.get('search') || '';
        form.querySelectorAll('.js-audit-filter').forEach(el => { el.value = p.get(el.name) || ''; });
        run(false);
    });
})();
</script>

@endsection
