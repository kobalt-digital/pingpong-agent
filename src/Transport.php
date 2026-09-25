<?php

namespace KobaltDigital\PingPong;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Transport
{
    public const SCHEMA = 1;

    public const TIMEOUT_SECONDS = 5;

    public function isConfigured(): bool
    {
        return filled(config('pingpong-agent.key'));
    }

    /**
     * Never throws: PingPong being down must not break the host app.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(string $path, array $payload): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::baseUrl(rtrim(config('pingpong-agent.endpoint'), '/'))
                ->withToken(config('pingpong-agent.key'))
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post($path, [
                    'schema' => self::SCHEMA,
                    'server' => gethostname() ?: 'unknown',
                    ...$payload,
                ]);
        } catch (Throwable $exception) {
            Log::warning("PingPong Agent could not reach PingPong at {$path}.", [
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }

        return $this->wasDelivered($path, $response);
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
