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
                <input type="text" id="name" name="name" value="{{ old('name', $product->name) }}" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-barcode"></i></span>
                    <label for="sku">SKU (Product Code)</label>
                </div>
                <input type="text" id="sku" name="sku" value="{{ old('sku', $product->sku) }}" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-category"></i></span>
                    <label for="category_id">Category</label>
                </div>
                <select id="category_id" name="category_id" required>
                    @foreach($categories as $cat)
                        <option value="{{ $cat->id }}" {{ old('category_id', $product->category_id) == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-box"></i></span>
                    <label for="unit">Unit</label>
                </div>
                {{-- unitOptions($product->unit) keeps a legacy value selectable,
                     so editing anything else does not rewrite it. --}}
                <select id="unit" name="unit" required>
                    @foreach(\App\Models\Product::unitOptions($product->unit) as $unitOption)
                        <option value="{{ $unitOption }}" {{ old('unit', $product->unit) === $unitOption ? 'selected' : '' }}>{{ $unitOption }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-coin"></i></span>
                    <label for="selling_price">Selling Price (&#8369;)</label>
                </div>
                <input type="number" step="0.01" id="selling_price" name="selling_price" value="{{ old('selling_price', $product->selling_price) }}" required>
            </div>

            <div class="form-field">
                <div class="form-field-head">
                    <span class="form-chip" aria-hidden="true"><i class="ti ti-alert-triangle"></i></span>
                    <label for="reorder_level">Reorder Level (Low Stock Threshold)</label>
                </div>
                <input type="number" id="reorder_level" name="reorder_level" value="{{ old('reorder_level', $product->reorder_level) }}" required>
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

<div class="form-card" style="margin-bottom:20px;">
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
                {{-- READONLY: the number is assigned, not entered. Picking a
                     received date shows what it will be, and
                     ProductController::addBatch derives the stored value from
                     ProductBatch::nextBatchNumber() regardless of what arrives
                     — so this box is a preview, and with JavaScript off it
                     simply stays empty and the server still gets it right.

                     `readonly` rather than `disabled`: a disabled field posts
                     nothing AND is skipped by the tab order, which would make
                     the form's one derived value invisible to a keyboard user.
                     Readonly still submits and is still focusable; the value is
                     discarded server-side either way. --}}
                <input type="text" id="batch_number" name="batch_number" value="{{ old('batch_number') }}"
                       readonly aria-readonly="true" tabindex="-1"
                       placeholder="Assigned when you pick the received date"
                       data-batch-auto
                       data-batch-code="{{ \App\Models\ProductBatch::batchNameCode($product) }}"
                       data-batch-pad="{{ \App\Models\ProductBatch::BATCH_SEQUENCE_PAD }}"
                       data-batch-taken="{{ json_encode($batchSequences) }}">
                <p class="batch-number-hint">
                    Assigned automatically: {{ \App\Models\ProductBatch::batchNameCode($product) }} (from the product name),
                    the received date, and a number for that day.
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
            <tr><th>Batch No.</th><th>Quantity</th><th>Received</th><th>Expiry</th><th>Status</th><th>Return Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        @forelse($existingBatches as $batch)
            <tr>
                <td>{{ $batch->batch_number }}</td>
                <td>
                    <form method="POST" action="{{ route('batches.update', $batch) }}" style="display:flex; gap:4px; align-items:center;">
                        @csrf
                        @method('PUT')
                        <input type="number" name="quantity" value="{{ $batch->quantity }}" min="0" style="width:70px; padding:4px; border:1px solid #d1d5db; border-radius:4px;">
                        <input type="hidden" name="expiry_date" value="{{ $batch->expiry_date?->format('Y-m-d') }}">
                        <button type="submit" class="btn btn-info" style="padding:4px 8px;">Update</button>
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
                    <form method="POST" action="{{ route('batches.destroy', $batch) }}" style="margin:0;"
                          class="js-confirm"
                          data-confirm-title="Remove this batch?"
                          data-confirm-body="Batch {{ $batch->batch_number }} and its {{ $batch->quantity }} remaining units will be removed from stock. This cannot be undone."
                          data-confirm-label="Remove"
                          data-confirm-icon="ti-trash"
                          data-on-success="remove-row">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger" style="padding:4px 8px;">Remove</button>
                    </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7">No batches yet. Add one above.</td></tr>
        @endforelse
        </tbody>
    </table></div>
        </div>
</div>

<style>
    .batch-number-hint { margin: 6px 0 0; font-size: 12px; color: var(--ink-soft); }

    /* Readable, not disabled-looking: this value is real and is what gets
       stored, it just isn't yours to type. A greyed-out field would read as
       "not applicable". */
    #batch_number[readonly] { background: #f8fafc; color: var(--ink); cursor: default; }
    #batch_number[readonly]:focus { outline: none; border-color: var(--line); box-shadow: none; }
</style>

<script>
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

    function sync() {
        field.value = suggestion();
    }

    // Unconditional: the field is readonly, so nothing in it is ever the user's
    // and there is no typed value to protect.
    form.addEventListener('change', function (e) { if (e.target === date) sync(); });
    form.addEventListener('input', function (e) { if (e.target === date) sync(); });

    sync();
})();
</script>
@endsection
