<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Contracts\Foundation\Application;
use Throwable;

class DiskSignal
{
    use CollectsFacts;

    public function __construct(private Application $app) {}

    /**
     * @return array{
     *     free_bytes: ?int,
     *     total_bytes: ?int,
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

        return [
            'free_bytes' => (int) $freeBytes,
            'total_bytes' => (int) $totalBytes,
            'error' => null,
        ];
    }

    /**
     * @return array{
     *     free_bytes: null,
     *     total_bytes: null,
     *     error: string
     * }
     */
    private function unreadable(string $message): array
    {
        return [
            'free_bytes' => null,
            'total_bytes' => null,
            'error' => $this->error($message),
        ];
    }
}
