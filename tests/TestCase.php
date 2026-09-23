<?php

namespace Tests;

use App\Services\SmsService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        /* SmsService's fake is STATIC, and PHPUnit runs the whole suite in
           one process -- so a class that calls SmsService::fake() would leave
           every test after it faking too, in files that never asked. That is
           not hypothetical: it turned eight SmsServiceTest cases green-alone
           and red-in-suite, because send() short-circuited into the capture
           branch and never reached the gateway the test was checking.

           Same family of trap as the guard memoisation DeactivationTest
           documents: state that outlives the test that set it. */
        SmsService::stopFaking();
    }
}
