<?php

namespace App\Http\Controllers;

use App\Models\AuditTrail;
use App\Models\Product;
use App\Models\StockReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Staff tell an admin about low or expired stock; an admin approves or
 * rejects (2026-09-28). See App\Models\StockReport for what approval means.
 *
 * The admin is notified through the audit trail, the same pipe account
 * changes use: every write here goes through AuditTrail::log() with details
 * starting "Stock report", which AlertService::activity() turns into a bell
 * row and a toast card linking to this page.
 */
class StockReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $status = in_array($request->get('status'), array_keys(StockReport::STATUSES), true)
            ? $request->get('status')
            : null;

        $query = StockReport::with(['product.batches', 'reporter', 'reviewer'])
            // Staff see only what they reported; an admin sees everyone's.
            ->when(! $user->isAdmin(), fn ($q) => $q->where('reported_by', $user->id));

        // Counted before the status filter, so the tabs always describe the
        // whole list rather than the slice on screen.
        $counts = (clone $query)->select('status', DB::raw('COUNT(*) AS n'))
            ->groupBy('status')->pluck('n', 'status');

        $reports = $query
            ->when($status, fn ($q) => $q->where('status', $status))
            // Waiting ones first -- they are what an admin opens this page for.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [StockReport::STATUS_PENDING])
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('stock-reports.index', compact('reports', 'counts', 'status'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['bail', 'required', 'integer', Rule::exists('products', 'id')->whereNull('archived_at')],
            'type' => ['required', 'string', Rule::in(array_keys(StockReport::TYPES))],
        ]);

        $product = Product::with('batches')->findOrFail($data['product_id']);
        $typeLabel = StockReport::TYPES[$data['type']];

        // The button is only drawn when this is true, but a gated button is
        // not a gated endpoint.
        if (! StockReport::applies($product, $data['type'])) {
            return $this->actionFailed($request, "{$product->name} is not showing {$typeLabel} right now, so there is nothing to report.");
        }

        // One open report per product and problem. A second click -- by the
        // same person or a colleague -- is answered as a success, since the
        // admin already knows, and writes nothing.
        $open = StockReport::where('product_id', $product->id)
            ->where('type', $data['type'])
            ->where('status', StockReport::STATUS_PENDING)
            ->first();

        if ($open) {
            return $this->actionOk($request, "The admin has already been notified about {$product->name} ({$typeLabel}). It is waiting for approval.", back());
        }

        StockReport::create([
            'product_id' => $product->id,
            'type' => $data['type'],
            'status' => StockReport::STATUS_PENDING,
            'reported_by' => $request->user()->id,
        ]);

        StockReport::forgetPendingCount();

        AuditTrail::log('Requested', "Stock report: {$typeLabel} — {$product->name}, reported by {$request->user()->name}");

        return $this->actionOk($request, "The admin has been notified about {$product->name} ({$typeLabel}).", back());
    }

    public function approve(Request $request, StockReport $stockReport)
    {
        return $this->decide($request, $stockReport, StockReport::STATUS_APPROVED, 'Approved');
    }

    public function reject(Request $request, StockReport $stockReport)
    {
        return $this->decide($request, $stockReport, StockReport::STATUS_REJECTED, 'Rejected');
    }

    private function decide(Request $request, StockReport $stockReport, string $status, string $verb)
    {
        // Locked and re-checked inside the transaction, so two admins
        // answering the same report cannot both win.
        $decided = DB::transaction(function () use ($request, $stockReport, $status) {
            $fresh = StockReport::whereKey($stockReport->id)->lockForUpdate()->first();

            if (! $fresh || ! $fresh->isPending()) {
                return false;
            }

            $fresh->update([
                'status' => $status,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return true;
        });

        $stockReport->load(['product', 'reporter']);
        $name = $stockReport->product->name ?? 'a product';

        if (! $decided) {
            return $this->actionFailed($request, "This report on {$name} has already been answered.");
        }

        StockReport::forgetPendingCount();

        AuditTrail::log($verb, 'Stock report '.strtolower($verb).": {$stockReport->type_label} — {$name}"
            .($stockReport->reporter ? " (reported by {$stockReport->reporter->name})" : ''));

        return $this->actionOk($request, "{$verb}: {$stockReport->type_label} — {$name}.", back());
    }
}
