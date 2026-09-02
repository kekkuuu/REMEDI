<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | Only the lines this app adds on top of the framework's own auth
    | translations. Laravel merges app-level lang files with the framework's
    | rather than replacing them, so `failed`, `password` and `throttle` still
    | come from the framework and stay current with it -- copying them here
    | would just create a second copy to drift.
    |
    */

    /*
     * Shown when the credentials are correct but the account has been
     * deactivated (LoginRequest::authenticate, and EnsureUserIsActive when it
     * ends a live session).
     *
     * This key did not exist, so trans('auth.deactivated') returned the key
     * itself and the login page rendered the words "auth.deactivated" in its
     * error box. That lands on exactly the person least able to interpret it --
     * someone whose account has just been switched off, who cannot tell whether
     * they are locked out or the system is broken.
     */
    'deactivated' => 'This account has been deactivated. Please contact an administrator.',
];
