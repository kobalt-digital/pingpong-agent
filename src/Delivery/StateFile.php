<?php

namespace KobaltDigital\PingPong\Delivery;

use Closure;
use KobaltDigital\PingPong\Server;
use RuntimeException;

/**
 * A small JSON file shared by the scheduler, tick and queue processes of one
 * server. The cache cannot hold this state: its store may be `array`, which
 * every process starts empty, and it is down exactly when the state matters.
 * Kept per server, since servers can share a storage directory.
 */
class StateFile
{
    public function __construct(private string $path) {}

    public static function named(string $name): self
    {
        $server = preg_replace('/[^A-Za-z0-9._-]/', '-', Server::name());

        return new self(storage_path("framework/pingpong-agent/{$server}/{$name}.json"));
    }

    /**
     * @return array<mixed>
     */
    public function read(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        return $this->locked(false, fn ($handle) => $this->decode($handle));
    }

    /**
     * Reads, changes and writes the state under one exclusive lock, so two
     * processes never overwrite each other's change.
     *
     * @param  Closure(array<mixed>): array<mixed>  $change
     */
    public function update(Closure $change): void
    {
        $this->ensureDirectoryExists();

        $this->locked(true, function ($handle) use ($change) {
            $state = $change($this->decode($handle));

            $contents = $state === [] ? '' : json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $contents);
            fflush($handle);

            return [];
        });
    }

    /**
     * Reading needs no write access, so a process running as another user
     * of the server still sees the state.
     *
     * @param  Closure(resource): array<mixed>  $callback
     * @return array<mixed>
     */
    private function locked(bool $exclusive, Closure $callback): array
    {
        $handle = fopen($this->path, $exclusive ? 'c+' : 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$this->path}.");
        }

        try {
            if (! flock($handle, $exclusive ? LOCK_EX : LOCK_SH)) {
                throw new RuntimeException("Cannot lock {$this->path}.");
            }

            return $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * A file that is empty or not JSON, for instance after a full disk cut
     * a write short, starts over empty.
     *
     * @param  resource  $handle
     * @return array<mixed>
     */
    private function decode($handle): array
    {
        rewind($handle);

        $contents = stream_get_contents($handle);

        if ($contents === false || $contents === '') {
            return [];
        }

        $state = json_decode($contents, true);

        return is_array($state) ? $state : [];
    }

    private function ensureDirectoryExists(): void
    {
        $directory = dirname($this->path);

        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }
    }
}
