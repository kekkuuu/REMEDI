@extends('layouts.app')

@section('title', 'User Management')

@section('content')
<style>
    /* ---- Toolbar ------------------------------------------------------- */
    .users-toolbar {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 18px;
    }

    /* Search is a pill with the glyph INSIDE it rather than a bare input with
       a Search button beside it: the list filters on submit either way, and the
       button was taking horizontal room from a field people type into.

       One glyph, not two: there used to also be a purely decorative, non-
       interactive ti-search icon pinned to the left of the field, which put
       two identical magnifying glasses on screen at once -- the submit
       button's icon on the right is the only one every other search box in
       the app renders (see sales/index.blade.php), so it does the same job
       here without the duplicate. */
    .users-search {
        position: relative;
        flex: 1;
        min-width: 240px;
        max-width: 420px;
    }

    .users-search input {
        width: 100%;
        padding: 11px 38px 11px 14px;
        border: 1px solid var(--line);
        border-radius: 12px;
        font-size: 13px;
        background: var(--surface);
    }

    /* Submit lives inside the field, so Enter and the glyph do the same thing. */
    .users-search button {
        position: absolute;
        right: 6px;
        top: 50%;
        transform: translateY(-50%);
        border: 0;
        background: transparent;
        color: #94a3b8;
        cursor: pointer;
        padding: 6px;
        line-height: 1;
        border-radius: 8px;
    }

    .users-search button:hover { color: var(--brand-dark); background: var(--brand-soft); }

    .users-toolbar .spacer { flex: 1 1 auto; }

    /* ---- Filter panel --------------------------------------------------- */
    .users-filters {
        display: flex;
        gap: 12px;
        align-items: flex-end;
        flex-wrap: wrap;
        padding: 14px 16px;
        margin-bottom: 18px;
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: 14px;
    }

    /* An author `display` beats the UA stylesheet's rule for [hidden], so the
       flex above silently defeats the attribute and the panel renders while
       claiming to be hidden -- assistive tech is told one thing and the screen
       shows another. Exactly what `.dash-loader` carrying its own `display`
       did to the loader's two gate rules (see REMEDI.md). Restate it here,
       where the attribute selector outranks the class. */
    .users-filters[hidden] { display: none; }

    .users-filters label {
        display: block;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 5px;
    }

    .users-filters select {
        padding: 8px 12px;
        border: 1px solid var(--line);
        border-radius: 9px;
        font-size: 13px;
        min-width: 150px;
        background: #fff;
    }

    /* ---- Table ---------------------------------------------------------- */
    /* Sortable heading. An <a> so it is a real link — the sort is a URL, which
       means it survives a refresh and can be bookmarked or shared. */
    .th-sort {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        color: inherit;
        text-decoration: none;
        white-space: nowrap;
    }

    .th-sort i { font-size: 13px; color: #cbd5e1; }
    .th-sort:hover { color: var(--brand-dark); }
    .th-sort:hover i { color: var(--brand); }
    .th-sort.is-sorted i { color: var(--brand-dark); }

    /* Name cell: avatar, then the name with its role underneath. Reuses
       .user-avatar from the topbar rather than defining a second circle. */
    .user-cell { display: flex; align-items: center; gap: 11px; }
    .user-cell .user-avatar { width: 36px; height: 36px; font-size: 12px; flex-shrink: 0; }
    .user-cell-name { font-weight: 600; color: var(--ink); line-height: 1.25; }
    .user-cell-role { font-size: 11.5px; color: var(--ink-soft); }

    /* ---- Actions -------------------------------------------------------- */
    /* .actions-cell, its gap and the soft action-button treatment all live in
       layouts/app.blade.php now, shared with the products and inventory
       tables. They were briefly duplicated here, and the first copy silently
       lost: the layout's selector is `.remedi-table .actions-cell`, two
       classes to one, so a bare `.actions-cell { gap }` in this page changed
       nothing at all. Nothing errors when a rule loses on specificity -- the
       page just ignores you. Only the rules genuinely specific to THIS table
       stay below. */

    .action-btn {
        border-radius: 8px;
        padding: 6px 12px;
        font-size: 13px;
        /* Centred, so a label that is narrower than its box sits in the middle
           of it rather than hard against the left edge. */
        justify-content: center;
    }

    /* The toggle is the only action whose LABEL changes with the row, and the
       two words are not the same width: measured at 13px, "Deactivate" is 112px
       and "Activate" 98px. That 14px difference does not stay in this column --
       it drags Delete left on every activated row, so the last column runs down
       the table with a step in it. Sizing the toggle to its widest label pins
       Delete to one x-position for every row.

       In em, not px, so it survives a font-size change: 8.7em at this button's
       13px is the measured 112px, and both labels grow together. */
    .action-toggle { min-width: 8.7em; }

    /* ---- Footer --------------------------------------------------------- */
    .users-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 16px;
    }

    .users-count { font-size: 12.5px; color: var(--ink-soft); }

    @media (max-width: 700px) {
        .users-toolbar .spacer { display: none; }
        .users-search { max-width: none; }
    }
