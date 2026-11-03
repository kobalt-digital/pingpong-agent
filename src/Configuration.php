<?php

namespace KobaltDigital\PingPong;

use InvalidArgumentException;

class Configuration
{
    public static function nullableString(string $key): ?string
    {
        $value = config($key);

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException("Configuration value for key [{$key}] must be a string or null.");
        }

        return $value;
    }
}
