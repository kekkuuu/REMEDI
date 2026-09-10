@extends('layouts.app')

@section('title', 'Point of Sale')

@section('content')

<style>
    /* Cart quantity input — was width:50px with the app's default
       9px/12px input padding, which left almost no room once the
       browser's native number-spinner arrows were added in. A second
       digit (e.g. "12") got visually clipped/hidden behind the spinner.
       Wider box + tighter, centered padding fixes it and comfortably
       fits 3-digit quantities too. */
    /* ── POS layout ──
       These were inline grid styles, which media queries can't override — on
       a phone the two columns still computed to 474px + 650px, so the cart
       sat ~750px off-screen. They're classes now so the breakpoints work. */
    .pos-layout {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 16px;
        align-items: start;
    }

    .pos-cart { position: sticky; top: 16px; height: fit-content; }

    .pos-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
    }

    /* Desktop keeps the cart inline, so the summary bar is hidden there.
       Declared BEFORE the breakpoints so those can turn it back on. */
    .pos-cart-bar { display: none; }

    /* ── Tablet & phone: the fast-food register layout ──
       One full-width wall of large product tiles, and the order total pinned to
       the bottom of the screen where a thumb reaches it — the same shape as
       a quick-service till. */
    @media (max-width: 1024px) {
        .pos-layout { grid-template-columns: 1fr; }

        .pos-grid { grid-template-columns: repeat(3, 1fr); gap: 12px; }

        .pos-product {
            min-height: 132px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-size: 15px;
        }

        /* Cart leaves the flow and becomes a docked order panel. */
        .pos-cart {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            top: auto;
            z-index: 50;
            margin: 0;
            border-radius: 16px 16px 0 0;
            box-shadow: 0 -10px 30px -12px rgba(15, 23, 42, .4);
            max-height: 62vh;
            overflow-y: auto;
            transform: translateY(calc(100% - 62px));
            transition: transform .25s ease;
        }

        /* Expanded by the summary bar. */
        .pos-cart.is-open { transform: translateY(0); }

        /* The always-visible strip: item count, total, and the way in. */
        .pos-cart-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            height: 62px;
            margin: -16px -14px 10px;
            padding: 0 16px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            background: #fff;
            border-radius: 16px 16px 0 0;
            position: sticky;
            top: -16px;
            z-index: 2;
        }

        .pos-cart-bar .count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 26px;
            height: 26px;
            padding: 0 7px;
            border-radius: 999px;
            background: #4f46e5;
            color: #fff;
            font-size: 13px;
            font-weight: 700;
        }

        .pos-cart-bar .total { font-size: 18px; font-weight: 700; color: #1e293b; }
        .pos-cart-bar .chev { transition: transform .25s ease; color: #94a3b8; }
        .pos-cart.is-open .pos-cart-bar .chev { transform: rotate(180deg); }

        /* Room so the docked panel never covers the last row of products. */
        .pos-products { padding-bottom: 86px; }
    }

    @media (max-width: 767px) {
        .pos-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
        .pos-product { min-height: 120px; }
        .pos-cart { max-height: 72vh; }
    }

    @media (max-width: 340px) {
        .pos-grid { grid-template-columns: 1fr; }
        .pos-product { min-height: auto; }
    }

    /* ── Product tile hover ──
       These tiles are the primary click target at the register, but a plain
       card gave no feedback that it was interactive. Lift + indigo edge +
       a tinted shadow, so the tile under the cursor is unmistakable. */
    .pos-product {
        cursor: pointer;
        border: 1px solid #e2e8f0;
        transition: transform .13s ease, box-shadow .16s ease,
                    border-color .16s ease, background .16s ease;
    }

    .pos-product:hover {
        transform: translateY(-3px);
        border-color: #6366f1;
        background: #fbfcff;
        box-shadow: 0 10px 22px -10px rgba(99, 102, 241, .55);
    }

    /* Pressing settles it back down, so a tap feels like a button. */
    .pos-product:active { transform: translateY(-1px); box-shadow: none; }

    /* Nothing to add when it's out of stock — say so instead of inviting a
       click that only produces an alert. */
    .pos-product.is-out {
        cursor: not-allowed;
        opacity: .6;
        background: #f8fafc;
    }

    .pos-product.is-out:hover {
        transform: none;
        border-color: #e2e8f0;
        box-shadow: none;
        background: #f8fafc;
    }

    @media (prefers-reduced-motion: reduce) {
        .pos-product, .pos-product:hover { transform: none; transition: box-shadow .16s ease; }
    }

    /* The fields the cashier actually types into. These carried their border
       as an INLINE style, and an inline style beats any stylesheet rule, so a
       :hover on them would have been silently overridden -- hence the class.
       (Same trap as the inline grid columns documented in REMEDI.md.) */
    .pos-input {
        width: 100%;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        transition: border-color .15s ease, box-shadow .15s ease;
    }

    .pos-input:hover { border-color: #a5b4fc; }

    .pos-input:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, .15);
    }

    /* Scan and payment fields: big type, they are hit under time pressure. */
    .pos-input-lg { padding: 12px; font-size: 1.1rem; margin-top: 6px; }
    .pos-input-search { padding: 10px; }

    .cart-qty-input {
        width: 68px;
        padding: 6px 4px;
        text-align: center;
        font-size: 14px;
        border: 1px solid #d1d5db;
        border-radius: 5px;
        transition: border-color .15s ease, box-shadow .15s ease;
    }

    .cart-qty-input:hover { border-color: #a5b4fc; }

    .cart-qty-input:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, .15);
    }

    /* The docked order summary is role="button" on tablet/phone but read as
       inert -- nothing moved when a finger or cursor landed on it. */
    .pos-cart-bar { cursor: pointer; transition: background .15s ease; }
    .pos-cart-bar:hover { background: #f8fafc; }
</style>

{{-- Barcode scanner: hidden, not deleted.

     The markup stays in the DOM so every handle below it (barcode-input,
     barcode-status and the keydown listener) still resolves and the scanning
     path keeps working for a hardware reader, which types into whatever holds
     focus. Deleting it would mean null-guarding four call sites and losing the
     feature; hiding it is one attribute and reversible.

     To bring it back, drop the `hidden` attribute -- focusEntryField() below
     detects that the field is visible again and re-arms it automatically, so
     nothing else needs changing. --}}
<div class="card" hidden style="margin-bottom: 12px; border: 2px solid #4f46e5;">
    <label style="font-weight:600; font-size:.85rem;">Scan Barcode</label>
    <input
        type="text"
        id="barcode-input"
        placeholder="Click here, then scan a product's barcode..."
        autocomplete="off"
        autofocus
        class="pos-input pos-input-lg">
    <div id="barcode-status" style="margin-top:6px; font-size:.85rem; min-height:1.2em;"></div>
</div>

<div class="pos-layout">

    <div class="pos-products">
        <form method="GET" id="search-form" style="margin-bottom:12px;">
            <input type="text" name="search" id="search-input"
            data-suggest-url="{{ route('suggest.products') }}" placeholder="Search product by name or SKU..." value="{{ request('search') }}"
                   class="pos-input pos-input-search" autocomplete="off">
        </form>
        <p id="stock-live-status" style="font-size:.8rem; color:#64748b; margin:-6px 0 10px;">
            <span style="display:inline-block; width:7px; height:7px; border-radius:50%; background:#16a34a; margin-right:5px;"></span>
            Stock levels update automatically — last checked just now
        </p>

        <div id="results-wrapper">
            @include('pos._grid')
            <div style="margin-top:16px;" id="pagination-wrapper">{{ $products->links() }}</div>
        </div>
    </div>

    <div class="card pos-cart" id="posCart">
        {{-- Docked order summary, shown only at tablet/phone widths. Tapping
             it slides the full cart up — the quick-service till pattern. --}}
        <div class="pos-cart-bar" id="posCartBar" role="button" tabindex="0" aria-expanded="false">
            <span style="display:flex; align-items:center; gap:9px;">
                <span class="count" id="posCartCount">0</span>
                <span style="font-weight:600; color:#475569;">View order</span>
            </span>
            <span style="display:flex; align-items:center; gap:10px;">
                <span class="total">&#8369;<span id="posCartTotal">0.00</span></span>
                <i class="ti ti-chevron-up chev" aria-hidden="true"></i>
            </span>
        </div>

        <h4>Cart</h4>
        <div class="table-scroll"><table class="remedi-table" id="cart-table">
            <thead><tr><th>Item</th><th>Qty</th><th>Subtotal</th><th></th></tr></thead>
            <tbody id="cart-body">
                <tr id="cart-empty-row"><td colspan="4">Cart is empty</td></tr>
            </tbody>
        </table></div>
        <h3 style="text-align:right; margin-top:12px;">Total: &#8369;<span id="cart-total">0.00</span></h3>
        <div id="cart-error" style="display:none; margin-top:10px; padding:8px 12px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; border-radius:6px; font-size:13px;"></div>

        <form method="POST" action="{{ route('pos.checkout') }}" id="checkout-form">
            @csrf
            <div id="hidden-inputs"></div>
            <input type="hidden" name="amount_paid" id="amount-paid-hidden" value="">

            <button type="button" class="btn btn-success" style="width:100%; margin-top:8px;" id="checkout-btn" disabled>Proceed to Payment</button>
        </form>
    </div>
</div>

<!-- ===== Checkout Modal: Customer's Payment & Change ===== -->
<div id="checkout-modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:1000; align-items:center; justify-content:center; padding:16px;">
    <div class="card" style="width:100%; max-width:360px; position:relative;">
        <button type="button" id="modal-close-x" aria-label="Close"
                style="position:absolute; top:10px; right:12px; background:none; border:none; font-size:1.2rem; line-height:1; color:#94a3b8; cursor:pointer;">&times;</button>

        <h4 style="margin-top:0;">Customer's Payment</h4>

        <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:10px; margin-bottom:10px; border-bottom:1px dashed #d1d5db;">
            <span style="font-weight:600;">Order Total</span>
            <span style="font-weight:700; font-size:1.15rem;">&#8369;<span id="modal-total">0.00</span></span>
        </div>

        <label for="amount-paid-input" style="font-weight:600; font-size:.85rem;">Customer's Payment (&#8369;) <span style="color:#dc2626;" id="amount-required-mark">*</span></label>
        <input
            type="number"
            id="amount-paid-input"
            step="0.01"
            min="0"
            placeholder="Enter amount received..."
            autocomplete="off"
            autofocus
            class="pos-input pos-input-lg">

        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
            <span style="font-weight:600;">Change:</span>
            <span id="change-due" style="font-weight:700; font-size:1.1rem;">&#8369;0.00</span>
        </div>
        <div id="payment-status" style="margin-top:4px; font-size:.85rem; min-height:1.2em;"></div>

        <div style="margin-top:18px;">
            <button type="button" class="btn btn-success" style="width:100%;" id="modal-confirm-btn" disabled>Checkout</button>
        </div>
    </div>
</div>

{{-- ===== Receipt Modal: shown after a successful checkout, so the cashier
     stays on the register instead of being navigated to the receipt page ===== --}}
@include('pos._receipt-styles')

<div id="receipt-modal-overlay" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:1100; align-items:center; justify-content:center; padding:16px; overflow-y:auto;">
    <div style="display:flex; flex-direction:column; align-items:center; gap:14px; max-height:100%;">
        <div id="receipt-modal-body" style="overflow-y:auto;"></div>

        <div class="no-print" style="display:flex; gap:10px; flex-wrap:wrap; justify-content:center; padding-bottom:8px;">
            {{-- Printing fires automatically when this modal opens; the button
                 stays for reprinting the same sale if the cashier cancels the
                 dialog or the printer wasn't ready. --}}
            <button type="button" class="btn btn-secondary" id="receipt-print-btn">Print Again</button>
            <button type="button" class="btn btn-success" id="receipt-new-txn-btn">New Transaction</button>
        </div>
    </div>
</div>

<script>
    let cart = {};

    /* Stock refusals go through the app's message modal, not window.alert().
       A browser alert reads as a browser error, blocks the tab, and can only
       repeat the number this page was rendered with — which at a busy counter
       is exactly the number that may have just changed. So the modal opens
       immediately with what we know, then confirms against /pos/lookup and
       replaces its detail line with the figure on the shelf right now.

       `sku` is optional: the grid passes it, the barcode path already holds a
       code it scanned. Without one the modal simply shows no live line rather
       than blocking on a lookup it cannot make. */
    function stockMessage(title, body, sku) {
        const dialog = REMEDI.showMessage({
            title: title,
            body: body,
            icon: 'ti-package-off',
            detail: sku ? 'Checking current stock…' : '',
        });

        if (!sku) return;

        fetch(`{{ route('pos.lookup') }}?sku=${encodeURIComponent(sku)}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then((res) => (res.ok ? res.json() : null))
            .then((data) => {
                if (!data || !data.found) {
                    dialog.setDetail('');
                    return;
                }

                // Keep the grid honest too: if the shelf moved under us, the
                // badge and the cart cap should not keep quoting the old figure.
                syncStockBadge(data.id, data.stock);
                if (cart[data.id]) cart[data.id].maxStock = data.stock;

                dialog.setDetail(data.stock > 0
                    ? `${data.stock} on hand right now.`
                    : 'None on hand right now.');
            })
            .catch(() => dialog.setDetail(''));
    }

    /* The grid badge carries the figure addToCart trusts, so a live lookup that
       finds a different number updates both or they disagree on the next tap. */
    function syncStockBadge(productId, stock) {
        const badge = document.getElementById(`product-stock-${productId}`);
        if (!badge) return;

        badge.dataset.trueStock = stock;
        badge.textContent = `Stock: ${stock}`;

        const reorder = Number(badge.dataset.reorderLevel || 0);
        badge.classList.remove('badge-danger', 'badge-warning', 'badge-success');
        badge.classList.add(stock <= 0 ? 'badge-danger' : (stock <= reorder ? 'badge-warning' : 'badge-success'));
    }

    function addToCart(id, name, price, maxStock, sku) {
        if (maxStock <= 0) {
            stockMessage('Out of stock', `${name} has no sellable stock.`, sku);
            return;
        }

        if (!cart[id]) {
            cart[id] = { name, price, qty: 0, maxStock };
        }
        if (cart[id].qty + 1 > maxStock) {
            stockMessage(
                'Not enough stock',
                `${name}: only ${maxStock} available, and the cart already holds ${cart[id].qty}.`,
                sku
            );
            return;
        }
        cart[id].qty += 1;
        renderCart();
    }

    function removeFromCart(id) {
        delete cart[id];
        clearCartError();
        renderCart();
    }

    function showCartError(message) {
        const el = document.getElementById('cart-error');
        el.textContent = message;
        el.style.display = 'block';
    }

    function clearCartError() {
        const el = document.getElementById('cart-error');
        el.style.display = 'none';
        el.textContent = '';
    }

    function updateQty(id, qtyRaw) {
        if (!cart[id]) return;

        const qty = parseInt(qtyRaw, 10);

        // A cleared field or a typed "0" used to silently either wipe the
        // item from the cart or (for anything non-numeric) stash NaN as
        // the quantity, which then rendered as "NaN" everywhere including
        // the subtotal. Surface it as an error instead and leave the
        // cart untouched — removing an item is what the × button is for.
        if (isNaN(qty) || qty <= 0) {
            showCartError('Enter a quantity of 1 or more. Use the × button to remove an item from the cart.');
            renderCart(); // redraws the qty input back to its last valid value
            return;
        }

        clearCartError();

        if (qty > cart[id].maxStock) {
            stockMessage(
                'Not enough stock',
                `${cart[id].name}: only ${cart[id].maxStock} available. The quantity has been set to that.`,
                cart[id].sku
            );
            cart[id].qty = cart[id].maxStock;
            renderCart();
            return;
        }

        cart[id].qty = qty;
        renderCart();
    }

    /* Product names reach here from an admin-only source (the grid tile's
       inline JSON encoding of the product name, or the JSON lookupBySku()
       response for a barcode scan) but are otherwise plain, unvalidated
       strings -- ProductController allows any characters. Escaping on the way
       into innerHTML, not on the way in, matches how every other product-name
       sink in this app treats the same data (the receipt partial and the
       toast seed both escape it too). */
    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderCart() {
        const body = document.getElementById('cart-body');
        const hiddenInputs = document.getElementById('hidden-inputs');
        body.innerHTML = '';
        hiddenInputs.innerHTML = '';
        let total = 0;
        let index = 0;
        let hasItems = false;

        for (const id in cart) {
            hasItems = true;
            const item = cart[id];
            const subtotal = item.price * item.qty;
            total += subtotal;

            body.innerHTML += `
                <tr>
                    <td>${escapeHtml(item.name)}</td>
                    <td><input type="number" min="1" max="${item.maxStock}" value="${item.qty}" class="cart-qty-input" onchange="updateQty(${id}, this.value)"></td>
                    <td>&#8369;${subtotal.toFixed(2)}</td>
                    <td><button type="button" class="btn btn-danger" style="padding:2px 6px;" onclick="removeFromCart(${id})">x</button></td>
                </tr>`;

            hiddenInputs.innerHTML += `
                <input type="hidden" name="items[${index}][product_id]" value="${id}">
                <input type="hidden" name="items[${index}][quantity]" value="${item.qty}">`;
            index++;
        }

        if (!hasItems) {
            body.innerHTML = '<tr id="cart-empty-row"><td colspan="4">Cart is empty</td></tr>';
        }

        document.getElementById('cart-total').innerText = total.toFixed(2);
        cartTotal = total;
        syncPosCartBar(total);
        syncStockBadges();
        updatePaymentState();
    }

    // ── Docked cart bar (tablet/phone) ──
    const posCart = document.getElementById('posCart');
    const posCartBar = document.getElementById('posCartBar');

    function togglePosCart(force) {
        const open = force !== undefined ? force : !posCart.classList.contains('is-open');
        posCart.classList.toggle('is-open', open);
        posCartBar.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    if (posCartBar) {
        posCartBar.addEventListener('click', () => togglePosCart());
        posCartBar.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); togglePosCart(); }
        });
    }

    // Mirrors the cart totals into the docked bar, which is the only part of
    // the cart visible until it's opened.
    function syncPosCartBar(total) {
        const count = Object.values(cart).reduce((n, i) => n + i.qty, 0);
        const countEl = document.getElementById('posCartCount');
        const totalEl = document.getElementById('posCartTotal');
        if (countEl) countEl.textContent = count;
        if (totalEl) totalEl.textContent = total.toFixed(2);
    }

    // ===== Keep grid stock badges in sync with the cart =====
    // Clicking a product doesn't actually deduct stock in the database
    // (that only happens at checkout), but the cashier should still see
    // "what's really left to sell" reflected immediately: true DB stock
    // minus whatever's currently sitting in this cart. Also re-syncs each
    // cart item's own ceiling (maxStock) against the latest true stock
    // whenever fresh grid data arrives, so the "cannot add more" check
    // stays correct even if real inventory changed after the item was
    // added to the cart.
    function syncStockBadges() {
        document.querySelectorAll('[id^="product-stock-"]').forEach((badge) => {
            const id = badge.id.replace('product-stock-', '');
            const trueStock = parseInt(badge.dataset.trueStock, 10);
            const reorderLevel = parseInt(badge.dataset.reorderLevel, 10) || 0;

            if (cart[id]) {
                cart[id].maxStock = trueStock;
            }

            const inCart = cart[id] ? cart[id].qty : 0;
            const remaining = Math.max(0, trueStock - inCart);

            badge.textContent = 'Stock: ' + remaining;
            badge.classList.remove('badge-danger', 'badge-warning', 'badge-success');

            if (remaining <= 0) {
                badge.classList.add('badge-danger'); // no stock left
            } else if (remaining <= reorderLevel) {
                badge.classList.add('badge-warning'); // low stock
            } else {
                badge.classList.add('badge-success'); // sufficient stock
            }
        });
    }

    // ===== Amount Paid / Change =====
    let cartTotal = 0;
    const amountPaidInput = document.getElementById('amount-paid-input');
    const amountPaidHidden = document.getElementById('amount-paid-hidden');
    const changeDueEl = document.getElementById('change-due');
    const paymentStatusEl = document.getElementById('payment-status');
    const checkoutBtn = document.getElementById('checkout-btn');
    const modalConfirmBtn = document.getElementById('modal-confirm-btn');
    const modalCloseX = document.getElementById('modal-close-x');
    const modalOverlay = document.getElementById('checkout-modal-overlay');
    const modalTotalEl = document.getElementById('modal-total');
    const checkoutForm = document.getElementById('checkout-form');

    function openCheckoutModal() {
        if (Object.keys(cart).length === 0) return;
        modalTotalEl.innerText = cartTotal.toFixed(2);
        amountPaidInput.value = '';
        updatePaymentState();
        modalOverlay.style.display = 'flex';
        // preventScroll: the field is inside a fixed overlay, and revealing it
        // scrolls the document behind the dialog to the top.
        setTimeout(() => amountPaidInput.focus({ preventScroll: true }), 50);
    }

    function closeCheckoutModal() {
        modalOverlay.style.display = 'none';
    }

    checkoutBtn.addEventListener('click', openCheckoutModal);
    modalCloseX.addEventListener('click', closeCheckoutModal);
    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) closeCheckoutModal();
    });

    // ===== Receipt modal =====
    const receiptOverlay = document.getElementById('receipt-modal-overlay');
    const receiptBody = document.getElementById('receipt-modal-body');
    const receiptPrintBtn = document.getElementById('receipt-print-btn');
    const receiptNewTxnBtn = document.getElementById('receipt-new-txn-btn');

    function openReceiptModal(html) {
        receiptBody.innerHTML = html;
        receiptOverlay.style.display = 'flex';

        // Send it to the printer without waiting for a click -- printing is
        // what happens on essentially every sale. The button remains for a
        // reprint if the cashier dismisses the dialog.
        printReceipt();

        // Park focus on "New Transaction" so the cashier can just hit Enter
        // to start the next sale. printReceipt() hands focus to the print
        // iframe, so this has to come after it settles.
        setTimeout(() => receiptNewTxnBtn.focus({ preventScroll: true }), 400);
    }

    function closeReceiptModal() {
        receiptOverlay.style.display = 'none';
        receiptBody.innerHTML = '';
        focusEntryField(); // straight back to the next sale's first entry
    }

    // Print the receipt through an offscreen iframe rather than window.print().
    // Printing the page itself would mean hiding the entire app chrome by CSS,
    // and hidden-but-still-laid-out elements (the full-height sticky sidebar
    // especially) tend to push out blank pages on an 80mm roll. An iframe
    // containing only the receipt sidesteps that completely.
    function printReceipt() {
        const receiptEl = receiptBody.querySelector('.receipt');
        if (!receiptEl) return;

        const styles = document.getElementById('receipt-styles');

        const frame = document.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.style.cssText = 'position:fixed; right:0; bottom:0; width:0; height:0; border:0;';
        document.body.appendChild(frame);

        const win = frame.contentWindow;
        const doc = win.document;
        doc.open();
        doc.write(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Receipt</title>'
            + '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;700&display=swap">'
            + (styles ? styles.outerHTML : '')
            + '</head><body>' + receiptEl.outerHTML + '</body></html>'
        );
        doc.close();

        let printed = false;
        function fire() {
            if (printed) return; // whichever trigger wins, only print once
            printed = true;
            try {
                win.focus();
                win.print();
            } catch (err) {
                console.error('Receipt print failed', err);
            }
            setTimeout(() => frame.remove(), 1000);
        }

        // document.write() + close() finishes the document SYNCHRONOUSLY, so
        // by this line the iframe's load event has usually already fired.
        // Waiting on addEventListener('load') alone therefore never runs --
        // that bug silently disabled printing entirely. Check readyState
        // first, and keep the listener only for the case where it genuinely
        // is still loading, with a timeout as a final backstop.
        if (doc.readyState === 'complete') {
            setTimeout(fire, 150); // brief pause for layout + webfont
        } else {
            win.addEventListener('load', () => setTimeout(fire, 150));
            setTimeout(fire, 800);
        }
    }

    receiptPrintBtn.addEventListener('click', printReceipt);
    receiptNewTxnBtn.addEventListener('click', closeReceiptModal);

    // Enter starts the next transaction. The button is focused when the modal
    // opens so Enter usually activates it natively, but focus can end up
    // elsewhere after the print dialog is dismissed -- this catches that case
    // so Enter is reliable either way. Escape does the same thing.
    document.addEventListener('keydown', (e) => {
        if (receiptOverlay.style.display !== 'flex') return;
        if (e.key !== 'Enter' && e.key !== 'Escape') return;

        e.preventDefault();
        closeReceiptModal();
    });
    receiptOverlay.addEventListener('click', (e) => {
        if (e.target === receiptOverlay) closeReceiptModal();
    });

    // ===== Checkout =====
    // Posted over fetch() rather than a form navigation so a completed sale
    // leaves the cashier on the register with the receipt in a modal.
    function submitCheckout() {
        modalConfirmBtn.disabled = true;
        modalConfirmBtn.textContent = 'Processing...';

        fetch(checkoutForm.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: new FormData(checkoutForm),
        })
            .then(res => res.json().catch(() => null).then(data => ({ res, data })))
            .then(({ res, data }) => {
                if (!res.ok || !data || !data.success) {
                    // Two different 422 shapes reach here and the cashier needs
                    // both. The controller's own refusals (out of stock, short
                    // payment) arrive as {error}; Laravel's VALIDATION failures
                    // arrive as {message, errors} with no `error` key at all, so
                    // reading only `error` turned "The amount paid must not be
                    // greater than 99999999.99" into a bare "Checkout failed.
                    // Please try again." -- the one message that does not say
                    // what to change. Anything else really is unexpected.
                    throw new Error(
                        (data && (data.error || data.message))
                        || 'Checkout failed. Please try again.'
                    );
                }

                closeCheckoutModal();
                openReceiptModal(data.receipt_html);

                cart = {};
                renderCart();
                fetchProducts(currentProductsUrl, false); // stock just changed
            })
            .catch(err => {
                // Order matters: updatePaymentState() clears the status line,
                // so restore the button state first and write the error after,
                // or the message is wiped the instant it's set.
                updatePaymentState();
                paymentStatusEl.textContent = err.message;
                paymentStatusEl.style.color = '#dc2626';
            })
            .finally(() => {
                modalConfirmBtn.textContent = 'Checkout';
            });
    }

    modalConfirmBtn.addEventListener('click', () => {
        if (modalConfirmBtn.disabled) return;
        amountPaidHidden.value = amountPaidInput.value;
        submitCheckout();
    });

    function updatePaymentState() {
        const hasItems = Object.keys(cart).length > 0;
        checkoutBtn.disabled = !hasItems;

        const amountPaid = parseFloat(amountPaidInput.value);
        const paidEntered = !isNaN(amountPaid) && amountPaidInput.value.trim() !== '';

        if (!paidEntered) {
            changeDueEl.innerText = '\u20B1' + (0).toFixed(2);
            paymentStatusEl.textContent = '';
            modalConfirmBtn.disabled = true;
            return;
        }

        const change = amountPaid - cartTotal;

        if (change < 0) {
            changeDueEl.innerText = '\u20B1' + (0).toFixed(2);
            changeDueEl.style.color = '#dc2626';
            paymentStatusEl.textContent = 'Insufficient amount. Short by \u20B1' + Math.abs(change).toFixed(2);
            paymentStatusEl.style.color = '#dc2626';
            modalConfirmBtn.disabled = true;
        } else {
            changeDueEl.innerText = '\u20B1' + change.toFixed(2);
            changeDueEl.style.color = '#16a34a';
            paymentStatusEl.textContent = '';
            modalConfirmBtn.disabled = !hasItems;
        }
    }

    amountPaidInput.addEventListener('input', updatePaymentState);
    amountPaidInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (!modalConfirmBtn.disabled) modalConfirmBtn.click();
        }
    });

    // ===== Real-time product search =====
    const searchInput = document.getElementById('search-input');
    const resultsWrapper = document.getElementById('results-wrapper');
    const posBaseUrl = "{{ route('pos.index') }}";

    let searchController;
    let currentProductsUrl = window.location.href;

    function fetchProducts(url, pushState = true) {
        if (searchController) searchController.abort();
        searchController = new AbortController();
        currentProductsUrl = url.toString();

        // Swap the stale rows for a skeleton so a search/filter reads as

        // 'working' instead of leaving the previous results on screen.

        // See REMEDI.holdScroll: read the offset before the grid is gone.
        const restoreScroll = REMEDI.holdScroll();

        REMEDI.showListSkeleton(resultsWrapper, { rows: 6, grid: true });


        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: searchController.signal,
        })
            .then(res => res.json())
            .then(data => {
                REMEDI.clearListSkeleton(resultsWrapper);
                resultsWrapper.innerHTML = data.html + `<div style="margin-top:16px;" id="pagination-wrapper">${data.pagination}</div>`;
                restoreScroll();
                if (pushState) window.history.pushState({}, '', url);
                updateLiveStatus();
                syncStockBadges();
            })
            .catch(err => {
                if (err.name !== 'AbortError') console.error(err);
            });
    }

    const liveStatus = document.getElementById('stock-live-status');
    function updateLiveStatus() {
        if (!liveStatus) return;
        const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        liveStatus.innerHTML = `<span style="display:inline-block; width:7px; height:7px; border-radius:50%; background:#16a34a; margin-right:5px;"></span>Stock levels update automatically — last checked ${time}`;
    }

    let searchDebounce;

    function runProductSearch(pushState = true) {
        const url = new URL(posBaseUrl);
        const term = searchInput.value.trim();
        if (term) url.searchParams.set('search', term);
        fetchProducts(url, pushState);
    }

    // With the dropdown gone there is nothing to "pick": typing filters the
    // product grid below, and the cashier taps a tile (or scans) to add it.
    searchInput.addEventListener('suggest:live', () => runProductSearch());

    searchInput.addEventListener('input', () => {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => runProductSearch(), 300);
    });

    document.getElementById('search-form').addEventListener('submit', (e) => {
        e.preventDefault();
        runProductSearch();
    });

    // Intercept pagination link clicks so paging through products
    // doesn't reload the page (which would wipe out the in-progress cart).
    resultsWrapper.addEventListener('click', (e) => {
        const link = e.target.closest('#pagination-wrapper a');
        if (link && link.href) {
            e.preventDefault();
            fetchProducts(link.href);
        }
    });

    window.addEventListener('popstate', () => {
        const params = new URLSearchParams(window.location.search);
        searchInput.value = params.get('search') || '';
        fetchProducts(window.location.href, false);
    });

    // ===== Keep stock levels live =====
    // The grid only reflected whatever stock existed when the page (or
    // last search) loaded. Since sales can happen from any register and
    // stock can be adjusted from Inventory at any time, poll for fresh
    // numbers on a timer so a cashier who leaves this page open doesn't
    // sell against stale stock. Reuses the same search/pagination state
    // already on screen, so it doesn't disturb what the cashier is doing,
    // and never touches the in-progress cart.
    const POLL_INTERVAL_MS = 15000;

    function anyModalOpen() {
        return modalOverlay.style.display === 'flex' || receiptOverlay.style.display === 'flex';
    }

    function pollProducts() {
        if (document.hidden) return; // skip while tab is backgrounded
        if (anyModalOpen()) return;  // don't refresh mid-checkout or under the receipt
        fetchProducts(currentProductsUrl, false);
    }

    setInterval(pollProducts, POLL_INTERVAL_MS);

    // Catch up immediately if the cashier switches back to this tab
    // after it's been backgrounded for a while, rather than waiting
    // for the next timer tick.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) pollProducts();
    });

    // ===== Barcode Scanner Support =====
    const barcodeInput = document.getElementById('barcode-input');

    /* Where should the caret sit?

       The scanner card is hidden, and a hidden input cannot take focus -- so
       every barcodeInput.focus() below would silently do nothing and the caret
       would end up nowhere at all: after a sale, after closing the receipt, and
       after every click on the page. This routes focus to whichever entry field
       is actually on screen, which is the product search box while the scanner
       is hidden and the scanner itself the moment it is shown again. */
    function focusEntryField() {
        const scanner = document.getElementById('barcode-input');
        const target = (scanner && scanner.offsetParent !== null)
            ? scanner
            : document.getElementById('search-input');

        if (target) target.focus({ preventScroll: true });
    }
    const barcodeStatus = document.getElementById('barcode-status');

    /* Keep the scanner armed WITHOUT dragging the page around.
       This refocuses a field that sits at the top of the page, and a plain
       .focus() scrolls it into view -- so every click on a card, a row or a
       label threw the reader back to the top. preventScroll keeps the caret
       where the scanner needs it and leaves the viewport alone.

       It also bails out while a selection is live: refocusing collapses the
       selection, so highlighting a product name to copy it both wiped the
       highlight and jumped the page. */
    document.addEventListener('click', function (e) {
        if (anyModalOpen()) return; // don't steal focus from an open modal

        const selection = window.getSelection();
        if (selection && !selection.isCollapsed) return;

        const tag = e.target.tagName;
        if (tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'BUTTON' && tag !== 'A') {
            focusEntryField();
        }
    });
    focusEntryField();

    barcodeInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const code = barcodeInput.value.trim();
            barcodeInput.value = '';

            if (!code) return;

            barcodeStatus.textContent = 'Looking up ' + code + '...';
            barcodeStatus.style.color = '#6b7280';

            fetch(`{{ route('pos.lookup') }}?sku=${encodeURIComponent(code)}`)
                .then(function (response) {
                    if (!response.ok) throw new Error('not_found');
                    return response.json();
                })
                .then(function (data) {
                    if (data.found) {
                        addToCart(data.id, data.name, data.price, data.stock, code);
                        barcodeStatus.textContent = '\u2705 Added: ' + data.name;
                        barcodeStatus.style.color = '#16a34a';
                    } else {
                        barcodeStatus.textContent = '\u274C Product not found for code: ' + code;
                        barcodeStatus.style.color = '#dc2626';
                    }
                })
                .catch(function () {
                    barcodeStatus.textContent = '\u274C Product not found for code: ' + code;
                    barcodeStatus.style.color = '#dc2626';
                });
        }
    });
</script>
@endsection
