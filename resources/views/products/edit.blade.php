@extends('layouts.app')

@section('title', 'Edit Product')

@section('content')
{{-- Back sits beside the title, the shared .page-head pattern (see the styles
     in layouts/app.blade.php).

     Deliberately a fixed target, not url()->previous(): after a failed
     validation redirect the previous URL is this same edit page, so a "back"
     built on it would link to itself. --}}
<div class="page-head">
    <a href="{{ route('products.index') }}" class="btn-back"><i class="ti ti-arrow-left" aria-hidden="true"></i> Back</a>
    <span class="form-chip" aria-hidden="true"><i class="ti ti-tag"></i></span>
    <div class="page-head-text is-record">
        <h3>{{ $product->name }}</h3>
        <p>SKU {{ $product->sku }}</p>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('products.stock-card', $product) }}" class="btn btn-secondary">
            <i class="ti ti-list-details" aria-hidden="true"></i> Stock Card
        </a>
        <a href="{{ route('barcodes.index', ['product' => $product->id]) }}" class="btn btn-secondary">
            <i class="ti ti-barcode" aria-hidden="true"></i> Print Barcode
        </a>
    </div>
</div>

{{-- Same fields and values as before; only the presentation changed. The
     per-field chips the Add pages use are dropped here on purpose: the card
     header already carries one, and six of them in a single row reads as a
     wall of icons. --}}
