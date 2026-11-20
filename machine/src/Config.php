<?php

namespace KobaltDigital\PingPong\Machine;

use RuntimeException;

/**
 * Where the machine agent finds PingPong and keeps what it remembers between
 * two runs. `install.sh` writes one of two files:
 *
 * - `/etc/pingpong-machine/config.json` on a machine it installed as root,
 *   with the state in `/var/lib/pingpong-machine`;
 * - `~/.config/pingpong-machine/config.json` for one user on shared hosting,
 *   with the state in `~/.local/state/pingpong-machine`.
 *
 * `PINGPONG_MACHINE_CONFIG` names another file, for tests and odd setups.
 * Besides `url` and `key` the file may name `mode` (`system` or `user`,
 * which otherwise follows where the file is).
 */
final class Config
{
    public const SYSTEM_FILE = '/etc/pingpong-machine/config.json';

    public const SYSTEM_STATE = '/var/lib/pingpong-machine';

    public const SYSTEM_LIBRARY = '/usr/local/lib/pingpong-machine';

    public const USER_FILE = '.config/pingpong-machine/config.json';

    public const USER_STATE = '.local/state/pingpong-machine';

    public function __construct(
        public readonly string $url,
        public readonly string $key,
        public readonly string $stateDirectory,
        public readonly bool $system = false,
    ) {}

    public static function load(): self
    {
        $file = self::file();
        $contents = @file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException("Cannot read {$file}. Run install.sh, or point PINGPONG_MACHINE_CONFIG at the config file.");
        }

        $system = self::isSystem($file);

        return self::parse($contents, $system ? self::SYSTEM_STATE : self::home().'/'.self::USER_STATE, $system);
    }

    /**
     * @param  bool  $system  installed by root for the whole machine, rather than by one user for themselves
     */
    public static function parse(string $json, string $defaultState, bool $system = false): self
    {
        $values = json_decode($json, true);

        if (! is_array($values)) {
            throw new RuntimeException('The config file does not hold a JSON object.');
        }

        $url = $values['url'] ?? null;
        $key = $values['key'] ?? null;

        if (! is_string($url) || ! is_string($key) || $key === '') {
            throw new RuntimeException('The config file needs a "url" and a "key".');
        }

        /* The key travels in every request: never in the clear, except to this machine itself. */
        $host = parse_url($url, PHP_URL_HOST);

        if (! str_starts_with($url, 'https://') && ! (str_starts_with($url, 'http://') && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true))) {
            throw new RuntimeException('The "url" must start with https://.');
        }

        $state = is_string($values['state_dir'] ?? null) && $values['state_dir'] !== '' ? $values['state_dir'] : $defaultState;
        $mode = $values['mode'] ?? null;

        return new self(rtrim($url, '/'), $key, rtrim($state, '/'), $mode === 'system' || ($mode !== 'user' && $system));
    }

    public static function file(): string
    {
        $override = getenv('PINGPONG_MACHINE_CONFIG');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        if (@is_file(self::SYSTEM_FILE)) {
            return self::SYSTEM_FILE;
        }

        return self::home().'/'.self::USER_FILE;
    }

    private static function isSystem(string $file): bool
    {
        return $file === self::SYSTEM_FILE;
    }

    public static function home(): string
    {
        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $entry = @posix_getpwuid(posix_geteuid());

            if (is_array($entry) && $entry['dir'] !== '') {
                return rtrim($entry['dir'], '/');
            }
        }

        throw new RuntimeException('Cannot tell the home directory: set HOME.');
    }
}
