<?php

namespace KobaltDigital\PingPong\Signals;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Exceptions\ReportableHandler;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use KobaltDigital\PingPong\Contracts\Collector;
use KobaltDigital\PingPong\InstalledPackages;
use KobaltDigital\PingPong\Server;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionProperty;
use Throwable;

/**
 * Whether an exception thrown in this app would reach Flare: a client is
 * installed, it has a key, the exception handler or the log stack hands
 * exceptions to it, and Flare accepts the key. Left out when neither
 * spatie/laravel-flare nor spatie/laravel-ignition is installed.
 *
 * Flare is asked about the key once an hour, and right away when the key
 * changes. It is asked with an empty report, which Flare accepts from a
 * valid key and rejects with a 403 from an invalid one, but never turns
 * into an error.
 */
class Flare implements Collector
{
    use CollectsFacts;

    public const LARAVEL_FLARE = 'spatie/laravel-flare';

    public const LARAVEL_IGNITION = 'spatie/laravel-ignition';

    public const KEY_CHECK_MINUTES = 60;

    public const TIMEOUT_SECONDS = 3;

    public const DAEMON_TIMEOUT_SECONDS = 1;

    private const FLARE_CLIENT = 'Spatie\FlareClient\Flare';

    private const DAEMON_SENDER = 'Spatie\FlareClient\Senders\DaemonSender';

    public function __construct(
        private InstalledPackages $packages,
        private Application $app,
        private Config $config,
        private Cache $cache,
    ) {}

    public function name(): string
    {
        return 'flare';
    }

    /** @return array{client: string, client_version: string, key_set: bool, key_hash: ?string, report: ?bool, handler_wired: ?bool, sender: ?string, daemon_reachable: ?bool, key_accepted: ?bool, key_status: ?int, key_checked_at: ?string, error: ?string}|null */
    public function collect(): ?array
    {
        $client = $this->client();

        if ($client === null) {
            return null;
        }

        [$name, $version] = $client;

        $key = $this->config->get('flare.key');
        $key = is_string($key) && trim($key) !== '' ? $key : null;

        $handlesItself = $name === self::LARAVEL_FLARE;
        $sender = $handlesItself ? $this->sender() : null;
        $check = $key === null ? null : $this->keyCheck($key, $handlesItself);

        return [
            'client' => $name,
            'client_version' => $version,
            'key_set' => $key !== null,
            'key_hash' => $key === null ? null : substr(hash('sha256', $key), 0, 8),
            'report' => $handlesItself ? (bool) $this->config->get('flare.report', true) : null,
            'handler_wired' => $handlesItself ? $this->handlerReportsToFlare() : $this->logStackReachesFlare(),
            'sender' => $sender,
            'daemon_reachable' => $sender === 'daemon' ? $this->daemonReachable() : null,
            'key_accepted' => $check['accepted'] ?? null,
            'key_status' => $check['status'] ?? null,
            'key_checked_at' => $check['checked_at'] ?? null,
            'error' => $check['error'] ?? null,
        ];
    }

    /**
     * laravel-flare, the current client, is looked for first.
     *
     * @return array{0: string, 1: string}|null
     */
    private function client(): ?array
    {
        foreach ([self::LARAVEL_FLARE, self::LARAVEL_IGNITION] as $name) {
            $version = $this->packages->version($name);

            if ($version !== null) {
                return [$name, $version];
            }
        }

        return null;
    }

    /**
     * The last answer Flare gave about this key, asked again once it is an
     * hour old or was given about another key. When the cache cannot keep
     * the answer, Flare is asked every tick: once too often is harmless.
     *
     * @return array{key_hash: string, accepted: ?bool, status: ?int, error: ?string, checked_at: string}
     */
    private function keyCheck(string $key, bool $handlesItself): array
    {
        $cacheKey = 'pingpong-agent:flare-key-check:'.Server::name();
        $keyHash = hash('sha256', $key);

        try {
            $last = $this->cache->get($cacheKey);
        } catch (Throwable) {
            $last = null;
        }

        if ($this->stillFresh($last, $keyHash)) {
            /** @var array{key_hash: string, accepted: ?bool, status: ?int, error: ?string, checked_at: string} $last */
            return $last;
        }

        $check = [
            'key_hash' => $keyHash,
            ...$this->askFlare($key, $handlesItself),
            'checked_at' => Date::now()->toIso8601String(),
        ];

        try {
            $this->cache->forever($cacheKey, $check);
        } catch (Throwable) {
            // Asked again next tick.
        }

        return $check;
    }

