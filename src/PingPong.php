<?php

namespace KobaltDigital\PingPong;

use Closure;

/**
 * What an app calls to tell PingPong about itself.
 *
 *     PingPong::metric('orders.pending', fn () => Order::pending()->count());
 *     PingPong::metric('import.lag', fn () => Import::lagInSeconds(), unit: 'seconds');
 */
class PingPong
{
    /**
     * Report a number of the app with every tick, read by `$resolve` on the
     * server that ticks. Registering a name again replaces it.
     *
     * @param  Closure(): (int|float)  $resolve
     * @param  'count'|'percent'|'ms'|'seconds'|'bytes'  $unit
     */
    public static function metric(string $name, Closure $resolve, string $unit = 'count'): void
    {
        app(Metrics::class)->register($name, $resolve, $unit);
    }
}
