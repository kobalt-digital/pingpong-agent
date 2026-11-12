<?php

namespace KobaltDigital\PingPong\Delivery;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\InvalidArgumentException as InvalidRequestException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Why a request did not reach PingPong, as the tick reports it afterwards.
 */
readonly class Failure
{
    public const MESSAGE_MAX_LENGTH = 255;

    public function __construct(
        public FailureKind $kind,
        public string $message,
    ) {}

    /**
     * Null for an exception that did not come from the HTTP client, such as
     * a malformed configuration, or for a request Guzzle could not build,
     * such as a message that is not UTF-8: PingPong may be reachable all
     * along, and sending the same request again fails the same way.
     */
    public static function fromException(Throwable $exception): ?self
    {
        if (! $exception instanceof HttpClientException && ! $exception instanceof GuzzleException) {
            return null;
        }

        if ($exception instanceof InvalidRequestException) {
            return null;
        }

        $kind = FailureKind::fromCurlError(self::curlErrorNumber($exception), self::messages($exception));

        return new self($kind, Str::limit($exception->getMessage(), self::MESSAGE_MAX_LENGTH - 3));
    }

    public static function fromResponse(Response $response): self
    {
        $kind = $response->status() === 429
            ? FailureKind::RATE_LIMITED
            : FailureKind::HTTP_5XX;

        return new self($kind, trim("HTTP {$response->status()} {$response->reason()}"));
    }

    /**
     * Guzzle 7 keeps the number in the handler context, Guzzle 8 only in
     * the message, which both start with "cURL error <number>:".
     */
    private static function curlErrorNumber(Throwable $exception): ?int
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            $context = method_exists($cause, 'getHandlerContext') ? $cause->getHandlerContext() : [];

            if (is_array($context) && is_int($context['errno'] ?? null)) {
                return $context['errno'];
            }
        }

        if (preg_match('/cURL error (\d+):/', self::messages($exception), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * Laravel can wrap the Guzzle exception under a message of its own.
     */
    private static function messages(Throwable $exception): string
    {
        $messages = [];

        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            $messages[] = $cause->getMessage();
        }

        return implode("\n", $messages);
    }
}
