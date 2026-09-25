<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Support\Str;

trait CollectsFacts
{
    private function millisecondsSince(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }

    /**
     * PingPong stores an error in a 255 character column.
     */
    private function error(string $message): string
    {
        return Str::limit($message, 252);
    }
}
