@if ($forecasts->isEmpty())
    <p id="empty-message" style="color:#64748b;">
        No forecasts found matching your search.
    </p>
@else
    <div class="table-scroll"><table class="remedi-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>SKU</th>
                <th>Category</th>
                <th>Forecast Qty</th>
                <th>Accuracy</th>
                <th>Trend</th>
                <th>Generated At</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($forecasts as $forecast)
                {{-- data-href, not an inline onclick. `onclick="window.location=..."`
                     fired on ANY click inside the row, including the one that ends
                     a text selection -- so selecting a SKU to copy it navigated you
                     off the page instead. It also swallowed ctrl/middle-click (no
                     opening in a new tab) and bypassed the layout's loading
                     skeleton. The delegated handler below applies the same guards
                     every other link in the app gets. --}}
                <tr class="clickable-row" data-href="{{ route('forecast.show', $forecast->product_sku) }}">
                    <td><a href="{{ route('forecast.show', $forecast->product_sku) }}">{{ $forecast->product_name ?? '—' }}</a></td>
                    <td>{{ $forecast->product_sku }}</td>
                    <td>{{ $forecast->category_name ?? '—' }}</td>
                    {{-- Say which month the figure is for. The value used to be
                         printed bare, so a stale row and next month's row looked
                         identical, and a forecast of 0.34 units rendered as a flat
                         "0" that read as "no forecast" rather than "less than one
                         unit". --}}
                    <td>
                        @php $qty = (float) $forecast->next_month_forecast; @endphp
                        <strong>{{ $qty > 0 && $qty < 0.5 ? '<1' : number_format($qty) }}</strong>
                        <div style="font-size:11px; color:{{ $forecast->is_stale ? '#b45309' : '#94a3b8' }};">
                            {{ $forecast->is_stale ? 'as of ' : '' }}{{ $forecast->forecast_date->format('M Y') }}
                        </div>
                    </td>
                    {{-- Verdict per row, so the list can be scanned for forecasts worth
                         trusting without opening each product. Same grader as the detail page. --}}
                    <td>
                        <span style="display:inline-block; padding:2px 9px; border-radius:999px; font-size:11px;
                                     font-weight:600; color:#fff; background:{{ $forecast->grade['colour'] }};"
                              title="{{ $forecast->grade['note'] }}">
                            {{ $forecast->grade['label'] }}
                        </span>
                    </td>
                    <td>
                        <canvas
                            class="sparkline"
                            width="120" height="36"
                            data-labels="{{ $forecast->trend_labels->toJson() }}"
                            data-values="{{ $forecast->trend_values->toJson() }}"
                        ></canvas>
                    </td>
                    <td>{{ $forecast->generated_at->format('Y-m-d H:i') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table></div>
@endif
