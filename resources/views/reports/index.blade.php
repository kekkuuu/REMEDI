@extends('layouts.app')

@section('title', 'Reports')

@section('content')
<style>
    .report-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }

    /* Each card carries its own accent through --report-accent: the icon
       tile, the top band and the hover glow all read from it, so a report
       is recognisable by colour before you read the title. */
    .report-card {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        min-height: 250px;
        padding: 30px 26px 26px;
        border-radius: 14px;
        border-color: #eef2f7;
        overflow: hidden;
        text-decoration: none;
        color: inherit;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04),
                    0 10px 22px -14px rgba(15, 23, 42, 0.16);
        transition: box-shadow .16s ease, transform .16s ease, border-color .16s ease;
    }

    .report-card::before {
        content: '';
        position: absolute;
        inset: 0 0 auto 0;
        height: 4px;
        background: var(--report-accent);
    }

    .report-card:hover {
        transform: translateY(-3px);
        border-color: #dbe3ec;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04),
                    0 16px 30px -16px var(--report-glow);
    }

    .report-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 46px;
        height: 46px;
        border-radius: 12px;
        background: var(--report-tint);
        color: var(--report-accent);
        font-size: 23px;
        margin-bottom: 16px;
    }

    .report-card h4 {
        font-family: 'Outfit', sans-serif;
        font-size: 18px;
        font-weight: 700;
        color: #1e293b;
        margin: 0 0 8px;
    }

    .report-card p {
        color: #64748b;
        font-size: 14px;
        line-height: 1.6;
        flex: 1;
        margin: 0 0 20px;
    }

    /* Reads as a link, becomes a filled button on hover — keeps three
       identical solid buttons from dominating the row. */
    .report-open {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 13.5px;
        font-weight: 600;
        color: var(--report-accent);
        background: var(--report-tint);
        border-radius: 8px;
        padding: 9px 16px;
        transition: background .16s ease, color .16s ease;
    }

    .report-card:hover .report-open {
        background: var(--report-accent);
        color: #fff;
    }
</style>

<div class="report-grid">
    <a href="{{ route('reports.sales') }}" class="card report-card"
       style="--report-accent:#3b82f6; --report-tint:#eff6ff; --report-glow:rgba(59,130,246,.35);">
        <span class="report-icon"><i class="ti ti-receipt-2" aria-hidden="true"></i></span>
        <h4>Sales Report</h4>
        <p>Transactions and revenue within a date range, broken down by day, cashier, and payment totals.</p>
        <span class="report-open">Open <i class="ti ti-arrow-right" aria-hidden="true"></i></span>
    </a>

    <a href="{{ route('reports.inventory') }}" class="card report-card"
       style="--report-accent:#14b8a6; --report-tint:#f0fdfa; --report-glow:rgba(20,184,166,.35);">
        <span class="report-icon"><i class="ti ti-packages" aria-hidden="true"></i></span>
        <h4>Inventory Report</h4>
        <p>Snapshot of current stock levels, total inventory value, low-stock items, and products nearing expiry.</p>
        <span class="report-open">Open <i class="ti ti-arrow-right" aria-hidden="true"></i></span>
    </a>

    <a href="{{ route('reports.analytics') }}" class="card report-card"
       style="--report-accent:#8b5cf6; --report-tint:#f5f3ff; --report-glow:rgba(139,92,246,.35);">
        <span class="report-icon"><i class="ti ti-chart-histogram" aria-hidden="true"></i></span>
        <h4>Analytics Report</h4>
        <p>Top-selling and slow-moving products, seasonal patterns, and overall sales trends over time.</p>
        <span class="report-open">Open <i class="ti ti-arrow-right" aria-hidden="true"></i></span>
    </a>
</div>
@endsection
