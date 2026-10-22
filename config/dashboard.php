<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Render the dashboard in ONE request instead of shell + AJAX body?
    |--------------------------------------------------------------------------
    |
    | The shell exists because the body was slow where it was built: 5-12 s
    | under `php artisan serve`, during which the browser sat on whatever page
    | it came from. On the live site the body takes ~0.36 s of server time
    | (measured 2026-10-22) while each request costs 1-2 s of travel to the
    | hosting in Europe -- so the shell's second trip cost more than it saved.
    |
    | On by default in production, off elsewhere. Even when on, the visit
    | straight after signing in still gets the shell and its loader card, so
    | signing in never waits on a cold dashboard; DashboardController applies
    | that exception. `?full=1` renders inline either way.
    |
    */

    'inline_body' => (bool) env('DASHBOARD_INLINE_BODY', env('APP_ENV') === 'production'),

];
