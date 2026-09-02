{{-- resources/views/partials/_page-skeleton.blade.php --}}
{{--
    The dashboard-shaped skeleton, defined ONCE and used by two callers:

      - layouts/app.blade.php, for in-app navigation between pages
        (`.content-body.is-navigating`), where a whole document is being
        swapped and there is no shell on screen to hold the frame; and
      - dashboard/_loading.blade.php, where it sits behind the dimmed scrim
        so the page has its own shape behind the floating loader instead of
        an empty panel.

    It is display:none by default -- each caller opts it in with its own rule.

    REMEDI.md "Theme": this must keep mirroring the dashboard's real
    proportions (greeting bar + date pill, SIX 118px KPI tiles on .kpi-grid's
    auto-fit track, tab pills, two side-by-side 280px charts). A skeleton that
    no longer matches is worse than none, because the page visibly jumps when
    the real content lands. The .sk-* primitives live in layouts/app.blade.php.
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

    {{-- A header over rows. Inventory, Products, Sales, Users, the audit trail,
         notifications -- every other page in the app. Kept generic: it says "a
         list is coming", not which one. --}}
    <div class="sk-shape sk-shape-list">
        <div class="sk-block sk-head"></div>
        <div class="sk-block sk-head-sub"></div>

        <div class="sk-toolbar">
            <div class="sk-block sk-search"></div>
            <div class="sk-block sk-btn"></div>
        </div>

        <div class="sk-tabs">
            <div class="sk-block sk-pill-tab"></div>
            <div class="sk-block sk-pill-tab"></div>
            <div class="sk-block sk-pill-tab"></div>
        </div>

        <div class="sk-table">
            @for ($row = 0; $row < 9; $row++)
                <div class="sk-trow">
                    <div class="sk-block sk-td narrow"></div>
                    <div class="sk-block sk-td wide"></div>
                    <div class="sk-block sk-td"></div>
                    <div class="sk-block sk-td"></div>
                    <div class="sk-block sk-td"></div>
                    <div class="sk-block sk-td narrow"></div>
                </div>
            @endfor
        </div>
    </div>
</div>
