@extends('layouts.app')

@section('title', 'Stock Reports')

@section('content')
<style>
    .sr-tabs { display: inline-flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; }
    .sr-tab {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 14px; border-radius: 999px; font-size: 13px; font-weight: 500;
        color: #334155; background: var(--surface, #fff); border: 1px solid var(--line, #e5e7eb);
        text-decoration: none;
    }
    .sr-tab:hover { background: #f1f5f9; }
    .sr-tab.is-active { background: var(--brand, #10b981); border-color: var(--brand, #10b981); color: #fff; }
    .sr-tab .sr-count { font-size: 11.5px; padding: 1px 7px; border-radius: 999px; background: rgba(15, 23, 42, .07); }
    .sr-tab.is-active .sr-count { background: rgba(255, 255, 255, .25); }
    .sr-product { font-weight: 600; color: var(--ink, #0f172a); line-height: 1.3; }
    .sr-sub { font-size: 11.5px; color: var(--ink-soft, #64748b); }
    .sr-note { max-width: 260px; white-space: normal; color: #475569; font-size: 12.5px; }
</style>

<div class="page-head">
    <div class="page-head-text">
        <h3><i class="ti ti-bell-ringing" style="font-size:18px;vertical-align:-2px;margin-right:7px;"></i>Stock Reports</h3>
        <p>
            @if(auth()->user()->isAdmin())
                Low or expired stock reported by staff. Approve to authorise the fix: reorder it, or pull the expired units.
            @else
                What you have reported to the admin, and their answer. Report a product from its row in Inventory.
            @endif
        </p>
    </div>
</div>

@php
    $tabs = ['' => 'All'] + \App\Models\StockReport::STATUSES;
    $total = $counts->sum();
@endphp
<nav class="sr-tabs" aria-label="Filter by status">
    @foreach($tabs as $key => $label)
        <a href="{{ route('stock-reports.index', $key ? ['status' => $key] : []) }}"
           class="sr-tab {{ (string) $status === (string) $key ? 'is-active' : '' }}"
           @if((string) $status === (string) $key) aria-current="true" @endif>
            {{ $label }} <span class="sr-count">{{ number_format($key ? ($counts[$key] ?? 0) : $total) }}</span>
        </a>
    @endforeach
</nav>

<div class="card" style="padding:0;">
    <div class="table-scroll"><table class="remedi-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Problem</th>
                <th>Stock now</th>
                <th>Reported</th>
                <th>Note</th>
                <th class="col-status">Status</th>
                @if(auth()->user()->isAdmin())
                    <th class="col-actions">Actions</th>
                @endif
            </tr>
        </thead>
        <tbody>
        @forelse($reports as $report)
            @php $product = $report->product; @endphp
            <tr id="stock-report-{{ $report->id }}">
                <td>
                    <div class="sr-product">{{ $product->name ?? 'Deleted product' }}</div>
                    <div class="sr-sub">SKU {{ $product->sku ?? '—' }}@if($product?->trashed()) · archived @endif</div>
                </td>
                <td>
                    <span class="badge {{ $report->type === \App\Models\StockReport::TYPE_EXPIRED ? 'badge-critical' : 'badge-danger' }}">
                        {{ $report->type_label }}
                    </span>
                </td>
                <td>
                    {{-- Read live, not stored: the admin decides on the shelf
                         as it is now, which may have moved since the report. --}}
                    @if($product)
                        {{ number_format($product->sellable_stock) }} sellable
                        <div class="sr-sub">{{ number_format($product->total_stock) }} on shelf · reorder at {{ number_format($product->reorder_level) }}</div>
                    @else
                        —
                    @endif
                </td>
                <td>
                    {{ $report->reporter->name ?? 'Unknown' }}
                    <div class="sr-sub">{{ $report->created_at->format('M d, Y g:i A') }}</div>
                </td>
                <td class="sr-note">{{ $report->note ?: '—' }}</td>
                <td class="col-status">
                    <span class="badge {{ $report->status === 'approved' ? 'badge-success' : ($report->status === 'rejected' ? 'badge-danger' : 'badge-warning') }}">
                        {{ $report->status_label }}
                    </span>
                    @if($report->reviewed_at)
                        <div class="sr-sub" style="margin-top:4px;">
                            by {{ $report->reviewer->name ?? 'an admin' }} · {{ $report->reviewed_at->format('M d, g:i A') }}
                        </div>
                    @endif
                </td>
                @if(auth()->user()->isAdmin())
                    <td class="col-actions">
                        <div class="actions-cell">
                            @if($report->isPending())
                                <form method="POST" action="{{ route('stock-reports.approve', $report) }}"
                                      class="js-confirm"
                                      data-confirm-title="Approve this report?"
                                      data-confirm-body="{{ $report->type_label }} — {{ $product->name ?? 'this product' }}. {{ $report->type === 'expired' ? 'Approving authorises pulling the expired units.' : 'Approving authorises a reorder.' }} {{ $report->reporter->name ?? 'The reporter' }} will see it as approved. Stock is not changed by this; do that from Manage."
                                      data-confirm-label="Approve"
                                      data-confirm-icon="ti-circle-check"
                                      data-confirm-tone="neutral">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-success btn-sm"><i class="ti ti-circle-check" aria-hidden="true"></i> Approve</button>
                                </form>
                                <form method="POST" action="{{ route('stock-reports.reject', $report) }}"
                                      class="js-confirm"
                                      data-confirm-title="Reject this report?"
                                      data-confirm-body="{{ $report->type_label }} — {{ $product->name ?? 'this product' }}. {{ $report->reporter->name ?? 'The reporter' }} will see it as rejected."
                                      data-confirm-label="Reject"
                                      data-confirm-icon="ti-circle-x"
                                      data-confirm-tone="neutral">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-danger btn-sm"><i class="ti ti-circle-x" aria-hidden="true"></i> Reject</button>
                                </form>
                            @endif
                            @if($product && ! $product->trashed())
                                <a href="{{ route('products.edit', $product) }}" class="btn btn-info btn-sm"><i class="ti ti-pencil" aria-hidden="true"></i> Manage</a>
                            @endif
                        </div>
                    </td>
                @endif
            </tr>
        @empty
            <tr>
                <td colspan="{{ auth()->user()->isAdmin() ? 7 : 6 }}" style="text-align:center;color:#94a3b8;padding:28px;">
                    @if(auth()->user()->isAdmin())
                        No stock reports{{ $status ? ' with this status' : '' }}.
                    @else
                        You have not reported anything{{ $status ? ' with this status' : '' }}. Use "Notify admin" on a low or expired product in Inventory.
                    @endif
                </td>
            </tr>
        @endforelse
        </tbody>
    </table></div>
</div>

<div style="margin-top:14px;">{{ $reports->links() }}</div>
@endsection
