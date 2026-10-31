<?php

namespace Tests;

use App\Support\OutboundHost;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Webhook delivery and device adapters connect only to an address
        // they looked up and checked, so a test that names a host must not
        // depend on the runner's DNS: CI answered example.com on one run and
        // not the next. Every name resolves to one public address unless a
        // test answers DNS itself with OutboundHost::resolveUsing().
        OutboundHost::resolveUsing(fn (string $host): array => ['93.184.216.34']);
    }

    protected function tearDown(): void
    {
        OutboundHost::resolveUsing(null);

        parent::tearDown();
    }
}