</style>

<div class="page-head">
    <div class="page-head-text">
        <h3><i class="ti ti-users" style="font-size:18px;vertical-align:-2px;margin-right:7px;"></i>User Management</h3>
        <p>Manage system users and their access.</p>
    </div>
</div>

@php
    // Read once, up here. These were assigned inline inside the aria-expanded
    // expression -- `($roleFilter = ...) || ($statusFilter = ...)` -- and `||`
    // SHORT-CIRCUITS, so the moment a role filter was set the second assignment
    // never ran and every later use of $statusFilter was undefined. An
    // assignment buried in a boolean expression only runs when the boolean
    // happens to reach it.
    $roleFilter = request('role');
    $statusFilter = request('status');
    $hasFilter = $roleFilter || $statusFilter;
@endphp

{{-- One GET form owns the search AND the filters, so they compose instead of
     clearing each other: submitting from the search box carries the role and
     status already in force, and vice versa. The sort links carry them too. --}}
<form method="GET" action="{{ route('users.index') }}" id="usersFilterForm">
    <div class="users-toolbar">
        <div class="users-search">
            <input type="text" id="usersSearchInput" name="search" value="{{ request('search') }}"
                   placeholder="Search by name or email..."
                   autocomplete="off"
                   data-suggest-url="{{ route('suggest.users') }}">
            {{-- With JavaScript off this button (and Enter) still submits the
                 form normally -- the live search below is an enhancement, not
                 the only way this works. --}}
            <button type="submit" aria-label="Search"><i class="ti ti-search" aria-hidden="true"></i></button>
        </div>

        {{-- Opens the panel below rather than filtering by itself. It carries
             a dot while a filter is actually in force, so a narrowed list is
             never a mystery after the panel is closed again. --}}
        <button type="button" class="btn btn-secondary" id="usersFilterToggle"
                aria-expanded="{{ $hasFilter ? 'true' : 'false' }}"
                aria-controls="usersFilterPanel">
            <i class="ti ti-filter" aria-hidden="true"></i> Filter{{ $hasFilter ? ' ·' : '' }}
        </button>

        <span class="spacer"></span>

        <a href="{{ route('register') }}" class="btn btn-primary btn-lg">
            <i class="ti ti-user-plus" aria-hidden="true"></i> Add User
        </a>
    </div>

    {{-- Rendered open when a filter is in force, so the controls that are
         narrowing the table are visible beside it. --}}
    <div class="users-filters" id="usersFilterPanel" @unless($hasFilter) hidden @endunless>
        <div>
            <label for="role">Role</label>
            <select name="role" id="role">
                <option value="">All roles</option>
                <option value="admin" {{ $roleFilter === 'admin' ? 'selected' : '' }}>Admin</option>
                <option value="staff" {{ $roleFilter === 'staff' ? 'selected' : '' }}>Staff</option>
            </select>
        </div>
        <div>
            <label for="status">Status</label>
            <select name="status" id="status">
                <option value="">All statuses</option>
                <option value="active" {{ $statusFilter === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ $statusFilter === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>
        {{-- No Apply button: both selects already re-run the search on
             change (see the JS below), so it only ever re-submitted a
             filter that had already applied. The form still has a real
             submit control -- the search box's icon button above -- so a
             no-JS visitor can still change role/status and click that to
             apply them; nothing here relies on this button existing. --}}
        <div style="display:flex;gap:8px;">
            @if($hasFilter || request('search'))
                <a href="{{ route('users.index') }}" class="btn btn-secondary btn-sm">Clear</a>
            @endif
        </div>
    </div>

    {{-- The sort travels with the filters. Hidden rather than appended to each
         link, so one place decides what a submit carries. --}}
    <input type="hidden" name="sort" value="{{ request('sort') }}">
    <input type="hidden" name="dir" value="{{ request('dir') }}">
</form>

{{-- Counted across ALL accounts, never the filtered slice — see
     UserController::index. "Total Users" that quietly reported the search box
     would be the same bug as a "Total sales today" card that reports a date
     filter. --}}
<div class="kpi-grid" style="margin-bottom:18px;">
    <div class="kpi" style="--kpi-accent:#0d9488;">
        <div class="kpi-head">
            <i class="ti ti-users" aria-hidden="true"></i>
            <span class="kpi-label">Total Users</span>
        </div>
        <span class="kpi-value">{{ number_format($stats['total']) }}</span>
        <span class="kpi-sub">All registered users</span>
    </div>

    <div class="kpi" style="--kpi-accent:#10b981;">
        <div class="kpi-head">
            <i class="ti ti-circle-check" aria-hidden="true"></i>
            <span class="kpi-label">Active Users</span>
        </div>
        <span class="kpi-value">{{ number_format($stats['active']) }}</span>
        <span class="kpi-sub">Currently active</span>
    </div>

    <div class="kpi" style="--kpi-accent:#f59e0b;">
        <div class="kpi-head">
            <i class="ti ti-player-pause" aria-hidden="true"></i>
            <span class="kpi-label">Inactive Users</span>
        </div>
        <span class="kpi-value">{{ number_format($stats['inactive']) }}</span>
        <span class="kpi-sub">Signed out and blocked</span>
    </div>

    <div class="kpi" style="--kpi-accent:#3b82f6;">
        <div class="kpi-head">
            <i class="ti ti-shield-lock" aria-hidden="true"></i>
            <span class="kpi-label">Admins</span>
        </div>
        <span class="kpi-value">{{ number_format($stats['admins']) }}</span>
        <span class="kpi-sub">Administrator(s)</span>
    </div>
</div>

{{-- The table + footer live in _rows.blade.php, shared with the AJAX
     search/filter refresh below — see the AJAX partial pattern in CLAUDE.md.
     #usersResults is the whole swappable region. --}}
<div class="card" id="usersResults">
    @include('admin.users._rows')
</div>

<script>
/* The Filter button reveals the panel; it does not filter by itself.
 *
 * `hidden` rather than a display rule, so the panel is out of the accessibility
 * tree as well as off screen while closed. The panel starts OPEN when a filter
 * is already in force (rendered server-side), so this only has to handle the
 * toggle.
 */
(function () {
    var button = document.getElementById('usersFilterToggle');
    var panel = document.getElementById('usersFilterPanel');
    if (!button || !panel) return;

    button.addEventListener('click', function () {
        panel.hidden = !panel.hidden;
        button.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');

        if (!panel.hidden) {
            var first = panel.querySelector('select');
            if (first) first.focus();
        }
    });
})();

/* Realtime search, matching the pattern products/index.blade.php and
 * inventory/index.blade.php already use -- see "AJAX partial pattern" and
 * "Search: live filtering, no dropdown" in REMEDI.md. Search debounces on
 * every keystroke; the role/status filters and the suggestion dropdown's
 * pick both re-run the same search immediately.
 *
 * Deliberately does NOT touch the KPI cards above #usersResults -- those are
 * counted before any filter (see UserController::index) and the AJAX
 * response never carries them, so there is nothing here that could
 * accidentally make "Total Users" start reporting the search box.
 */
(function () {
    var input = document.getElementById('usersSearchInput');
    var roleSelect = document.getElementById('role');
    var statusSelect = document.getElementById('status');
    var wrapper = document.getElementById('usersResults');
    var form = document.getElementById('usersFilterForm');
    if (!input || !wrapper || !form) return;

    var baseUrl = "{{ route('users.index') }}";
    var debounceTimer;
    var currentController;

    function runSearch(pushState) {
        if (pushState === undefined) pushState = true;
        if (currentController) currentController.abort();
        currentController = new AbortController();

        var url = new URL(baseUrl);
        var term = input.value.trim();
        var role = roleSelect ? roleSelect.value : '';
        var status = statusSelect ? statusSelect.value : '';
        // Sort/dir travel with every search, the same way the hidden fields
        // carry them on a plain form submit -- otherwise typing in the search
        // box would silently drop whatever column the table was sorted by.
        var sort = form.elements.sort ? form.elements.sort.value : '';
        var dir = form.elements.dir ? form.elements.dir.value : '';

        if (term) url.searchParams.set('search', term);
        if (role) url.searchParams.set('role', role);
        if (status) url.searchParams.set('status', status);
        if (sort) url.searchParams.set('sort', sort);
        if (dir) url.searchParams.set('dir', dir);

        var restoreScroll = REMEDI.holdScroll();
        REMEDI.showListSkeleton(wrapper, { rows: 6 });

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: currentController.signal,
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                REMEDI.clearListSkeleton(wrapper);
                wrapper.innerHTML = data.html;
                restoreScroll();

                if (pushState) window.history.pushState({}, '', url);
            })
            .catch(function (err) {
                if (err.name !== 'AbortError') console.error(err);
            });
    }

    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () { runSearch(); }, 300);
    });

    // Choosing a suggestion runs the same search the field would.
    input.addEventListener('suggest:live', function () { runSearch(); });

    if (roleSelect) roleSelect.addEventListener('change', function () { runSearch(); });
    if (statusSelect) statusSelect.addEventListener('change', function () { runSearch(); });

    // Enter in the search box, or clicking Apply -- same live path rather
    // than a full page reload.
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        runSearch();
    });

    window.addEventListener('popstate', function () {
        var params = new URLSearchParams(window.location.search);
        input.value = params.get('search') || '';
        if (roleSelect) roleSelect.value = params.get('role') || '';
        if (statusSelect) statusSelect.value = params.get('status') || '';
        runSearch(false);
    });
})();
</script>
@endsection
