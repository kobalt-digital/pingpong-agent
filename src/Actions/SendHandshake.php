<?php

namespace KobaltDigital\PingPong\Actions;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;
use KobaltDigital\PingPong\AgentVersion;
use KobaltDigital\PingPong\Transport;
use Throwable;

class SendHandshake
{
    public const RETRY_AFTER_SECONDS = 600;

    public function __construct(
        private Transport $transport,
        private AgentVersion $agentVersion,
        private Repository $cache,
        private Application $app,
    ) {}

    /**
     * Runs on every terminating app, so a broken cache store must not turn
     * into an exception in the host app either.
     */
    public function execute(): void
    {
        if (! $this->transport->isConfigured()) {
            return;
        }

        try {
            $this->sendOncePerVersion($this->agentVersion->current());
        } catch (Throwable $exception) {
            Log::warning('PingPong Agent could not send its handshake.', [
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function sendOncePerVersion(string $version): void
    {
        if ($this->cache->has("pingpong-agent:handshake:{$version}")) {
            return;
        }

        if (! $this->cache->add("pingpong-agent:handshake-attempt:{$version}", true, self::RETRY_AFTER_SECONDS)) {
            return;
        }

        $delivered = $this->transport->send('api/agent/handshake', [
            'agent_version' => $version,
            'php_version' => PHP_VERSION,
            'laravel_version' => $this->app->version(),
        ]);

        if (! $delivered) {
            return;
        }

        $this->cache->forever("pingpong-agent:handshake:{$version}", true);
    }
}
