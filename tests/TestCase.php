<?php

namespace KobaltDigital\PingPong\Tests;

use Illuminate\Support\Facades\Http;
use KobaltDigital\PingPong\PingPongServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            PingPongServiceProvider::class,
        ];
    }
}
