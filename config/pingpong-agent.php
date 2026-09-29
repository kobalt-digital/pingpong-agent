<?php

use KobaltDigital\PingPong\Signals;

return [

    /*
     * Switch the Agent off without removing the key. It is also silent
     * while the app runs its unit tests, whatever this says.
     */
    'enabled' => env('PINGPONG_ENABLED', true),

    /*
     * Where PingPong lives. Only change this to point an app at a local or
     * staging PingPong.
     */
    'endpoint' => env('PINGPONG_ENDPOINT', 'https://pingpong.kobaltdigital.nl'),

    /*
     * The Agent key of this app's Monitor, shown once when it is created in
     * PingPong. Without a key the Agent sends nothing and schedules nothing.
     */
    'key' => env('PINGPONG_KEY'),

    /*
     * Health signals the tick carries, one class per signal. Remove one to
     * stop sending it. Checks report their own failure and platform facts
     * leave themselves out, so a collector never needs removing because the
     * server lacks something.
     */
    'signals' => [
        Signals\Database::class,
        Signals\Cache::class,
        Signals\Disk::class,
        Signals\FailedJobs::class,
        Signals\Queue::class,
    ],

];
