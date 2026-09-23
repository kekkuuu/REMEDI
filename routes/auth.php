<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetRequestController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);
    // Breeze's own token-by-email reset (NewPasswordController, below) can't
    // deliver anything here -- see PasswordResetRequestController's
    // docblock -- so these two carry the same route NAMES to an in-app
    // request-an-admin flow instead. reset-password/{token} is left wired to
    // NewPasswordController: nothing links to it any more, but it's harmless
    // dead code and removing it isn't this feature's job.
    Route::get('forgot-password', [PasswordResetRequestController::class, 'create'])
        ->name('password.request');
    Route::post('forgot-password', [PasswordResetRequestController::class, 'store'])
        ->name('password.email');
    // The ADMIN half of that flow: an admin has no admin to ask, so they
    // verify a code texted to their own number and set the password
    // themselves. All three steps are guest routes -- the whole point is that
    // nobody is signed in -- and each re-checks the session itself rather
    // than trusting the step before it. See PasswordResetRequestController.
    Route::get('forgot-password/code', [PasswordResetRequestController::class, 'showOtpForm'])
        ->name('password.otp');
    Route::post('forgot-password/code', [PasswordResetRequestController::class, 'verifyOtp'])
        ->name('password.otp.verify');
    Route::get('forgot-password/new-password', [PasswordResetRequestController::class, 'showResetForm'])
        ->name('password.otp.reset');
    Route::post('forgot-password/new-password', [PasswordResetRequestController::class, 'resetPassword'])
        ->name('password.otp.update');
    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

// `active` here too: PUT /password is in this group, and a deactivated account
// must not be able to change its own credentials. Logout still works -- the
// middleware ends the session and lands on login either way.
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');
    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');
    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
