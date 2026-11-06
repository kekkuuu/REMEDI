<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * What a pop-up says when a form's security token no longer matches the session
     * (2026-11-06, at the user's request): Laravel's own "CSRF token mismatch." means
     * nothing to the person at the counter. It happens when the page was left open past
     * the session (SESSION_LIFETIME), or the account signed in again in another tab.
     */
    public const EXPIRED_PAGE_MESSAGE = 'This page has been open too long, so nothing was saved. Reload the page and try again.';

    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Only for the pop-ups, which post over fetch. A plain form post keeps
        // Laravel's own "Page Expired" page.
        $this->renderable(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 419 && $e->getPrevious() instanceof TokenMismatchException
                && ($request->expectsJson() || $request->ajax())) {
                return response()->json([
                    'success' => false,
                    'error' => self::EXPIRED_PAGE_MESSAGE,
                    'message' => self::EXPIRED_PAGE_MESSAGE,
                ], 419);
            }
        });
    }
}
