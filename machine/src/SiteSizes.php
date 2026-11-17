<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * How much room every site and every database takes, so PingPong can say
 * what filled a disk and what grew. `du` costs 8 to 17 microseconds a file:
 * a home directory with a million files takes up to twenty seconds, so this
 * runs once an hour (more often only while PingPong has a disk Finding
 * open), at the lowest priority and with a timeout.
 *
 * Of everything `du` prints only what tells sites apart is kept: the first
 * two levels under each root (`/home/forge`, `/home/forge/example.nl`) and
 * the folders that fill up on their own: logs, uploads, app storage and old
 * releases. Lines are filtered as they arrive, so the whole tree is never
 * held in memory.
 *
 * Whatever room is left of the folders sent goes to the largest folders
 * deeper down (`releases/20261117/vendor`), from `DEEP_MIN_BYTES`, so what
 * fills a site can be opened folder by folder. `du` counts them anyway, so
 * this costs no reading of the disk. A folder is never larger than the one
 * holding it, so in a scan that finished, taking the largest first never
 * sends a folder without the one above it (a folder `du` ran out of time on
 * can leave children without it), and the folders kept above can never be
 * pushed out by them. Nothing in or under a hidden folder (`.git`, `.ssh`, `.config`) is
 * sent from deeper down: those names tell more about a user than a site.
 */
class SiteSizes
{
    public const MAX_ENTRIES = 400;

    public const MAX_DEPTH = 6;

    public const KEEP_DEPTH = 2;

    /** The smallest folder deeper than `KEEP_DEPTH` that is sent, as room allows. */
    public const DEEP_MIN_BYTES = 100 * 1024 * 1024;

    /** How long one scan of every site may take in all. */
    public const BUDGET_SECONDS = 900;

    /** How long one folder under a root may take. */
    public const FOLDER_TIMEOUT_SECONDS = 300;

    public const DATABASE_TIMEOUT_SECONDS = 120;

    public const MYSQL_DIRECTORY = '/var/lib/mysql';

    /** Folders that grow by themselves, kept at any depth. */
    public const GROWING = ['/storage/logs', '/storage/app', '/wp-content/uploads', '/releases'];

    /** What MySQL keeps for itself, not a database of a site. */
    public const SYSTEM_DATABASES = ['mysql', 'performance_schema', 'sys', 'information_schema', 'lost+found'];

    /** Filesystems a scan stays out of: one that stops answering would hang `du` where not even a kill reaches it. */
    public const REMOTE_FILESYSTEMS = ['nfs', 'nfs4', 'cifs', 'smb3', 'smbfs', 'sshfs', 'ceph', 'glusterfs', '9p', 'davfs'];

    /** @var array<string, string>|null */
    private ?array $mountTypes = null;

    public function __construct(private Runner $runner, private Users $users, private string $mounts = '/proc/mounts') {}

    /**
     * Every folder one level under each folder of a root (a site in a home
     * directory, the app folders of a site in `/var/www`) gets a `du` of its
     * own, so one that holds millions of files only loses itself to the
     * timeout: `du` prints a folder once everything in it is counted, so a
     * single `du` over a whole home directory lost its total, and every
     * site after the one it was stuck in, the moment one site was too big.
     * The folder above is then the sum of what is in it. When a folder
     * under it ran out of time, that sum is only what was counted, and the
     * folder is unmeasured too: PingPong reads it as "at least".
     *
     * @param  list<string>  $roots
     * @return array{entries: list<array{path: string, bytes: int, owner: ?string}>, complete: bool, unmeasured: list<string>}
     */
    public function sites(array $roots, string $du, int $budgetSeconds = self::BUDGET_SECONDS): array
    {
        $deadline = microtime(true) + $budgetSeconds;
        $entries = [];
        $deep = [];
        $unmeasured = [];

        foreach ($roots as $root) {
            $root = rtrim($root, '/');

            foreach ($this->folders($root) as $folder) {
                $children = $this->folders($folder);

                if ($children === []) {
                    $this->measure($folder, $root, $du, self::MAX_DEPTH - 1, $deadline, $entries, $deep, $unmeasured);

                    continue;
                }

                $bytes = $this->looseBytes($folder);
                $counted = true;

                foreach ($children as $child) {
                    $total = $this->measure($child, $root, $du, self::MAX_DEPTH - 2, $deadline, $entries, $deep, $unmeasured);
                    $bytes += $total ?? 0;
                    $counted = $counted && $total !== null;
                }

                if (! $counted) {
                    $unmeasured[] = $folder;
                }

                $entries[] = ['path' => $folder, 'bytes' => $bytes];
            }
        }

        usort($entries, self::largestFirst(...));
        usort($deep, self::largestFirst(...));

        $entries = array_slice($entries, 0, self::MAX_ENTRIES);
        $entries = [...$entries, ...array_slice($deep, 0, self::MAX_ENTRIES - count($entries))];
        usort($entries, self::largestFirst(...));

        return [
            'entries' => array_map(fn (array $entry): array => [...$entry, 'owner' => $this->owner($entry['path'], $roots)], $entries),
            'complete' => $unmeasured === [],
            'unmeasured' => array_slice($unmeasured, 0, self::MAX_ENTRIES),
        ];
    }

