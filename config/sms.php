<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS driver
    |--------------------------------------------------------------------------
    |
    | Which gateway App\Services\SmsService hands a message to.
    |
    | "log" sends nothing: it writes the message to storage/logs/laravel.log
    | so the admin OTP flow works end to end with no account, no credits and
    | no credentials. That is where to read the code while no gateway is set
    | up, and it is the right setting for a development machine -- the code is
    | written out in full, so the log file can hand somebody an admin reset.
    |
    | "semaphore" sends real texts through semaphore.co, the usual Philippine
    | gateway. It needs SEMAPHORE_API_KEY, and every message costs credits.
    |
    | Nothing else in the app changes when this moves: SmsService::delivers()
    | flips to true on its own and the OTP screen stops telling people to look
    | in the log.
    |
    */

    'driver' => env('SMS_DRIVER', 'log'),

    'semaphore' => [

        /*
        | From the API Keys page of the Semaphore dashboard.
        |
        | Left empty, the semaphore driver logs an error and refuses to send
        | rather than failing silently -- a password reset that quietly goes
        | nowhere is worse than one that says it could not be sent.
        */
        'key' => env('SEMAPHORE_API_KEY', ''),

        /*
        | The name the text appears to come from.
        |
        | EMPTY BY DEFAULT ON PURPOSE. Semaphore REJECTS a sender name that
        | has not been registered and approved on the account, so setting this
        | to something plausible like "REMEDI" before registering it would
        | fail every message and look like a broken integration. Blank lets
        | Semaphore use its own default sender; set this only once the name is
        | approved on the account.
        */
        'sender_name' => env('SEMAPHORE_SENDER_NAME', ''),

    ],

];
