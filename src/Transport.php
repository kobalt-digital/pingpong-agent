<?php

namespace KobaltDigital\PingPong;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Throwable;

class Transport
{
    public const SCHEMA = 1;

    public const TIMEOUT_SECONDS = 5;

    public const MAX_REDIRECTS = 3;

    public function __construct(private Application $app) {}

    /**
     * The one place that decides whether the Agent talks to PingPong at all:
     * not without a key, not when disabled, and never from the host app's
     * unit tests, which would otherwise report to the real Monitor.
     */
    public function shouldSend(): bool
    {
        if (! config('pingpong-agent.enabled')) {
            return false;
        }

        if (blank(config('pingpong-agent.key'))) {
            return false;
        }

        return ! $this->app->runningUnitTests();
    }

    /**
     * Never throws: PingPong being down must not break the host app.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(string $path, array $payload): bool
    {
        return $this->deliver($path, $payload) !== null;
    }

    /**
     * Like send, but hands back PingPong's answer when it was delivered, for
     * the callers that read it. Null when it was not.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliver(string $path, array $payload): ?Response
    {
        if (! $this->shouldSend()) {
            return null;
        }

        $endpoint = rtrim(config('pingpong-agent.endpoint'), '/');
        $key = config('pingpong-agent.key');

        try {
            $response = Http::baseUrl($endpoint)
                ->withToken($key)
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => [
                    'max' => self::MAX_REDIRECTS,
                    'strict' => true,
                    'track_redirects' => true,
                ]])
                ->withRequestMiddleware(fn (RequestInterface $request) => $this->keepKeyOnEndpointHost($request, $endpoint, $key))
                ->post($path, [
                    'schema' => self::SCHEMA,
                    'server' => Server::name(),
                    ...$payload,
                ]);
        } catch (Throwable $exception) {
            Log::warning("PingPong Agent could not reach PingPong at {$path}.", [
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        $this->warnWhenRedirected($path, $response);

        return $this->wasDelivered($path, $response) ? $response : null;
    }

    /**
     * Guzzle drops the key on a cross-origin redirect, and http to https is
     * one. The key goes back on only while the redirect stays on the
     * configured host, so it never follows a redirect elsewhere.
     */
    private function keepKeyOnEndpointHost(RequestInterface $request, string $endpoint, string $key): RequestInterface
    {
        if ($request->hasHeader('Authorization') || $request->getUri()->getHost() !== parse_url($endpoint, PHP_URL_HOST)) {
            return $request;
        }

        return $request->withHeader('Authorization', "Bearer {$key}");
    }

    /**
     * Redirects are followed in strict mode so the POST survives a 301 or 302,
     * but the endpoint is still wrong, so say which URL to configure.
     */
    private function warnWhenRedirected(string $path, Response $response): void
    {
        $history = $response->toPsrResponse()->getHeader('X-Guzzle-Redirect-History');

        if ($history === []) {
            return;
        }

        $finalUrl = end($history);
        $suffix = '/'.ltrim($path, '/');

        if (str_ends_with($finalUrl, $suffix)) {
            $finalUrl = substr($finalUrl, 0, -strlen($suffix));
        }

        Log::warning("PingPong endpoint redirected to {$finalUrl}; set PINGPONG_ENDPOINT to that URL (usually https).", [
            'path' => $path,
            'redirects' => count($history),
        ]);
    }

    private function wasDelivered(string $path, Response $response): bool
    {
        if ($response->status() === 429) {
            Log::info("PingPong Agent was rate limited at {$path}, skipping.", [
                'retry_after' => $response->header('Retry-After'),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning("PingPong rejected the Agent at {$path}.", [
                'status' => $response->status(),
            ]);

            return false;
        }

        return true;
    }
}
