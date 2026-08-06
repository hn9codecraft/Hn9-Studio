<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Laravel sends any request matching no stub for real. The AI suites
     * exercise live vendor routes, so this makes a stray request fail loudly
     * instead of reaching a vendor with a test credential.
     *
     * It is applied to every test rather than per suite, so no future test can
     * quietly reintroduce outbound traffic.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