<div class="form-card" style="margin-bottom:20px;">
    <div class="section-head">
        <h4>Product Details</h4>
    </div>

    <form method="POST" action="{{ route('products.update', $product) }}"
          novalidate data-inline-errors
          class="js-confirm" data-confirm-tone="neutral" data-confirm-icon="ti-device-floppy"
          data-confirm-title="Save changes?" data-confirm-body="Update this product details."
          data-confirm-label="Save changes">
        @csrf
        @method('PUT')

        {{-- .form-grid / .form-field / .form-field-head: the chip belongs to the
             LABEL and the input sits underneath it, full width. This card used
             .field-aside, which put the chip in its own column beside the whole
             field, so it read as an ornament on the input instead of a mark on
             the label -- and it did not match Add Product, which has always
             used this pattern. --}}
        <div class="form-grid">
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-bottle"></i></span>
                    <label for="name">Product Name</label>
                </div>
                <input type="text" id="name" name="name" value="{{ old('name', $product->name) }}" required data-label="product name" maxlength="255" aria-describedby="name-error" @error('name') aria-invalid="true" @enderror>
                @include('products._field-error', ['field' => 'name'])
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-barcode"></i></span>
                    <label for="sku">SKU / Barcode</label>
                </div>
                {{-- One code per product (2026-10-22): the SKU IS the barcode the
                     till scans, saved to both columns. Changing it re-points every
                     table keyed on the SKU (ProductController::update). --}}
                <input type="text" id="sku" name="sku" value="{{ old('sku', $product->sku) }}" required data-label="SKU / barcode" maxlength="50" autocomplete="off" data-scan-field aria-describedby="sku-error" @error('sku') aria-invalid="true" @enderror>
                @include('products._field-error', ['field' => 'sku'])
                <span class="form-field-note">
                    <i class="ti ti-info-circle" aria-hidden="true"></i>
                    The barcode printed on the box. Changing it keeps the product's sales history and forecasts.
                </span>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-category"></i></span>
                    <label for="category_id">Category</label>
                </div>
                <select id="category_id" name="category_id" required data-label="category" aria-describedby="category_id-error" @error('category_id') aria-invalid="true" @enderror>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id', $product->category_id) == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                    @endforeach
                </select>
                @include('products._field-error', ['field' => 'category_id'])
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-box"></i></span>
                    <label for="unit">Unit</label>
                </div>
                {{-- unitOptions($product->unit) keeps a legacy value selectable,
                     so editing anything else does not rewrite it. --}}
                <select id="unit" name="unit" required data-label="unit" aria-describedby="unit-error" @error('unit') aria-invalid="true" @enderror>
                    @foreach(\App\Models\Product::unitOptions($product->unit) as $unitOption)
                        <option value="{{ $unitOption }}" {{ old('unit', $product->unit) === $unitOption ? 'selected' : '' }}>{{ $unitOption }}</option>
                    @endforeach
                </select>
                @include('products._field-error', ['field' => 'unit'])
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-coin"></i></span>
                    <label for="selling_price">Selling Price (&#8369;)</label>
                </div>
                <input type="number" step="0.01" id="selling_price" name="selling_price" value="{{ old('selling_price', $product->selling_price) }}" required data-label="selling price" min="0.01" max="{{ \App\Http\Controllers\Controller::MAX_MONEY }}" aria-describedby="selling_price-error" @error('selling_price') aria-invalid="true" @enderror>
                @include('products._field-error', ['field' => 'selling_price'])
            </div>

            {{-- "Default", not bare "Cost Price": the Add New Batch card below
                 has its OWN "Cost Price (optional)" (ProductBatch::unit_cost,
                 what a specific delivery actually cost), and the two sitting
                 on one page under the same label would leave an admin unable
                 to tell which one they're editing. This one is the product-
                 level figure the dashboard's Profit Today tile reads
                 (DashboardController::computeTodayProfit()); a batch's own
                 unit_cost is purchase-history/traceability and nothing
                 downstream computes profit from it. Optional, like
                 unit_cost -- see that field's own note. --}}
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-receipt-refund"></i></span>
                    <label for="cost_price">Default Cost Price (&#8369;)</label>
                </div>
                <input type="number" step="0.01" id="cost_price" name="cost_price" value="{{ old('cost_price', $product->cost_price) }}" data-label="default cost price" min="0" max="{{ \App\Http\Controllers\Controller::MAX_MONEY }}" aria-describedby="cost_price-error" @error('cost_price') aria-invalid="true" @enderror>
                @include('products._field-error', ['field' => 'cost_price'])
                <span class="form-field-note">
                    <i class="ti ti-info-circle" aria-hidden="true"></i>
                    Optional. Used to work out today's profit on the dashboard -- separate from a batch's own delivery cost below.
                </span>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-alert-triangle"></i></span>
                    <label for="reorder_level">Reorder Level (Low Stock Threshold)</label>
                </div>
                <input type="number" id="reorder_level" name="reorder_level" value="{{ old('reorder_level', $product->reorder_level) }}" required data-label="reorder level" min="0" max="{{ \App\Http\Controllers\Controller::MAX_COUNT }}" step="1" aria-describedby="reorder_level-error" @error('reorder_level') aria-invalid="true" @enderror>
                @include('products._field-error', ['field' => 'reorder_level'])
                <span class="form-field-note">
                    <i class="ti ti-info-circle" aria-hidden="true"></i>
                    Alerts fire when sellable stock falls to this level.
                </span>
            </div>

        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ti ti-check" aria-hidden="true"></i> Update Product
            </button>
            <a href="{{ route('products.index') }}" class="btn btn-secondary btn-lg">
                <i class="ti ti-x" aria-hidden="true"></i> Cancel
            </a>
        </div>
    </form>
</div>

{{-- A product arrives here straight from Add Product (ProductController::
     store, `added=1`). It has no batches yet, so every list already shows it
     as Low Stock / Out of Stock -- correct, but the next step is not obvious
     from there. This says so and points at the card below; the link jumps to
     #add-batch and the script focuses its first field. --}}
@if(request()->boolean('added') && $product->batches()->doesntExist())
    <div class="next-step" role="status">
        <i class="ti ti-circle-check" aria-hidden="true"></i>
        <div>
            <strong>Product added.</strong>
            It has no stock yet, so it shows as Out of Stock until its first batch is in.
            <a href="#add-batch" class="next-step-link">Add the first batch now <i class="ti ti-arrow-down" aria-hidden="true"></i></a>
        </div>
    </div>
@endif

<div class="form-card" id="add-batch" style="margin-bottom:20px; scroll-margin-top:90px;">
    <div class="section-head">
        <h4>Add New Batch</h4>
    </div>

    <form method="POST" action="{{ route('products.batches.store', $product) }}">
        @csrf
        {{-- Every field repopulates from old(). addBatch() answers a failed
             validation with back()->withErrors(), so without this a typo in one
             date threw away the batch number and quantity already typed. --}}
        {{-- Both dates start EMPTY, so each shows the mm/dd/yyyy placeholder
             the layout's date-placeholder script puts on an empty date field.
             They used to be prefilled -- received = today, expiry = this
             product's usual shelf life (ProductController::edit still computes
             $suggestedExpiryDate, so restoring either is a one-line change) --
             but a prefilled field has a VALUE, and a value is not a
             placeholder: it showed a date where the format was wanted. Both
             come off the same delivery document anyway, and an expiry date
             accepted by accident because it was already in the box is the one
             mistake on this form that reaches the shelf. --}}
        <div class="form-grid cols-3">
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-hash"></i></span>
                    <label for="batch_number">Batch Number</label>
                </div>
                {{-- TYPEABLE (2026-10-22, at the user's request; it was
                     readonly). Picking a received date fills in the generated
                     number as a suggestion; typing replaces it -- a supplier's
                     own lot number, say -- and is kept. Left as suggested, or
                     emptied, the server assigns the number itself
                     (ProductController::addBatch), so two people adding a batch
                     the same day still get -01 and -02. --}}
                <input type="text" id="batch_number" name="batch_number" value="{{ old('batch_number') }}"
                       maxlength="100" autocomplete="off" aria-describedby="batch_number-error"
                       placeholder="Type a lot number, or pick the received date"
                       data-batch-auto
                       data-batch-code="{{ \App\Models\ProductBatch::batchNameCode($product) }}"
                       data-batch-pad="{{ \App\Models\ProductBatch::BATCH_SEQUENCE_PAD }}"
                       data-batch-taken="{{ json_encode($batchSequences) }}">
                @error('batch_number')
                    <p id="batch_number-error" class="batch-number-hint" style="color:#b91c1c;">{{ $message }}</p>
                @enderror
                <p class="batch-number-hint">
                    Type the supplier's lot number, or leave it blank to get one automatically:
                    {{ \App\Models\ProductBatch::batchNameCode($product) }} (from the product name), the received date, and a number for that day.
                </p>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-stack-2"></i></span>
                    <label for="quantity">Quantity</label>
                </div>
                <input type="number" id="quantity" name="quantity" value="{{ old('quantity') }}"
                       min="1" placeholder="Enter quantity" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-calendar"></i></span>
                    <label for="received_date">Received Date</label>
                </div>
                {{-- max=today: a delivery cannot have arrived on a day that has
                     not happened. addBatch() enforces it as well. --}}
                <input type="date" id="received_date" name="received_date" value="{{ old('received_date') }}"
                       max="{{ now()->toDateString() }}" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-calendar-event"></i></span>
                    <label for="expiry_date">Expiry Date</label>
                </div>
                {{-- min=tomorrow, matching the after:today the endpoint applies:
                     a batch that expires today is already expired, and the till
                     may not sell it (ProductBatch::is_expired counts the expiry
                     date itself as expired). --}}
                <input type="date" id="expiry_date" name="expiry_date" value="{{ old('expiry_date') }}"
                       min="{{ now()->addDay()->toDateString() }}" required>
            </div>

        </div>

        {{-- Cost, DRP / FDA registration number and supplier — all optional,
             and all filled in through THIS form and no other:
             ProductController::addBatch is the single write path for a new
             batch, so there is nowhere else stock-in details can be entered.
             (The column is still `dr_no`: it was labelled DR Number, a delivery
             receipt, until 2026-10-22, when the user asked for it to hold the
             DRP / FDA registration number instead.) Leaving these blank is fine;
             cost-of-goods and traceability reporting simply have nothing to
             show for a batch that skipped them. --}}
        <div class="form-grid cols-3">
            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-currency-peso"></i></span>
                    <label for="unit_cost">Cost Price <span style="font-weight:400; color:#94a3b8;">(optional)</span></label>
                </div>
                <input type="number" id="unit_cost" name="unit_cost" value="{{ old('unit_cost') }}"
                       min="0" step="0.01" placeholder="Purchase price per unit">
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-file-invoice"></i></span>
                    <label for="dr_no">DRP / FDA Reg. No. <span style="font-weight:400; color:#94a3b8;">(optional)</span></label>
                </div>
                <input type="text" id="dr_no" name="dr_no" value="{{ old('dr_no') }}"
                       maxlength="100" placeholder="e.g. DRP-1234 or FR-4000001234">
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-truck-delivery"></i></span>
                    <label for="supplier">Supplier <span style="font-weight:400; color:#94a3b8;">(optional)</span></label>
                </div>
                <input type="text" id="supplier" name="supplier" value="{{ old('supplier') }}"
                       maxlength="150" placeholder="Who this batch was received from">
            </div>
        </div>

        {{-- Its own actions row, below the fields, exactly where Update
             Product sits. It used to be a fifth cell in the grid with an
             invisible chip holding its column open, which left it floating
             mid-form and reflowed to a different place at every breakpoint. --}}
        <div class="form-actions">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="ti ti-plus" aria-hidden="true"></i> Add Batch
            </button>
        </div>
    </form>
</div>

@php
    $existingBatches = $product->batches()->orderBy('expiry_date')->get();
@endphp
<div class="form-card">
    {{-- Always open. This used to be a collapsed <details>, which hid the one
         thing the page is actually for: stock lives on batches, not on the
         product, so expiry, quantities and the return actions were all behind
         a disclosure the user had to know to click. The table scrolls inside
         .table-scroll, so a long batch history costs width, not page height. --}}
    <div class="section-head">
        <h4>Existing Batches ({{ $existingBatches->count() }})</h4>
    </div>
        <div>
    <div class="table-scroll"><table class="remedi-table">
        <thead>
            <tr><th>Batch No.</th><th>Quantity</th><th>Cost / DRP–FDA Reg. No. / Supplier</th><th>Received</th><th>Expiry</th><th>Status</th><th>Return Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        @forelse($existingBatches as $batch)
            <tr>
                <td>{{ $batch->batch_number }}</td>
                <td>
                    {{-- Reason is REQUIRED server-side only when the quantity
                         actually changes (ProductController::updateBatch) — so
                         leaving it blank while only nudging other fields
                         through the second form below still works. --}}
                    {{-- A returned batch went back to the supplier; its quantity
                         is not editable (updateBatch refuses it too). --}}
                    @if($batch->returned_at)
                        <span>{{ number_format($batch->quantity) }}</span>
                    @else
                    <form method="POST" action="{{ route('batches.update', $batch) }}" style="display:flex; gap:4px; align-items:center; flex-wrap:wrap;">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="expected_quantity" value="{{ $batch->quantity }}">
                        <input type="number" name="quantity" value="{{ $batch->quantity }}" min="0" style="width:70px; padding:4px; border:1px solid #d1d5db; border-radius:4px;">
                        <input type="hidden" name="expiry_date" value="{{ $batch->expiry_date?->format('Y-m-d') }}">
                        <input type="hidden" name="unit_cost" value="{{ $batch->unit_cost }}">
                        <input type="hidden" name="dr_no" value="{{ $batch->dr_no }}">
                        <input type="hidden" name="supplier" value="{{ $batch->supplier }}">
                        <input type="text" name="reason" placeholder="Reason if changing qty" style="width:130px; padding:4px; border:1px solid #d1d5db; border-radius:4px; font-size:12px;">
                        <button type="submit" class="btn btn-info" style="padding:4px 8px;">Update</button>
                    </form>
                    @endif
                </td>
                <td>
                    {{-- Same route, this form's own hidden quantity/expiry keep
                         the OTHER form's fields untouched — quantity posted
                         unchanged means no stock movement is recorded and no
                         reason is required. --}}
                    <form method="POST" action="{{ route('batches.update', $batch) }}" style="display:flex; flex-direction:column; gap:3px;">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="expected_quantity" value="{{ $batch->quantity }}">
                        <input type="hidden" name="quantity" value="{{ $batch->quantity }}">
                        <input type="hidden" name="expiry_date" value="{{ $batch->expiry_date?->format('Y-m-d') }}">
                        <input type="number" name="unit_cost" value="{{ $batch->unit_cost }}" min="0" step="0.01" placeholder="Cost" style="width:90px; padding:3px; border:1px solid #d1d5db; border-radius:4px; font-size:12px;">
                        <input type="text" name="dr_no" value="{{ $batch->dr_no }}" maxlength="100" placeholder="DRP / FDA Reg." aria-label="DRP / FDA Reg. No." style="width:110px; padding:3px; border:1px solid #d1d5db; border-radius:4px; font-size:12px;">
                        <input type="text" name="supplier" value="{{ $batch->supplier }}" maxlength="150" placeholder="Supplier" style="width:110px; padding:3px; border:1px solid #d1d5db; border-radius:4px; font-size:12px;">
                        <button type="submit" class="btn btn-info" style="padding:3px 8px; font-size:12px;">Save</button>
                    </form>
                </td>
                <td>{{ $batch->received_date?->format('M d, Y') ?? '—' }}</td>
                <td>{{ $batch->expiry_date?->format('M d, Y') ?? '—' }}</td>
                <td>
                    @if($batch->is_expired)
                        <span class="badge badge-danger">Expired</span>
                    @elseif($batch->is_expiring_soon)
                        <span class="badge badge-warning">Expiring Soon</span>
                    @else
                        <span class="badge badge-success">Good</span>
                    @endif
                </td>
                <td>
                    @if($product->is_medicine)
                        @if($batch->is_returned)
                            <span class="badge badge-return-done" title="Returned {{ $batch->returned_at->format('M d, Y') }}{{ $batch->returnedBy ? ' by ' . $batch->returnedBy->name : '' }}">Successfully Returned</span>
                        @elseif($batch->needs_return)
                            <span class="badge badge-return-due" title="90-120 days left before expiry">Need to Return &middot; {{ $batch->return_days_label }}</span>
                        @elseif($batch->failed_return)
                            <span class="badge badge-return-late" title="Fewer than 90 days left before expiry, or already expired">Fail to Return &middot; {{ $batch->return_days_label }}</span>
                        @else
                            <span style="color:#94a3b8; font-size:12px;">—</span>
                        @endif
                    @else
                        <span style="color:#94a3b8; font-size:12px;">N/A</span>
                    @endif
                </td>
                {{-- flex goes on an inner wrapper, never the <td>:
                     display:flex on a table cell drops it out of the table's
                     column model and the row stops lining up with its header. --}}
                <td>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                    @if($batch->is_returnable)
                        <form method="POST" action="{{ route('batches.return', $batch) }}" style="margin:0;"
                              class="js-confirm"
                              data-confirm-title="Mark this batch as returned?"
                              data-confirm-body="Record batch {{ $batch->batch_number }} as sent back to the supplier. It stops counting as sellable stock."
                              data-confirm-label="Mark Returned"
                              data-confirm-icon="ti-package-export"
                              data-confirm-tone="neutral">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-success" style="padding:4px 8px;">Mark Returned</button>
                        </form>
                    @endif
                    {{-- Archive, not remove: the batch leaves the shelf, the
                         alerts and the stock totals, but the row stays, so a past
                         sale that drew on it still resolves. It can be restored
                         from "Archived batches" below. --}}
                    <form method="POST" action="{{ route('batches.destroy', $batch) }}" style="margin:0;"
                          class="js-confirm"
                          data-confirm-title="Archive this batch?"
                          data-confirm-body="Batch {{ $batch->batch_number }} and its {{ $batch->quantity }} remaining units will come off the shelf, the alerts and the stock totals. Past sales that used it are kept, and you can restore it from Archived batches."
                          data-confirm-label="Archive"
                          data-confirm-icon="ti-archive"
                          data-confirm-tone="neutral"
                          data-on-success="remove-row">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-warning" style="padding:4px 8px;">Archive</button>
                    </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="8">No batches yet. Add one above.</td></tr>
        @endforelse
        </tbody>
    </table></div>

    @if($archivedBatches->isNotEmpty())
        <h4 style="margin:22px 0 6px; font-size:14px; font-weight:600;">
            <i class="ti ti-archive" aria-hidden="true"></i> Archived batches
        </h4>
        <p style="margin:0 0 10px; font-size:13px; color:#64748b;">Off the shelf and out of the stock totals. Restore one to put it back.</p>
        <div class="table-scroll"><table class="remedi-table">
            <thead>
                <tr><th>Batch No.</th><th>Qty</th><th>Expiry</th><th>Archived</th><th></th></tr>
            </thead>
            <tbody>
            @foreach($archivedBatches as $batch)
                <tr>
                    <td>{{ $batch->batch_number }}</td>
                    <td>{{ $batch->quantity }}</td>
                    <td>{{ $batch->expiry_date?->format('M d, Y') ?? '—' }}</td>
                    <td>{{ $batch->archived_at->format('M d, Y') }}</td>
                    <td>
                        <form method="POST" action="{{ route('batches.restore', $batch) }}" style="margin:0;"
                              class="js-confirm"
                              data-confirm-title="Restore this batch?"
                              data-confirm-body="Batch {{ $batch->batch_number }} goes back on the shelf with its {{ $batch->quantity }} units."
                              data-confirm-label="Restore"
                              data-confirm-icon="ti-archive-off"
                              data-confirm-tone="neutral"
                              data-on-success="reload">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-success" style="padding:4px 8px;">Restore</button>
                        </form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
        </div>
</div>

<style>
    .batch-number-hint { margin: 6px 0 0; font-size: 12px; color: var(--ink-soft); }

</style>

<script>
/* Arriving from Add Product (#add-batch), or following the callout's link:
 * put the cursor in Quantity, the first field the admin types. Not the
 * received date -- focusing a date field opens its picker (the layout's
 * date-placeholder script), which nobody asked for on page load. */
(function () {
    function focusBatch() {
        var qty = document.getElementById('quantity');
        if (qty) qty.focus({ preventScroll: true });
    }
    if (location.hash === '#add-batch') focusBatch();
    document.querySelectorAll('.next-step-link').forEach(function (a) {
        a.addEventListener('click', function () { setTimeout(focusBatch, 0); });
    });
})();

/* The batch number follows the received date.
 *
 * This is a DISPLAY of ProductBatch::nextBatchNumber(), not a second copy of
 * it: the server derives the same value from the same prefix and padding when
 * the field arrives empty, and it is the server's answer that gets stored. The
 * point of doing it here as well is that the number should be visible before
 * you commit to it, rather than appearing for the first time in the batch table
 * underneath.
 *
 * Two things it must not do:
 *
 *  - Overwrite a number the user typed. A supplier's own lot number is a better
 *    batch number than a generated one, so the field stays editable and this
 *    only writes while what is in the box is still its own suggestion.
 *  - Assume the field is an <input type="date">. The layout's date-placeholder
 *    script holds an EMPTY date input as type="text" and flips it on focus, so
 *    the element changes type under us -- hence the delegated listeners on the
 *    form rather than direct ones on the input.
 */
(function () {
    var form = document.getElementById('batch_number');
    form = form && form.form;
    if (!form) return;

    var field = form.querySelector('[data-batch-auto]');
    var date = form.querySelector('[name="received_date"]');
    if (!field || !date) return;

    var code = field.getAttribute('data-batch-code') || '';
    var pad = parseInt(field.getAttribute('data-batch-pad'), 10) || 2;
    var taken = {};
    try { taken = JSON.parse(field.getAttribute('data-batch-taken') || '{}') || {}; } catch (e) { taken = {}; }

    function suggestion() {
        var value = (date.value || '').trim();          // Y-m-d, or '' while empty
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return '';

        var day = value.replace(/-/g, '');
        var next = String((taken[day] || 0) + 1);
        while (next.length < pad) next = '0' + next;

        return code + '-' + day + '-' + next;
    }

    // Only while the box still holds our own suggestion (or nothing): a
    // number the user typed is theirs and is never overwritten.
    var lastSuggested = field.value;
    function sync() {
        if (field.value !== '' && field.value !== lastSuggested) return;
        lastSuggested = suggestion();
        field.value = lastSuggested;
    }

    form.addEventListener('change', function (e) { if (e.target === date) sync(); });
    form.addEventListener('input', function (e) { if (e.target === date) sync(); });

    sync();
})();
</script>
@endsection
