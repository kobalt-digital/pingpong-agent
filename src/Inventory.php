<?php

namespace KobaltDigital\PingPong;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;
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
        $contents = file_get_contents($this->lockPath());

        if ($contents === false) {
            throw new RuntimeException('Could not read composer.lock.');
        }

        $lock = json_decode($contents, flags: JSON_THROW_ON_ERROR);

        if (! $lock instanceof stdClass) {
            throw new RuntimeException('composer.lock must contain an object.');
        }

        $packages = [
            ...$this->packages(property_exists($lock, 'packages') ? $lock->packages : [], false),
            ...$this->packages(property_exists($lock, 'packages-dev') ? $lock->{'packages-dev'} : [], true),
        ];

        return array_values(collect($packages)->sortBy('name')->all());
    }

    /**
     * @return list<array{name: string, version: string, dev: bool}>
     */
    private function packages(mixed $entries, bool $dev): array
    {
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new RuntimeException('composer.lock packages must be a list.');
        }

        $packages = [];

        foreach ($entries as $entry) {
            if (! $entry instanceof stdClass || ! is_string($entry->name ?? null) || ! is_string($entry->version ?? null)) {
                throw new RuntimeException('composer.lock packages must have a string name and version.');
            }

            $packages[] = ['name' => $entry->name, 'version' => $entry->version, 'dev' => $dev];
        }

        return $packages;
    }

    private function lockPath(): string
    {
        return $this->app->basePath('composer.lock');
    }
}
