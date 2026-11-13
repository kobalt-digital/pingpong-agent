<?php

namespace KobaltDigital\PingPong\Delivery;

enum FailureKind: string
{
    case DNS = 'dns';
    case CONNECT = 'connect';
    case TIMEOUT = 'timeout';
    case TLS = 'tls';
    case HTTP_5XX = 'http_5xx';
    case RATE_LIMITED = 'rate_limited';
    case OTHER = 'other';

    /**
     * cURL reports a resolver that gives up as a timeout (28), so the
     * message tells it apart from a server that does not answer.
     */
    public static function fromCurlError(?int $errorNumber, string $message): self
    {
        if (str_contains($message, 'Resolving timed out')) {
            return self::DNS;
        }

        return match ($errorNumber) {
            6 => self::DNS,
            7 => self::CONNECT,
            28 => self::TIMEOUT,
            35, 51, 58, 60 => self::TLS,
            default => self::OTHER,
        };
    }
}
