@extends('layouts.app')

@section('title', 'Stock Card')

@section('content')
<div class="page-head">
    <a href="{{ route('products.edit', $product) }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <span class="form-chip" aria-hidden="true"><i class="ti ti-list-details"></i></span>
    <div class="page-head-text is-record">
        <h3>{{ $product->name }}</h3>
        <p>SKU {{ $product->sku }} &middot; Stock Card</p>
    </div>
</div>

{{-- Every unit of stock movement for this product -- stock-in, sale, return,
     manual adjustment, pull-out -- in one chronological list. This is the
     thing to open when a physical count does not match what the system
     says: read the ledger for the period in question rather than piecing it
     together from batches, sale_items and the audit trail separately. --}}
<div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); margin-bottom:20px;">
    <div class="kpi" style="--kpi-accent:#0d9488;">
        <div class="kpi-head">
            <i class="ti ti-stack-2" aria-hidden="true"></i>
            <span class="kpi-label">Current Stock</span>
        </div>
        <span class="kpi-value">{{ number_format($currentTotal) }}</span>
        <span class="kpi-sub">{{ $product->unit }} on hand, all batches</span>
    </div>
    <div class="kpi" style="--kpi-accent:#3b82f6;">
        <div class="kpi-head">
            <i class="ti ti-list-numbers" aria-hidden="true"></i>
            <span class="kpi-label">Ledger Entries</span>
        </div>
        <span class="kpi-value">{{ number_format($movements->total()) }}</span>
        <span class="kpi-sub">matching the filters below</span>
    </div>
</div>

<div class="form-card" style="margin-bottom:20px;">
    <form method="GET" action="{{ route('products.stock-card', $product) }}" class="report-filters">
        <div class="report-field">
            <label for="type">Movement Type</label>
            <select name="type" id="type" class="report-select" onchange="this.form.submit()">
                <option value="">All types</option>
                @foreach($types as $type)
                    <option value="{{ $type }}" {{ $filterType === $type ? 'selected' : '' }}>
                        {{ ucfirst(str_replace('_', ' ', $type)) }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="report-field">
            <label for="start_date">From</label>
            <input type="date" name="start_date" id="start_date" class="report-select" value="{{ $startDate }}" max="{{ now()->toDateString() }}">
        </div>
        <div class="report-field">
            <label for="end_date">To</label>
            <input type="date" name="end_date" id="end_date" class="report-select" value="{{ $endDate }}" max="{{ now()->toDateString() }}">
        </div>
        <div class="report-field" style="align-self:flex-end;">
            <button type="submit" class="btn btn-primary">Filter</button>
            @if($filterType || $startDate || $endDate)
                <a href="{{ route('products.stock-card', $product) }}" class="btn btn-secondary">Clear</a>
            @endif
        </div>
    </form>
</div>

@if($movements->isEmpty() && ! $filterType && ! $startDate && ! $endDate && $currentTotal > 0)
    <p style="font-size:12.5px;color:#94a3b8;margin:0 0 10px;">
        This product has stock but no ledger history yet — the stock card only
        records movements going forward from when it was added; batches added,
        sold or adjusted before that are not retroactively logged here.
    </p>
@endif
<div style="border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;">
    <div class="table-scroll"><table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
            <tr style="background:#f9fafb;">
                <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Date</th>
                <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Type</th>
                <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Batch</th>
                <th style="width:90px;padding:9px 14px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Change</th>
                <th style="width:100px;padding:9px 14px;text-align:right;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Batch Balance</th>
                <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">Reason / Reference</th>
                <th style="padding:9px 14px;text-align:left;font-size:11px;font-weight:500;color:#6b7280;text-transform:uppercase;letter-spacing:0.04em;border-bottom:0.5px solid #e5e7eb;">User</th>
            </tr>
        </thead>
        <tbody>
            @php
                $typeMeta = [
                    'stock_in' => ['label' => 'Stock In', 'color' => '#16a34a', 'bg' => '#f0fdf4'],
                    'sale' => ['label' => 'Sale', 'color' => '#2563eb', 'bg' => '#eff6ff'],
                    'return' => ['label' => 'Return', 'color' => '#7c3aed', 'bg' => '#f5f3ff'],
                    'adjustment' => ['label' => 'Adjustment', 'color' => '#d97706', 'bg' => '#fffbeb'],
                    'pull_out' => ['label' => 'Pull-Out', 'color' => '#dc2626', 'bg' => '#fef2f2'],
                    'void' => ['label' => 'Void', 'color' => '#0891b2', 'bg' => '#ecfeff'],
                ];
            @endphp
            @forelse($movements as $m)
                @php $meta = $typeMeta[$m->type] ?? ['label' => ucfirst($m->type), 'color' => '#6b7280', 'bg' => '#f9fafb']; @endphp
                <tr style="border-bottom:0.5px solid #e5e7eb;">
                    <td style="padding:10px 14px;color:#374151;white-space:nowrap;">{{ $m->created_at->format('M d, Y g:i A') }}</td>
                    <td style="padding:10px 14px;">
                        <span style="font-size:11px;font-weight:500;padding:3px 9px;border-radius:20px;background:{{ $meta['bg'] }};color:{{ $meta['color'] }};">
                            {{ $meta['label'] }}
                        </span>
                    </td>
                    <td style="padding:10px 14px;color:#6b7280;">{{ $m->batch?->batch_number ?? '—' }}</td>
                    <td style="padding:10px 14px;text-align:right;font-weight:600;color:{{ $m->quantity_change >= 0 ? '#16a34a' : '#dc2626' }};">
                        {{ $m->quantity_change >= 0 ? '+' : '' }}{{ number_format($m->quantity_change) }}
                    </td>
                    <td style="padding:10px 14px;text-align:right;color:#374151;">{{ number_format($m->balance_after) }}</td>
                    <td style="padding:10px 14px;color:#6b7280;">
                        @if($m->sale)
                            <a href="{{ route('sales.show', $m->sale) }}">{{ $m->sale->transaction_no }}</a>
                        @elseif($m->reason)
                            {{ $m->reason }}
                        @else
                            <span style="color:#cbd5e1;">—</span>
                        @endif
                    </td>
                    <td style="padding:10px 14px;color:#6b7280;">{{ $m->performedBy->name ?? 'System' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" style="padding:32px;text-align:center;color:#9ca3af;font-size:13px;">
                        <i class="ti ti-list-details" style="font-size:24px;display:block;margin-bottom:6px;"></i>
                        No stock movements match these filters.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table></div>
    @if($movements->hasPages())
        <div style="padding:14px 16px;border-top:0.5px solid #e5e7eb;">
            {{ $movements->links() }}
        </div>
    @endif
</div>
@endsection
