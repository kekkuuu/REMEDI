{{--
    Receipt styling, shared by the two places a receipt is rendered:
      - pos/index.blade.php   -> inside the post-checkout modal
      - pos/receipt.blade.php -> the standalone / permalink page

    Keep this the single source of truth: a receipt printed from the modal
    and one printed from the standalone page must come out identical on the
    thermal roll.

    The id matters: the POS page reads this block's text back out and copies
    it into the print iframe, so the printed output uses these exact rules.
--}}
<style id="receipt-styles">
    /* 80mm thermal receipt paper ≈ 302px on screen; kept slightly wider
       for comfortable on-screen reading, with a subtle paper shadow */
    .receipt {
        width: 100%;
        max-width: 320px;
        background: #fff;
        border-radius: 4px;
        border: 0.5px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06), 0 8px 24px rgba(15, 23, 42, 0.05);
        padding: 24px 18px 20px;
        font-family: 'Courier New', 'SFMono-Regular', Consolas, monospace;
        font-size: 12.5px;
        color: #1e293b;
    }

    .receipt-store {
        text-align: center;
        margin-bottom: 4px;
    }

    .receipt-store .name {
        font-family: 'Outfit', sans-serif;
        font-size: 18px;
        font-weight: 700;
        letter-spacing: 0.02em;
        color: #1e293b;
    }

    .receipt-store .tagline {
        font-size: 11.5px;
        color: #94a3b8;
        margin-top: 2px;
    }

    .receipt-divider {
        border: none;
        border-top: 1.5px dashed #cbd5e1;
        margin: 16px 0;
    }

    .receipt-meta {
        font-size: 12.5px;
        color: #475569;
        display: flex;
        flex-direction: column;
        gap: 3px;
    }

    .receipt-meta .row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
    }

    .receipt-meta .row span:first-child { color: #94a3b8; }
    .receipt-meta .row span:last-child { font-weight: 600; text-align: right; }

    .receipt-items { margin: 4px 0; }

    .receipt-item {
        padding: 9px 0;
        border-bottom: 1px dashed #eef2f6;
    }

    .receipt-item:last-child { border-bottom: none; }

    .receipt-item .line1 {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        font-size: 13px;
        font-weight: 700;
        color: #1e293b;
    }

    .receipt-item .line2 {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        font-size: 11.5px;
        color: #94a3b8;
        margin-top: 2px;
    }

    .receipt-totals {
        margin-top: 4px;
        font-size: 12.5px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .receipt-totals .row {
        display: flex;
        justify-content: space-between;
        color: #64748b;
    }

    .receipt-totals .grand {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 8px;
        padding-top: 10px;
        border-top: 1.5px solid #1e293b;
        font-family: 'Outfit', sans-serif;
        font-size: 17px;
        font-weight: 700;
        color: #1e293b;
    }

    .receipt-footer {
        text-align: center;
        margin-top: 18px;
        font-size: 11.5px;
        color: #94a3b8;
        line-height: 1.6;
    }

    .receipt-footer .thanks {
        font-family: 'Outfit', sans-serif;
        font-size: 13px;
        font-weight: 600;
        color: #1e293b;
        margin-bottom: 4px;
    }

    .receipt-actions {
        display: flex;
        gap: 10px;
        justify-content: center;
        margin-top: 22px;
    }

    /* ── Print: size the page to standard 80mm thermal receipt paper,
       with height left free so the roll can be as long as the content ── */
    @page {
        size: 80mm auto;
        margin: 0;
    }

    @media print {
        /* The margin reset matters: the browser's default 8px body margin is
           added on top of an already 80mm-wide receipt, pushing it past the
           page edge so the right-hand column (prices, totals) clips off and
           can spill onto a second sheet. */
        html, body {
            width: 80mm;
            margin: 0 !important;
            padding: 0 !important;
        }
        .sidebar, .topbar, .receipt-actions, .no-print { display: none !important; }
        .main-content, .content-body { margin: 0 !important; padding: 0 !important; overflow: visible !important; }
        .receipt-wrap { display: block; }
        .receipt {
            width: 80mm;
            max-width: 80mm;
            margin: 0 auto;
            border: none;
            box-shadow: none;
            padding: 4mm 4mm 6mm;
            font-size: 11px;
        }
        .receipt-store .name { font-size: 15px; }
        .receipt-totals .grand { font-size: 14px; }
    }
</style>
