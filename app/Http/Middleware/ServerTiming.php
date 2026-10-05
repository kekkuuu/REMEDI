<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A `Server-Timing` header on every response: time spent in the app, time
 * spent waiting on the database, and how many queries that took.
 *
 * Added 2026-10-05 to measure the live site. From the Philippines a page's
 * time is the trip to Europe plus the server's own share, and the browser
 * cannot tell the two apart; this header can, and DevTools shows it under
 * Network -> Timing. Database time includes each query's round trip, which
 * is what matters on Vercel, where the function and the database are in
 * different data centres. Only durations and a count are sent, never SQL.
 */
class ServerTiming
{
    public function handle(Request $request, Closure $next): Response
    {
        $queries = 0;
        $dbMs = 0.0;

        DB::listen(function ($query) use (&$queries, &$dbMs) {
            $queries++;
            $dbMs += $query->time;
        });

        $response = $next($request);

        $start = defined('LARAVEL_START') ? LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));

        $response->headers->set('Server-Timing', sprintf(
            'app;dur=%.0f, db;dur=%.0f;desc="%d queries"',
            (microtime(true) - $start) * 1000,
            $dbMs,
            $queries,
        ));

        return $response;
    }
}
