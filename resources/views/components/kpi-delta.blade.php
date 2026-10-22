{{-- Percentage change row under a KPI figure.

     Renders NOTHING when $change is null. That is the point: only the sales
     figures have a real baseline to compare against (the POS timestamps every
     checkout). The stock counters — low stock, expiring, expired, returns —
     are never snapshotted, so there is no yesterday to measure them against
     and a "vs yesterday" percentage there would be invented.

     A tile that DOES have a baseline in principle can pass an `empty-label` to
     say so when today's comparison happens to be against zero -- otherwise
     "vs yesterday" silently disappears on any day the till took nothing, which
     reads as a missing feature rather than an absent baseline.

     `empty-against` replaces the note in that case. "No sales · vs yesterday"
     read as if TODAY had no sales (2026-10-22); the missing figure is the
     baseline, so the empty label names it ("No sales yesterday") and the note
     says why there is no percentage ("to compare"). --}}
@props(['change' => null, 'against' => '', 'emptyLabel' => null, 'emptyAgainst' => null])

@if($change === null && $emptyLabel)
    <span class="kpi-delta is-flat">
        <i class="ti ti-minus" aria-hidden="true"></i>
        {{ $emptyLabel }}
        <span class="kpi-delta-note">{{ $emptyAgainst ?? $against }}</span>
    </span>
@elseif($change !== null)
    @php $up = $change >= 0; @endphp
    <span class="kpi-delta {{ $up ? 'is-up' : 'is-down' }}">
        <i class="ti {{ $up ? 'ti-arrow-up-right' : 'ti-arrow-down-right' }}" aria-hidden="true"></i>
        {{ abs($change) }}%
        <span class="kpi-delta-note">{{ $against }}</span>
    </span>
@endif
