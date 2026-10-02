<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // PayMongo posts here from its servers, with no session to carry a
        // token. Its own signature (Paymongo-Signature) is checked instead,
        // first thing -- see QrPaymentController::webhook().
        'webhooks/paymongo',
    ];
}
