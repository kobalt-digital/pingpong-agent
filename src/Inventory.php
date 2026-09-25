<?php

namespace KobaltDigital\PingPong;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the app runs on: PHP, Laravel and the packages in its composer.lock.
 */
class Inventory
{
    public function __construct(private Application $app) {}

    /**
     * Hashes the lock file as it is, so a tick only parses it once it changed.
     */
    public function hash(): string
    {
        try {
            $lockHash = is_file($this->lockPath()) ? hash_file('sha256', $this->lockPath()) : 'missing';
        } catch (Throwable) {
            $lockHash = 'unreadable';
        }

        return hash('sha256', implode('|', [PHP_VERSION, $this->app->version(), $lockHash]));
    }

    /**
     * @return array{
     *     php_version: string,
     *     laravel_version: string,
     *     packages: ?list<array{name: string, version: string, dev: bool}>,
     *     error: ?string
     * }
     */
    public function collect(): array
    {
        if (! is_file($this->lockPath())) {
            return $this->withPackages(null, 'There is no composer.lock in the base path of the app.');
        }

        try {
            return $this->withPackages($this->lockedPackages(), null);
        } catch (Throwable $exception) {
            return $this->withPackages(null, Str::limit($exception->getMessage(), 252));
        }
    }

    /**
     * @param  ?list<array{name: string, version: string, dev: bool}>  $packages
     * @return array{
     *     php_version: string,
     *     laravel_version: string,
     *     packages: ?list<array{name: string, version: string, dev: bool}>,
     *     error: ?string
     * }
     */
    private function withPackages(?array $packages, ?string $error): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => $this->app->version(),
            'packages' => $packages,
            'error' => $error,
        ];
    }

    /**
     * @return list<array{name: string, version: string, dev: bool}>
     */
    private function lockedPackages(): array
    {
        $lock = json_decode(file_get_contents($this->lockPath()), true, flags: JSON_THROW_ON_ERROR);

        $toPackages = fn (array $entries, bool $dev) => array_map(fn (array $entry) => [
            'name' => $entry['name'],
            'version' => $entry['version'],
            'dev' => $dev,
        ], $entries);

        return collect([
            ...$toPackages($lock['packages'] ?? [], false),
            ...$toPackages($lock['packages-dev'] ?? [], true),
        ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function lockPath(): string
    {
        return $this->app->basePath('composer.lock');
    }
}
