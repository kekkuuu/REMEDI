<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS driver
    |--------------------------------------------------------------------------
    |
    | Which gateway App\Services\SmsService hands a message to.
    |
    | "log" is the default and sends nothing: it writes the message to
    | storage/logs/laravel.log so the admin OTP flow works end to end with no
    | account, no credits and no credentials. That is where to read the code
    | while no gateway is wired in.
    |
    | To send real texts, add a branch to SmsService::send() for the gateway
    | (Semaphore and Twilio are the usual choices here -- Semaphore is cheaper
    | for Philippine numbers, Twilio works anywhere), put its credentials
    | below, and set SMS_DRIVER in .env. Nothing else in the app changes:
    | SmsService::delivers() flips to true on its own and the OTP screens stop
    | telling people to look in the log.
    |
    */

    'driver' => env('SMS_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Sender name
    |--------------------------------------------------------------------------
    |
    | What the text appears to come from, where the gateway supports it.
    |
    */

    'from' => env('SMS_FROM', 'REMEDI'),

];
