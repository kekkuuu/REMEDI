{{-- resources/views/partials/_page-skeleton.blade.php --}}
{{--
    Three silhouettes in one partial, defined ONCE and used by two callers:

      - layouts/app.blade.php, for in-app navigation between pages
        (`.content-body.is-navigating`), where a whole document is being
        swapped and there is no shell on screen to hold the frame; and
      - dashboard/_loading.blade.php, where the dash shape sits behind the
        dimmed scrim so the page has its own shape behind the floating
        loader instead of an empty panel.

    It is display:none by default -- each caller opts it in with its own
    rule, and layouts/app.blade.php picks WHICH shape via `data-shape` on
    `.page-skeleton` itself (see the CSS and the nav-skeleton JS there):

      - "dash"  -- dashboard, reports, forecast, sales forecasting: stat
                   tiles over charts.
      - "pos"   -- the POS page: a product-tile grid beside a cart panel.
      - "feed"  -- Notifications: a flat scrolling list of alert/activity
                   cards, not a table.
      - "list"  -- Inventory, Products, Sales, Users, the audit trail,
                   Categories: a header over a table, PARAMETRIZED via
                   data-header / data-kpis / data-toolbar / data-tabs /
                   data-cols since these six pages disagree with each other
                   about a KPI row, tab chips, toolbar shape and column
                   count far more than they agree. See the comment inside
                   the sk-shape-list block below, and the per-route table
                   in layouts/app.blade.php's nav-skeleton JS.

    REMEDI.md "Theme": the dash shape must keep mirroring the dashboard's
    real proportions (greeting bar + date pill, SIX 118px KPI tiles on
    .kpi-grid's auto-fit track, tab pills, two side-by-side 280px charts).
    A skeleton that no longer matches is worse than none, because the page
    visibly jumps when the real content lands. The same is true of the pos
    shape against pos/index.blade.php's .pos-layout, and of each
    data-* combination against the real page it stands in for. The .sk-*
    primitives for all four live in layouts/app.blade.php.
--}}
<div class="page-skeleton" aria-hidden="true">
    {{-- KPI tiles and charts. The dashboard and the reports; also the default,
         which is what dashboard/_loading's backdrop wants. --}}
    <div class="sk-shape sk-shape-dash">
    <div class="sk-greeting">
        <div class="sk-greeting-text">
            <div class="sk-block sk-h"></div>
            <div class="sk-block sk-sub"></div>
        </div>
        <div class="sk-block sk-pill"></div>
    </div>

    <div class="sk-kpis">
        @for ($i = 0; $i < 6; $i++)
            <div class="sk-kpi">
                <div class="sk-kpi-head">
                    <div class="sk-block sk-disc"></div>
                    <div class="sk-block sk-cap"></div>
                </div>
                <div class="sk-block sk-num"></div>
                <div class="sk-block sk-note"></div>
            </div>
        @endfor
    </div>

    <div class="sk-tabs">
        <div class="sk-block sk-pill-tab"></div>
        <div class="sk-block sk-pill-tab"></div>
    </div>

    <div class="sk-block sk-sec-title"></div>
    <div class="sk-block sk-sec-sub"></div>

    <div class="sk-charts">
        <div class="sk-chart">
            <div class="sk-block sk-chart-title"></div>
            <div class="sk-block sk-chart-body"></div>
        </div>
        <div class="sk-chart">
            <div class="sk-block sk-chart-title"></div>
            <div class="sk-block sk-chart-body"></div>
        </div>
    </div>

    <div class="sk-lower">
        <div class="sk-chart">
            <div class="sk-block sk-chart-title"></div>
            <div class="sk-block sk-chart-body"></div>
        </div>
        <div class="sk-chart">
            <div class="sk-block sk-chart-title"></div>
            <div class="sk-block sk-chart-body"></div>
        </div>
    </div>
    </div>

    {{-- POS: a search bar over a product-tile grid beside a sticky cart panel
         -- matches pos/index.blade.php's own .pos-layout (2fr/1fr) rather
         than standing in with a table of rows, which POS has never had. --}}
    <div class="sk-shape sk-shape-pos">
        <div class="sk-block sk-search" style="max-width:420px; margin-bottom:18px;"></div>
        <div class="sk-pos-layout">
            <div class="sk-pos-grid">
                @for ($i = 0; $i < 9; $i++)
                    <div class="sk-pos-tile">
                        <div class="sk-block sk-pos-tile-title"></div>
                        <div class="sk-block sk-pos-tile-sub"></div>
                        <div class="sk-block sk-pos-tile-price"></div>
                    </div>
                @endfor
            </div>
            <div class="sk-pos-cart">
                <div class="sk-block sk-pos-cart-title"></div>
                @for ($i = 0; $i < 3; $i++)
                    <div class="sk-pos-cart-row">
                        <div class="sk-block sk-pos-cart-name"></div>
                        <div class="sk-block sk-pos-cart-qty"></div>
                    </div>
                @endfor
                <div class="sk-block sk-pos-cart-total"></div>
                <div class="sk-block sk-pos-cart-btn"></div>
            </div>
        </div>
    </div>

    {{-- A header over rows -- Inventory, Products, Sales, Users, the audit
         trail, Categories all land here. Every one of these differs from a
         plain "header + search + table" in some real way (a KPI row here,
         no header there, seven filter chips over there, three columns
         instead of eleven for Categories), and standing in with one fixed
         silhouette for all of them was its own version of the POS problem:
         the wrong-shaped placeholder visibly snaps into the real shape when
         the page lands. Rather than one dedicated block per page, this
         renders the FULL superset (4 KPI tiles, a rich toolbar, 7 tabs, 11
         table columns) and layouts/app.blade.php's nav-skeleton JS sets
         data-header / data-kpis / data-toolbar / data-tabs / data-cols on
         .page-skeleton per destination route; the CSS in there hides
         whatever a given page doesn't have. See the per-route table in that
         JS for exactly which page gets which combination. --}}
    <div class="sk-shape sk-shape-list">
        <div class="sk-block sk-head"></div>
        <div class="sk-block sk-head-sub"></div>

        {{-- KPI row: 0, 2 or 4 tiles depending on data-kpis. Users (4),
             Sales (2) and the audit trail (4) all show real stat cards
             above their table; Products, Categories and Inventory don't. --}}
        <div class="sk-kpis-sm">
            @for ($i = 0; $i < 4; $i++)
                <div class="sk-kpi-sm">
                    <div class="sk-block sk-kpi-sm-cap"></div>
                    <div class="sk-block sk-kpi-sm-num"></div>
                </div>
            @endfor
        </div>

        {{-- Plain toolbar: search plus up to two extra fields (dates/selects
             on Sales and the audit trail) and up to two buttons. data-toolbar
             switches between "simple" (search + 1 button -- Users, Products,
             Inventory) and "rich" (the fuller row Sales and the audit trail
             actually show). --}}
        <div class="sk-toolbar">
            <div class="sk-block sk-search"></div>
            <div class="sk-block sk-btn"></div>
            <div class="sk-block sk-field sk-extra"></div>
            <div class="sk-block sk-field sk-extra"></div>
            <div class="sk-block sk-btn sk-extra"></div>
        </div>

        {{-- Card toolbar: Categories replaces the search row entirely with
             an "Add Category" trigger card (icon + text + button), so this
             is a completely different shape from a search box, not just a
             narrower one. --}}
        <div class="sk-toolbar-card">
            <div class="sk-block sk-disc"></div>
            <div class="sk-toolbar-card-text">
                <div class="sk-block sk-cap" style="width:150px;"></div>
                <div class="sk-block sk-note" style="width:230px; margin-top:6px;"></div>
            </div>
            <div class="sk-block sk-btn"></div>
        </div>

        {{-- Filter tabs: 0 (most of these pages have none at all -- Users,
             Sales, the audit trail, Products, Categories) or 7 (Inventory's
             real status chips: All/Low Stock/Expiring/Expired/Need to
             Return/Fail to Return/Returned). --}}
        <div class="sk-tabs">
            @for ($i = 0; $i < 7; $i++)
                <div class="sk-block sk-pill-tab sk-pill-tab-sm"></div>
            @endfor
        </div>

        {{-- Table: 3 (Categories), 6 (Users/Sales/the audit trail) or 11
             (Products/Inventory) columns, via data-cols hiding the rest. --}}
        <div class="sk-table">
            @for ($row = 0; $row < 9; $row++)
                <div class="sk-trow">
                    @for ($col = 0; $col < 11; $col++)
                        <div class="sk-block sk-td @if ($col === 0) narrow @elseif ($col === 2) wide @endif"></div>
                    @endfor
                </div>
            @endfor
        </div>
    </div>

    {{-- Feed: Notifications is a flat scrolling list of alert/activity cards
         (icon, title, body, time), not a table at all -- forcing it through
         the list shape's 6-column table was the single worst mismatch of
         any page, per the request that prompted this. Tabs go up to 7 to
         match the page's own real tab count (All + five alert kinds +
         System/Updates for an admin). --}}
    <div class="sk-shape sk-shape-feed">
        <div class="sk-block sk-head"></div>
        <div class="sk-block sk-head-sub"></div>

        <div class="sk-tabs">
            @for ($i = 0; $i < 7; $i++)
                <div class="sk-block sk-pill-tab sk-pill-tab-sm"></div>
            @endfor
        </div>

        <div class="sk-feed">
            @for ($i = 0; $i < 7; $i++)
                <div class="sk-feed-row">
                    <div class="sk-block sk-disc sk-feed-icon"></div>
                    <div class="sk-feed-text">
                        <div class="sk-block sk-feed-title"></div>
                        <div class="sk-block sk-feed-body"></div>
                    </div>
                    <div class="sk-block sk-feed-time"></div>
                </div>
            @endfor
        </div>
    </div>
</div>