    /**
     * One `du` of a folder, its lines sorted into what is always sent and
     * what is sent as room allows. The folder's own total, or null when `du`
     * ran out of time on it or there was no time left to start: then it is
     * unmeasured, and only the folders inside it that `du` finished count.
     *
     * @param  list<array{path: string, bytes: int}>  $entries
     * @param  list<array{path: string, bytes: int}>  $deep
     * @param  list<string>  $unmeasured
     */
    private function measure(string $folder, string $root, string $du, int $depth, float $deadline, array &$entries, array &$deep, array &$unmeasured): ?int
    {
        $left = (int) floor($deadline - microtime(true));

        if ($left < 1) {
            $unmeasured[] = $folder;

            return null;
        }

        $total = null;

        $result = $this->runner->run(
            $this->lowPriority([$du, '-0', '-k', '-x', '-d', (string) $depth, $folder]),
            min($left, self::FOLDER_TIMEOUT_SECONDS),
            function (string $line) use ($folder, $root, &$entries, &$deep, &$total): void {
                $entry = $this->parseLine($line);

                if ($entry === null) {
                    return;
                }

                if ($entry['path'] === $folder) {
                    $total = $entry['bytes'];
                }

                if ($this->keeps($entry['path'], $root)) {
                    $entries[] = $entry;
                } elseif ($this->keepsDeep($entry['path'], $root, $entry['bytes'])) {
                    $deep[] = $entry;
                }
            },
            "\0",
        );

        /* du exits 1 when it could not read a folder somewhere, and still
           prints everything else, so only a timeout loses it. */
        if ($result === null || $result['timed_out']) {
            $unmeasured[] = $folder;

            return null;
        }

        return $total;
    }

    /**
     * What a folder holds outside the folders in it: its own entry and the
     * files right in it (a home directory's dotfiles), counted in blocks as
     * `du` counts them.
     */
    private function looseBytes(string $folder): int
    {
        $bytes = $this->blockBytes($folder);

        foreach (@scandir($folder) ?: [] as $name) {
            $path = "{$folder}/{$name}";

            if ($name !== '.' && $name !== '..' && (! @is_dir($path) || @is_link($path))) {
                $bytes += $this->blockBytes($path);
            }
        }

        return $bytes;
    }

    /** The room one entry takes on disk, in bytes, without following a link. */
    private function blockBytes(string $path): int
    {
        $stat = @lstat($path);

        return $stat === false ? 0 : max(0, $stat['blocks']) * 512;
    }

    /**
     * The folders right under a root, not following links.
     *
     * @return list<string>
     */
    public function folders(string $root): array
    {
        $folders = [];

        foreach (@scandir($root) ?: [] as $name) {
            $path = "{$root}/{$name}";

            if ($name !== '.' && $name !== '..' && @is_dir($path) && ! @is_link($path) && ! $this->isRemote($path)) {
                $folders[] = $path;
            }
        }

        return $folders;
    }

    /**
     * Whether the path lies on a network filesystem or one in user space
     * (FUSE), by the mount that holds it: the longest mount point it starts
     * with.
     */
    public function isRemote(string $path): bool
    {
        $type = null;
        $longest = -1;

        foreach ($this->mountTypes() as $mountPoint => $mountType) {
            $prefix = rtrim($mountPoint, '/').'/';

            if (($path === $mountPoint || str_starts_with($path.'/', $prefix)) && strlen($mountPoint) > $longest) {
                $type = $mountType;
                $longest = strlen($mountPoint);
            }
        }

        return $type !== null && (in_array($type, self::REMOTE_FILESYSTEMS, true) || str_starts_with($type, 'fuse.'));
    }

    /**
     * Every mount point with its type, from `/proc/mounts`, where a space in
     * a path is written as `\040`.
     *
     * @return array<string, string>
     */
    private function mountTypes(): array
    {
        if ($this->mountTypes !== null) {
            return $this->mountTypes;
        }

        $this->mountTypes = [];

        foreach (preg_split('/\R/', (string) @file_get_contents($this->mounts)) ?: [] as $line) {
            $fields = explode(' ', $line);

            if (count($fields) >= 3) {
                $this->mountTypes[stripcslashes($fields[1])] = $fields[2];
            }
        }

        return $this->mountTypes;
    }

