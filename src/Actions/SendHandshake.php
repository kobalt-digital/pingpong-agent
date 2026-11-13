<?php

namespace KobaltDigital\PingPong\Actions;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use KobaltDigital\PingPong\AgentVersion;
use KobaltDigital\PingPong\Host;
use KobaltDigital\PingPong\Server;
use KobaltDigital\PingPong\Transport;
use Throwable;

class SendHandshake
{
    public const RETRY_AFTER_SECONDS = 600;

    public const REFRESH_AFTER_SECONDS = 3600;

    public function __construct(
        private Transport $transport,
        private AgentVersion $agentVersion,
        private Repository $cache,
        private Application $app,
        private Host $host,
    ) {}

    /**
     * Runs on every terminating app, so a broken cache store must not turn
     * into an exception in the host app either.
     */
    public function execute(): void
    {
        if (! $this->transport->shouldSend()) {
            return;
        }

        try {
            $this->sendWhenDue($this->agentVersion->current());
        } catch (Throwable $exception) {
            Log::warning('PingPong Agent could not send its handshake.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function sendWhenDue(string $version): void
    {
        $identity = hash('sha256', serialize([
            config()->string('pingpong-agent.endpoint'),
            config()->string('pingpong-agent.key'),
            Server::name(),
            $version,
            PHP_VERSION,
            $this->app->version(),
        ]));
        $cacheKey = "pingpong-agent:handshake-fresh:{$identity}";

        if ($this->cache->has($cacheKey)) {
            return;
        }

        if (! $this->cache->add("{$cacheKey}:attempt", true, self::RETRY_AFTER_SECONDS)) {
            return;
        }

        $delivered = $this->transport->send('api/agent/handshake', [
            'agent_version' => $version,
            'php_version' => PHP_VERSION,
            'laravel_version' => $this->app->version(),
            'os' => PHP_OS_FAMILY,
            ...$this->cores(),
        ]);

        if (! $delivered) {
            return;
        }

        $this->cache->put($cacheKey, true, self::REFRESH_AFTER_SECONDS);
    }

    /** @return array{cores?: int} */
    private function cores(): array
    {
        $cores = $this->host->cores();

        if ($cores === null) {
            return [];
        }

        return ['cores' => $cores];
    }
}
