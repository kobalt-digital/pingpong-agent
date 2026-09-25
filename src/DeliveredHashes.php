<?php

namespace KobaltDigital\PingPong;

use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Remembers, per server, the hash of the last list PingPong accepted, so a
 * list that did not change is not sent again every minute.
 */
class DeliveredHashes
{
    public function __construct(private Repository $cache) {}

    /**
     * When the cache cannot tell, the list is new: once too often is harmless.
     */
    public function isNew(string $subject, string $hash): bool
    {
        try {
            return $this->cache->get($this->key($subject)) !== $hash;
        } catch (Throwable) {
            return true;
        }
    }

    public function remember(string $subject, string $hash): void
    {
        try {
            $this->cache->forever($this->key($subject), $hash);
        } catch (Throwable) {
            return;
        }
    }

    private function key(string $subject): string
    {
        return "pingpong-agent:{$subject}-hash:".Server::name();
    }
}