    /**
     * Every database by its folder in MySQL's data directory. Null where
     * that folder is missing or cannot be read.
     *
     * @return list<array{name: string, bytes: int}>|null
     */
    public function databases(string $du, string $directory = self::MYSQL_DIRECTORY): ?array
    {
        if (! @is_dir($directory)) {
            return null;
        }

        $databases = [];

        $result = $this->runner->run(
            $this->lowPriority([$du, '-0', '-k', '-d', '1', $directory]),
            self::DATABASE_TIMEOUT_SECONDS,
            function (string $line) use ($directory, &$databases): void {
                $entry = $this->parseLine($line);

                if ($entry === null || dirname($entry['path']) !== rtrim($directory, '/')) {
                    return;
                }

                $name = $this->databaseName(basename($entry['path']));

                if ($name !== null) {
                    $databases[] = ['name' => $name, 'bytes' => $entry['bytes']];
                }
            },
            "\0",
        );

        if ($result === null || ($databases === [] && $result['exit'] !== 0)) {
            return null;
        }

        usort($databases, fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return $databases;
    }

    /**
     * One entry of `du -0 -k`: kilobytes, a tab, the path. Entries end in a
     * NUL rather than a newline, which a folder name can hold: a user must
     * not be able to name a folder so it reads as the size of another's.
     *
     * @return array{path: string, bytes: int}|null
     */
    public function parseLine(string $line): ?array
    {
        if (preg_match('/^(\d+)\t(.+)$/s', $line, $match) !== 1) {
            return null;
        }

        return ['path' => $match[2], 'bytes' => (int) $match[1] * 1024];
    }

    /**
     * Whether a path under a root is worth sending: the root and the two
     * levels under it, or a folder that fills up on its own.
     */
    public function keeps(string $path, string $root): bool
    {
        if (! str_starts_with($path, $root.'/')) {
            return false;
        }

        if (substr_count(substr($path, strlen($root)), '/') <= self::KEEP_DEPTH) {
            return true;
        }

        foreach (self::GROWING as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{path: string, bytes: int}  $a
     * @param  array{path: string, bytes: int}  $b
     */
    private static function largestFirst(array $a, array $b): int
    {
        return $b['bytes'] <=> $a['bytes'] ?: strcmp($a['path'], $b['path']);
    }

    /**
     * Whether a folder deeper than `KEEP_DEPTH` is large enough to send when
     * there is room, and neither hidden nor inside a hidden folder.
     */
    public function keepsDeep(string $path, string $root, int $bytes): bool
    {
        if ($bytes < self::DEEP_MIN_BYTES || ! str_starts_with($path, $root.'/')) {
            return false;
        }

        $segments = explode('/', ltrim(substr($path, strlen($root)), '/'));

        if (count($segments) <= self::KEEP_DEPTH) {
            return false;
        }

        /* Under a home or site folder: nothing in a hidden folder, however deep. */
        foreach (array_slice($segments, 1) as $segment) {
            if (str_starts_with($segment, '.')) {
                return false;
            }
        }

        return true;
    }

    /**
     * MySQL writes a character a file name cannot hold as `@` and four hex
     * digits: `my@002dshop` is the database `my-shop`. Its own folders
     * (`#innodb_redo`) and system databases are no site's.
     */
    public function databaseName(string $folder): ?string
    {
        if ($folder === '' || str_starts_with($folder, '#') || in_array($folder, self::SYSTEM_DATABASES, true)) {
            return null;
        }

        return (string) preg_replace_callback('/@([0-9a-f]{4})/', fn (array $match): string => mb_chr((int) hexdec($match[1]), 'UTF-8') ?: $match[0], $folder);
    }

    /**
     * The user that owns a folder. A folder inside a home directory that is
     * closed to others cannot be looked at without root; it belongs to the
     * owner of the first folder above it that can, which is that home.
     *
     * @param  list<string>  $roots
     */
    private function owner(string $path, array $roots): ?string
    {
        while (true) {
            $uid = @fileowner($path);

            if ($uid !== false) {
                return $this->users->name($uid);
            }

            $parent = dirname($path);

            if ($parent === $path || in_array($path, $roots, true)) {
                return null;
            }

            $path = $parent;
        }
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    private function lowPriority(array $command): array
    {
        $ionice = $this->runner->find('ionice');
        $nice = $this->runner->find('nice');

        if ($ionice !== null) {
            $command = [$ionice, '-c', '3', ...$command];
        }

        if ($nice !== null) {
            $command = [$nice, '-n', '19', ...$command];
        }

        return $command;
    }
}