    private function stillFresh(mixed $last, string $keyHash): bool
    {
        if (! is_array($last) || ($last['key_hash'] ?? null) !== $keyHash || ! is_string($last['checked_at'] ?? null)) {
            return false;
        }

        try {
            return Date::parse($last['checked_at'])->gt(Date::now()->subMinutes(self::KEY_CHECK_MINUTES));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Sends an empty report to the endpoint the client reports to. A 401 or
     * 403 means Flare rejects the key; any other failure means the check
     * could not tell.
     *
     * @return array{accepted: ?bool, status: ?int, error: ?string}
     */
    private function askFlare(string $key, bool $handlesItself): array
    {
        try {
            $response = Http::withHeaders(['x-api-token' => $key])
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->post($this->reportsUrl($handlesItself), []);
        } catch (Throwable $exception) {
            return ['accepted' => null, 'status' => null, 'error' => $this->error('Flare could not be reached: '.$exception->getMessage())];
        }

        $status = $response->status();

        return match (true) {
            $response->successful() => ['accepted' => true, 'status' => $status, 'error' => null],
            in_array($status, [401, 403], true) => ['accepted' => false, 'status' => $status, 'error' => null],
            default => ['accepted' => null, 'status' => $status, 'error' => "Flare answered the key check with HTTP {$status}"],
        };
    }

    /**
     * Where the client sends its reports, honouring a base_url the app set.
     */
    private function reportsUrl(bool $handlesItself): string
    {
        $baseUrl = $this->config->get('flare.base_url');

        if ($handlesItself) {
            return rtrim(is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : 'https://ingress.flareapp.io', '/').'/v1/errors';
        }

        return rtrim(is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : 'https://flareapp.io/api', '/').'/reports';
    }

    /**
     * laravel-flare sends straight to Flare, or through a local daemon that
     * buffers. The daemon sender falls back to sending straight to Flare
     * when the daemon does not answer.
     */
    private function sender(): string
    {
        $class = $this->config->get('flare.sender.class');

        if (! is_string($class)) {
            return 'direct';
        }

        $isDaemon = $class === self::DAEMON_SENDER
            || (class_exists($class) && is_subclass_of($class, self::DAEMON_SENDER));

        return $isDaemon ? 'daemon' : 'direct';
    }

    private function daemonReachable(): bool
    {
        $url = $this->config->get('flare.sender.config.daemon_url');
        $url = rtrim(is_string($url) && $url !== '' ? $url : 'http://127.0.0.1:8787', '/');

        try {
            return Http::timeout(self::DAEMON_TIMEOUT_SECONDS)
                ->connectTimeout(self::DAEMON_TIMEOUT_SECONDS)
                ->get("{$url}/health")
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * laravel-flare only reports what the exception handler hands it, through
     * the callback `Flare::handles($exceptions)` registers in bootstrap/app.php.
     * That callback is recognised by its return type, as `flare:test` does.
     * Null when the handler is not one whose callbacks can be read.
     */
    private function handlerReportsToFlare(): ?bool
    {
        try {
            /** @var object $handler */
            $handler = $this->app->make(ExceptionHandler::class);

            // Collision wraps the app's handler in the console.
            if (property_exists($handler, 'appExceptionHandler')) {
                $handler = (new ReflectionProperty($handler, 'appExceptionHandler'))->getValue($handler);
            }

            if (! is_object($handler) || ! property_exists($handler, 'reportCallbacks')) {
                return null;
            }

            $callbacks = (new ReflectionProperty($handler, 'reportCallbacks'))->getValue($handler);

            foreach (is_iterable($callbacks) ? $callbacks : [] as $callback) {
                if ($this->reportsToFlare($callback)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return null;
        }
    }

    private function reportsToFlare(mixed $callback): bool
    {
        if (! $callback instanceof ReportableHandler) {
            return false;
        }

        $closure = (new ReflectionProperty($callback, 'callback'))->getValue($callback);

        if (! $closure instanceof Closure) {
            return false;
        }

        $returnType = (new ReflectionFunction($closure))->getReturnType();

        return $returnType instanceof ReflectionNamedType && $returnType->getName() === self::FLARE_CLIENT;
    }

    /**
     * Ignition reports exceptions through the log: Laravel logs every
     * reported exception to the default channel, so that channel, or a stack
     * it leads to, must use the `flare` driver.
     */
    private function logStackReachesFlare(): bool
    {
        return $this->channelReachesFlare($this->config->get('logging.default'), []);
    }

    /** @param  list<string>  $visited */
    private function channelReachesFlare(mixed $name, array $visited): bool
    {
        if (! is_string($name) || in_array($name, $visited, true)) {
            return false;
        }

        $channel = $this->config->get("logging.channels.{$name}");

        if (! is_array($channel)) {
            return false;
        }

        $driver = $channel['driver'] ?? null;

        if ($driver === 'flare') {
            return true;
        }

        if ($driver !== 'stack') {
            return false;
        }

        $channels = $channel['channels'] ?? [];
        $channels = is_string($channels) ? explode(',', $channels) : $channels;

        foreach (is_array($channels) ? $channels : [] as $stacked) {
            if ($this->channelReachesFlare(is_string($stacked) ? trim($stacked) : null, [...$visited, $name])) {
                return true;
            }
        }

        return false;
    }
}
