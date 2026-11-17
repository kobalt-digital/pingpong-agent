<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * The name of a Unix user by its uid, from ext-posix when PHP has it and
 * from `/etc/passwd` when it does not. Users from a directory service (LDAP)
 * are only found through posix; a uid that is found nowhere keeps its
 * number, so its processes still add up under one name.
 */
class Users
{
    /** @var array<int, string> */
    private array $names = [];

    /** @var array<int, string>|null */
    private ?array $passwd = null;

    public function __construct(private string $passwdFile = '/etc/passwd', private bool $usePosix = true) {}

    public function name(int $uid): string
    {
        return $this->names[$uid] ??= $this->lookUp($uid);
    }

    /**
     * The user this process runs as.
     */
    public function current(): string
    {
        if ($this->usePosix && function_exists('posix_geteuid')) {
            return $this->name(posix_geteuid());
        }

        $user = getenv('USER');

        return is_string($user) && $user !== '' ? $user : 'unknown';
    }

    /**
     * Reads `/etc/passwd`: one user per line, `name:password:uid:...`.
     *
     * @return array<int, string>
     */
    public function parsePasswd(string $contents): array
    {
        $users = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $fields = explode(':', $line);

            if (count($fields) < 3 || $fields[0] === '' || ! ctype_digit($fields[2])) {
                continue;
            }

            $users[(int) $fields[2]] ??= $fields[0];
        }

        return $users;
    }

    private function lookUp(int $uid): string
    {
        if ($this->usePosix && function_exists('posix_getpwuid')) {
            $entry = @posix_getpwuid($uid);

            if (is_array($entry) && $entry['name'] !== '') {
                return $entry['name'];
            }
        }

        $this->passwd ??= $this->parsePasswd((string) @file_get_contents($this->passwdFile));

        return $this->passwd[$uid] ?? (string) $uid;
    }
}
