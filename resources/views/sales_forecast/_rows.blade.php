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
                <th>Next Month Units</th>
                <th>Next Month Revenue</th>
                <th>Trend</th>
                <th>Generated At</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($forecasts as $forecast)
                <tr>
                    <td>{{ $forecast->product_name ?? '—' }}</td>
                    <td>{{ $forecast->product_sku }}</td>
                    <td>{{ $forecast->category_name ?? '—' }}</td>
                    <td>{{ number_format($forecast->next_month_units) }}</td>
                    <td>₱{{ number_format($forecast->next_month_revenue, 2) }}</td>
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
