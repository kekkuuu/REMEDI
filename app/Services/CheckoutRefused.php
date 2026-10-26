<?php

namespace App\Services;

use RuntimeException;

/**
 * A checkout refused AFTER it started writing (CheckoutService::run()).
 *
 * Thrown, never returned: DB::transaction() rolls back only on an exception --
 * a closure that returns normally COMMITS, so returning the refusal kept a
 * half-written sale and its stock deductions while the cashier read "Nothing
 * was charged".
 */
class CheckoutRefused extends RuntimeException {}
