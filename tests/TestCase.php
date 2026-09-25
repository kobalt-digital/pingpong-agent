<?php

namespace KobaltDigital\PingPong\Tests;

use KobaltDigital\PingPong\PingPongServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PingPongServiceProvider::class,
        ];
    }
}
