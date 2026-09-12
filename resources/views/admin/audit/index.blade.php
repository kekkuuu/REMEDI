@extends('layouts.app')

{{-- Without this the layout falls back to @yield('title', 'Dashboard'),
     so both the topbar heading and the browser tab said "Dashboard" on
     this page. Every other view that extends layouts.app sets one; this
     was the only one that did not. The wording matches the sidebar item. --}}
@section('title', 'Audit trail')

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
    {{-- Both actions live in one flex group -- without it, the outer header's
         justify-content:space-between treats Export CSV and Backup Database as
         two independent items and spreads them across the row instead of
         keeping them together as the button pair they are. --}}
    <div style="display:flex; align-items:center; flex-wrap:wrap; gap:10px;">
      {{-- Carries the current filters, so the file matches the table on screen.
           Rendered from the request for a full page load; the live filtering
           below keeps it in step after that. --}}
      <a href="{{ route('audit.export', request()->only(['search', 'action', 'role', 'user_id', 'date_from', 'date_to', 'all'])) }}"
         class="btn btn-primary btn-sm" id="audit-export" data-no-skeleton>
        <i class="ti ti-download" style="font-size:14px;"></i> Export CSV
      </a>
      {{-- Streams a .sql dump of the operational tables (not the imported/
           regenerable ones -- see BackupController::BACKUP_TABLES). No restore
           button by design: this repo's rule for anything that can destroy live
           data is a person with real database access, not a confirm dialog. --}}
      <a href="{{ route('admin.backup') }}" class="btn btn-secondary btn-sm" data-no-skeleton>
        <i class="ti ti-database-export" style="font-size:14px;"></i> Backup Database
      </a>
    </div>
  </div>

  {{-- Scoped-to-one-account banner. Reached from "My Profile"'s "View all
       activity logs" link (see profile/edit.blade.php), which carries
       user_id so this page never shows everyone's actions when a viewer
       expected only their own. Named here rather than left implicit in the
       address bar, and "Clear" is the one way back to the whole trail — it
       drops user_id specifically, keeping any other filter already in
       force (date range, action, role). --}}
  @if($filteredUser)
    <div style="display:flex; align-items:center; gap:8px; background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; border-radius:8px; padding:10px 14px; margin-bottom:1.5rem; font-size:13.5px;">
      <i class="ti ti-user-circle" aria-hidden="true"></i>
      <span>Showing activity for <strong>{{ $filteredUser->name }}</strong> only.</span>
      <a href="{{ route('audit.index', request()->except(['user_id', 'page'])) }}" style="margin-left:auto; color:#1e40af; font-weight:500;">Clear &times;</a>
    </div>
  @endif

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
    {{-- Round-trips the one-account scope through every live refresh below,
         the same way the "all" flag round-trips the Show All state — without
         it, typing in the search box or picking a date resubmits the form
         and silently drops back to every user's rows. Not a control anyone
         sets by hand; it only ever arrives via the profile page's link or
         the banner above, both of which write it directly. --}}
    <input type="hidden" name="user_id" value="{{ request('user_id') }}">

    {{-- Placeholder doesn't mention IP, same reasoning as the inventory/POS
         placeholders dropping "barcode" -- IP search still works
         (applyFilters() still matches ip_address below), it just isn't
         a column shown anywhere on this page, so advertising it in the
         one line every visitor reads was more confusing than helpful. --}}
    <input type="search" name="search" id="audit-search" value="{{ request('search') }}"
           placeholder="Search user, action or details…" class="report-select"
           autocomplete="off" data-suggest-url="{{ route('suggest.audit') }}">

    <select name="action" class="report-select js-audit-filter">
      <option value="">All actions</option>
      {{-- Rendered from AuditTrail::ACTIONS, never a list typed here. The
           hand-typed one said Create/Update/Delete while every row is written
           Created/Updated/Deleted, and the filter matches the column exactly —
           so those three options selected nothing, on a page whose whole job is
           to show that something happened. Compared through canonicalAction()
           so an old ?action=Create link keeps its option selected. --}}
      @foreach(\App\Models\AuditTrail::ACTIONS as $act)
        <option value="{{ $act }}" {{ \App\Models\AuditTrail::canonicalAction(request('action')) === $act ? 'selected' : '' }}>{{ $act }}</option>
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
      <button type="button" class="btn btn-secondary btn-sm" data-preset="today"><i class="ti ti-calendar-event" aria-hidden="true"></i> Today</button>
      <button type="button" class="btn btn-secondary btn-sm" data-preset="7"><i class="ti ti-calendar-week" aria-hidden="true"></i> 7 days</button>
      <button type="button" class="btn btn-secondary btn-sm" data-preset="30"><i class="ti ti-calendar-month" aria-hidden="true"></i> 30 days</button>
      {{-- The page defaults to today (see AuditTrailController::applyTodayDefault);
           this is the explicit way back to the full history. Round-trips
           through this hidden field rather than a plain link so it survives
           the live search/select filters below, which resubmit the whole
           form on every change. --}}
      <button type="button" class="btn btn-secondary btn-sm" id="audit-show-all"><i class="ti ti-list" aria-hidden="true"></i> Show All</button>
    </span>
    <input type="hidden" name="all" id="audit-all-flag" value="{{ request()->boolean('all') ? '1' : '' }}">

    <button type="submit" class="btn btn-primary btn-sm"><i class="ti ti-filter" aria-hidden="true"></i> Filter</button>
    <a href="{{ route('audit.index') }}" class="btn btn-secondary btn-sm"><i class="ti ti-rotate" aria-hidden="true"></i> Reset</a>

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

    /* The pager is centred from tablet up. This page is one full-width table,
       so a left-aligned pager sits under the row-number column with the width
       of the screen empty beside it -- the bespoke pager this replaced was
       centred, and losing that was the one thing the shared component changed
       for the worse here. Left-aligned on a phone, where centring only makes
       the wrapped rows look ragged. The other lists stay left-aligned: their
       pagers sit under narrower content. */
    .audit-pager { margin-top: 16px; }

    @media (min-width: 768px) {
        .audit-pager .pagination { justify-content: center; }
    }
    .audit-presets { display: inline-flex; gap: 6px; }
    .audit-count { margin-left: auto; font-size: 12px; color: #6b7280; white-space: nowrap; }

    @media (max-width: 640px) {
        /* Stack rather than let seven controls fight over one line: the date
           inputs and the preset row each take the full width. */
        .audit-filters { gap: 10px; }
        .audit-filters #audit-search { flex: 1 1 100%; }
        .audit-date { flex: 1 1 calc(50% - 5px); }
        .audit-date input { flex: 1; }
        /* flex-wrap here, not just on .audit-filters -- .audit-presets is its
           own inline-flex row, so its four buttons stayed on one line and
           ran off the edge of the screen regardless of the parent wrapping.
           min-width:0 is what actually lets a flex item shrink below its
           content's natural width; without it "Show All" or "30 days" held
           the button (and the row) wider than the 343px this leaves at
           375px, so the last button was clipped with no way to reach it. */
        .audit-presets { flex: 1 1 100%; flex-wrap: wrap; }
        .audit-presets .btn { flex: 1 1 calc(50% - 3px); justify-content: center; min-width: 0; }
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

        // Keep Export pointed at the same filter set the table is about to
        // show. Updated here rather than after the fetch: the href describes
        // the query, not its result, so it should not wait on the response --
        // and a failed fetch must not leave the button exporting something
        // else. `page` is dropped: the CSV is the whole filtered set.
        const exportLink = document.getElementById('audit-export');
        if (exportLink) {
            const ex = new URL(exportLink.href, window.location.origin);
            ex.search = '';
            url.searchParams.forEach((v, k) => { if (k !== 'page') ex.searchParams.set(k, v); });
            exportLink.href = ex.toString();
        }

        // Hold the scroll offset: a filter that returns far fewer rows shortens
        // the page, and without this the browser clamps the offset and throws
        // you back to the top mid-typing. One shared implementation now — see
        // REMEDI.holdScroll.
        const restoreScroll = REMEDI.holdScroll();

        REMEDI.showListSkeleton(wrapper, { rows: 8 });

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: controller.signal,
        })
            .then(r => r.json())
            .then(data => {
                REMEDI.clearListSkeleton(wrapper);
                wrapper.innerHTML = data.html;
                restoreScroll();

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

    const allFlag = document.getElementById('audit-all-flag');

    form.querySelectorAll('.js-audit-filter').forEach(el => {
        el.addEventListener('change', () => run());
    });

    form.addEventListener('submit', (e) => { e.preventDefault(); run(); });

    // Presets fill the two date inputs and re-run. A specific range is the
    // opposite of "show everything", so clear that flag here too.
    form.querySelectorAll('[data-preset]').forEach(btn => {
        btn.addEventListener('click', () => {
            const to = new Date();
            const from = new Date();
            const p = btn.dataset.preset;
            if (p !== 'today') from.setDate(from.getDate() - (parseInt(p, 10) - 1));

            /* Local date parts, NOT toISOString(). toISOString() converts to
               UTC first, and this app runs in Asia/Manila (UTC+8) -- so
               between midnight and 08:00 local it hands back YESTERDAY's date
               and the "Today" preset quietly filters the audit trail to the
               wrong day. The server renders max="{{ now()->toDateString() }}"
               on these inputs from its own local date, so local is also the
               only thing that agrees with the rest of the page. */
            const iso = (d) => d.getFullYear()
                + '-' + String(d.getMonth() + 1).padStart(2, '0')
                + '-' + String(d.getDate()).padStart(2, '0');
            form.querySelector('[name=date_from]').value = iso(from);
            form.querySelector('[name=date_to]').value = iso(to);
            allFlag.value = '';
            run();
        });
    });

    // Show All: clears the date range and sets the flag that tells
    // AuditTrailController::applyTodayDefault() not to fall back to today.
    document.getElementById('audit-show-all').addEventListener('click', () => {
        form.querySelector('[name=date_from]').value = '';
        form.querySelector('[name=date_to]').value = '';
        allFlag.value = '1';
        run();
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
        allFlag.value = p.get('all') || '';
        run(false);
    });
})();
</script>

@endsection
