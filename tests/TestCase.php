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

    /**
     * The Agent stays silent while the host app runs its unit tests. These
     * tests boot the app as "local" so they exercise sending; a test puts
     * "testing" back to cover the silence.
     */
    protected function defineEnvironment($app): void
    {
        $app['env'] = 'local';

        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.failed.database', 'testing');
    }
}
