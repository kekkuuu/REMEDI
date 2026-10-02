<?php

namespace App\Http\Controllers;

use App\Models\QrPayment;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\PayMongo;
use App\Services\PayMongoException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Automatic QR payments at the till, through PayMongo QR Ph (2026-10-02, at
 * the user's request: "it will automatically pay, no next buttons").
 *
 *   store()   the till asks for a code for its cart -> a PayMongo QR with the
 *             total in it, and a qr_payments row holding the cart
 *   show()    the till polls; PayMongo is asked whether the intent succeeded,
 *             and the sale is recorded the moment it has
 *   webhook() PayMongo says payment.paid -> the same completion, so a sale is
 *             recorded even if the till's tab was closed
 *
 * complete() is the one place a QR payment becomes a sale, through the same
 * CheckoutService the till's own checkout uses, and exactly once: the row is
 * locked and re-checked, and the sale carries an idempotency key, so a poll
 * and the webhook arriving together still make one sale.
 *
 * Only while PayMongo is configured (PayMongo::enabled()); otherwise these
 * routes answer 404 and the till uses the uploaded GCash QR plus the
 * "Customer has paid" button.
 */
class QrPaymentController extends Controller
{
    /** How long a code stays payable. PayMongo allows 60-9000 seconds. */
    private const EXPIRY_SECONDS = 900;

    public function store(Request $request, PayMongo $paymongo): JsonResponse
    {
        abort_unless(PayMongo::enabled(), 404);

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->whereNull('archived_at')],
            'items.*.quantity' => 'required|integer|min:1|max:'.self::MAX_COUNT,
            'payment_method' => ['required', Rule::in(Sale::EXACT_PAYMENT_METHODS)],
        ]);

        $items = collect($validated['items'])
            ->map(fn ($i) => ['product_id' => (int) $i['product_id'], 'quantity' => (int) $i['quantity']])
            ->values()
            ->all();

        $quote = app(CheckoutService::class)->quote($items);
        if (isset($quote['error'])) {
            return response()->json(['success' => false, 'error' => $quote['error']], 422);
        }

        $centavos = (int) round($quote['total'] * 100);
        if ($centavos < PayMongo::MIN_CENTAVOS) {
            return response()->json(['success' => false, 'error' => 'QR payments start at ₱1.00. Take this one in cash.'], 422);
        }

        try {
            $qr = $paymongo->createQrPh($centavos, 'REMEDI sale', self::EXPIRY_SECONDS);
        } catch (PayMongoException $e) {
            return response()->json(['success' => false, 'error' => 'Could not create the QR code: '.$e->getMessage()], 422);
        }

        $payment = QrPayment::create([
            'intent_id' => $qr['intent_id'],
            'amount' => $centavos / 100,
            'items' => $items,
            'payment_method' => $validated['payment_method'],
            'user_id' => $request->user()->id,
            'expires_at' => now()->addSeconds(self::EXPIRY_SECONDS),
        ]);

        return response()->json([
            'success' => true,
            'id' => $payment->id,
            'image' => $qr['image'],
            'amount' => number_format($centavos / 100, 2, '.', ''),
            'expires_at' => $payment->expires_at->toIso8601String(),
            'test_url' => $qr['test_url'],
            'status_url' => route('pos.qr-payments.show', $payment, false),
        ]);
    }

    public function show(Request $request, QrPayment $qrPayment, PayMongo $paymongo): JsonResponse
    {
        abort_unless((int) $qrPayment->user_id === (int) $request->user()->id || $request->user()->isAdmin(), 403);

        if ($qrPayment->isPending() && PayMongo::enabled()) {
            try {
                $status = $paymongo->intentStatus($qrPayment->intent_id);
            } catch (PayMongoException) {
                $status = null; // a blip; the next poll asks again
            }

            if ($status === 'succeeded') {
                $this->complete($qrPayment);
            } elseif ($qrPayment->expires_at && $qrPayment->expires_at->isPast()) {
                $qrPayment->update(['status' => QrPayment::STATUS_EXPIRED]);
            }
        }

        return $this->statusResponse($qrPayment->fresh());
    }

    /**
     * PayMongo's servers, not a signed-in person. The signature is checked
     * before anything else is read; an unsigned or wrongly signed request is
     * refused and changes nothing.
     */
    public function webhook(Request $request): JsonResponse
    {
        if (! PayMongo::verifySignature($request->getContent(), $request->header('Paymongo-Signature'), config('services.paymongo.webhook_secret'))) {
            abort(401);
        }

        $type = data_get($request->json()->all(), 'data.attributes.type');
        $intentId = data_get($request->json()->all(), 'data.attributes.data.attributes.payment_intent_id');
        $payment = $intentId ? QrPayment::where('intent_id', $intentId)->first() : null;

        if ($payment && $type === 'payment.paid') {
            $this->complete($payment);
        } elseif ($payment && $type === 'qrph.expired' && $payment->isPending()) {
            $payment->update(['status' => QrPayment::STATUS_EXPIRED]);
        }

        // 200 for anything verified, known or not, or PayMongo retries it.
        return response()->json(['received' => true]);
    }

    /** The one place a paid QR becomes a sale -- once. */
    private function complete(QrPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            $locked = QrPayment::whereKey($payment->id)->lockForUpdate()->first();

            if (! $locked || $locked->sale_id || $locked->status === QrPayment::STATUS_ATTENTION) {
                return;
            }

            $result = app(CheckoutService::class)->run([
                'items' => $locked->items,
                'amount_paid' => (float) $locked->amount,
                'payment_method' => $locked->payment_method,
                // One sale per code, however many times this runs.
                'idempotency_key' => 'qrpay:'.$locked->id,
            ], (int) $locked->user_id);

            if (isset($result['sale'])) {
                $locked->update(['status' => QrPayment::STATUS_COMPLETED, 'sale_id' => $result['sale']->id, 'paid_at' => now()]);

                return;
            }

            // The money arrived but the shelf or a price moved in between.
            // Nothing is sold; a person decides -- ring it up again, or refund.
            $locked->update([
                'status' => QrPayment::STATUS_ATTENTION,
                'paid_at' => now(),
                'message' => 'Paid ₱'.number_format((float) $locked->amount, 2).' through PayMongo, but the sale could not be recorded: '
                    .$result['error'].' Ring it up again as a cash sale or refund the customer.',
            ]);
        });
    }

    private function statusResponse(QrPayment $payment): JsonResponse
    {
        if ($payment->status === QrPayment::STATUS_COMPLETED && $payment->sale) {
            $sale = $payment->sale->load('items.product', 'user');

            return response()->json([
                'success' => true,
                'status' => $payment->status,
                'transaction_no' => $sale->transaction_no,
                'receipt_html' => view('pos._receipt', ['sale' => $sale])->render(),
                'receipt_url' => route('pos.receipt', $sale),
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => $payment->status,
            'message' => $payment->message,
        ]);
    }
}
