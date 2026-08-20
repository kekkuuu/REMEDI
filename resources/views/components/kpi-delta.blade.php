{{-- Percentage change row under a KPI figure.

     Renders NOTHING when $change is null. That is the point: only the sales
     figures have a real baseline to compare against (the POS timestamps every
     checkout). The stock counters — low stock, expiring, expired, returns —
     are never snapshotted, so there is no yesterday to measure them against
     and a "vs yesterday" percentage there would be invented. --}}
@props(['change' => null, 'against' => ''])

@if($change !== null)
    @php $up = $change >= 0; @endphp
    <span class="kpi-delta {{ $up ? 'is-up' : 'is-down' }}">
        <i class="ti {{ $up ? 'ti-arrow-up-right' : 'ti-arrow-down-right' }}" aria-hidden="true"></i>
        {{ abs($change) }}%
        <span class="kpi-delta-note">{{ $against }}</span>
    </span>
@endif
