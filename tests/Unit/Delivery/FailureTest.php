<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\InvalidArgumentException as GuzzleInvalidArgumentException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use KobaltDigital\PingPong\Delivery\Failure;
use KobaltDigital\PingPong\Delivery\FailureKind;

it('classifies a cURL error by its number and message', function (string $message, FailureKind $kind) {
    $failure = Failure::fromException(new ConnectionException($message));

    expect($failure->kind)->toBe($kind)
        ->and($failure->message)->toBe($message);
})->with([
    'resolving timed out' => ['cURL error 28: Resolving timed out after 5000 milliseconds', FailureKind::DNS],
    'unknown host' => ['cURL error 6: Could not resolve host: pingpong.kobaltdigital.nl', FailureKind::DNS],
    'refused' => ['cURL error 7: Failed to connect to pingpong.kobaltdigital.nl port 443', FailureKind::CONNECT],
    'timed out' => ['cURL error 28: Operation timed out after 5001 milliseconds with 0 bytes received', FailureKind::TIMEOUT],
    'handshake' => ['cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL', FailureKind::TLS],
    'peer verification' => ['cURL error 51: SSL: no alternative certificate subject name matches', FailureKind::TLS],
    'client certificate' => ['cURL error 58: unable to set private key file', FailureKind::TLS],
    'certificate' => ['cURL error 60: SSL certificate problem: certificate has expired', FailureKind::TLS],
    'other cURL error' => ['cURL error 52: Empty reply from server', FailureKind::OTHER],
    'no cURL error' => ['Will not follow more than 3 redirects', FailureKind::OTHER],
]);

it('reads the cURL error from the Guzzle exception Laravel wraps', function () {
    $guzzle = new ConnectException('cURL error 6: Could not resolve host: pingpong.kobaltdigital.nl', new Request('POST', 'https://pingpong.kobaltdigital.nl'));

    $failure = Failure::fromException(new ConnectionException('Connection failed', 0, $guzzle));

    expect($failure->kind)->toBe(FailureKind::DNS)
        ->and($failure->message)->toBe('Connection failed');
});

it('keeps the message within 255 characters', function () {
    $failure = Failure::fromException(new ConnectionException('cURL error 7: '.str_repeat('a', 300)));

    expect(mb_strlen($failure->message))->toBe(255);
});

it('does not count an exception outside the HTTP client as a failed delivery', function () {
    expect(Failure::fromException(new InvalidArgumentException('Configuration value for key [pingpong-agent.key] must be a string.')))->toBeNull();
});

it('does not count a request that could not be built as a failed delivery', function () {
    expect(Failure::fromException(new GuzzleInvalidArgumentException('json_encode error: Malformed UTF-8 characters, possibly incorrectly encoded')))->toBeNull();
});

it('classifies an answer by its status', function (int $status, FailureKind $kind, string $message) {
    $failure = Failure::fromResponse(new Response(new PsrResponse($status)));

    expect($failure->kind)->toBe($kind)
        ->and($failure->message)->toBe($message);
})->with([
    [500, FailureKind::HTTP_5XX, 'HTTP 500 Internal Server Error'],
    [503, FailureKind::HTTP_5XX, 'HTTP 503 Service Unavailable'],
    [429, FailureKind::RATE_LIMITED, 'HTTP 429 Too Many Requests'],
]);
