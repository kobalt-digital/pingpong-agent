<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Contracts\Foundation\Application;
use KobaltDigital\PingPong\Contracts\MachineSignal;
use KobaltDigital\PingPong\Host;
use Throwable;

class Disk implements MachineSignal
{
    use CollectsFacts;

    public function __construct(
        private Application $app,
        private Host $host,
    ) {}

    public function name(): string
    {
        return 'disk';
    }

    /**
     * @return array{
     *     free_bytes: ?int,
     *     total_bytes: ?int,
     *     inodes_total: ?int,
     *     inodes_free: ?int,
     *     error: ?string
     * }
     */
    public function collect(): array
    {
        $path = $this->app->basePath();

        try {
            $freeBytes = disk_free_space($path);
            $totalBytes = disk_total_space($path);
        } catch (Throwable $exception) {
            return $this->unreadable($exception->getMessage());
        }

        if ($freeBytes === false || $totalBytes === false) {
            return $this->unreadable("The disk space of {$path} could not be read.");
        }

        $inodes = $this->host->inodes($path);

        return [
            'free_bytes' => (int) $freeBytes,
            'total_bytes' => (int) $totalBytes,
            'inodes_total' => $inodes['total'] ?? null,
            'inodes_free' => $inodes['free'] ?? null,
            'error' => null,
        ];
    }

    /**
     * @return array{
     *     free_bytes: null,
     *     total_bytes: null,
     *     inodes_total: null,
     *     inodes_free: null,
     *     error: string
     * }
     */
    private function unreadable(string $message): array
    {
        return [
            'free_bytes' => null,
            'total_bytes' => null,
            'inodes_total' => null,
            'inodes_free' => null,
            'error' => $this->error($message),
        ];
    }
}
