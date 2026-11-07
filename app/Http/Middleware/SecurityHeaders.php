<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two standard headers on every response (2026-11-07, found checking the live
 * site from outside: neither was sent).
 *
 * X-Frame-Options: SAMEORIGIN -- no other site may show these pages inside a
 * frame. Without it a page elsewhere could load REMEDI invisibly over its own
 * content and steer a signed-in admin's click onto Void, Archive or Save
 * ("clickjacking"). The app never frames itself (there is no <iframe> in it),
 * so nothing here is lost.
 *
 * X-Content-Type-Options: nosniff -- the browser uses the type the server
 * declares instead of guessing one from the bytes, so an uploaded or
 * mislabelled file cannot be run as a script.
 *
 * Static files Vercel serves straight from public/ do not pass through Laravel
 * and are unaffected; they carry no actions to hijack.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);

        return $response;
    }
}
