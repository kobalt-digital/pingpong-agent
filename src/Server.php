<?php

namespace KobaltDigital\PingPong;

class Server
{
    /**
     * All servers of a Monitor share one key, so the hostname tells them apart.
     */
    public static function name(): string
    {
        return gethostname() ?: 'unknown';
    }
}
