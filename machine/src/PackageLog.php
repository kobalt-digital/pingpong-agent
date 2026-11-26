<?php

namespace KobaltDigital\PingPong\Machine;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * The Package changes dpkg logged since the last tick PingPong accepted,
 * read from where that tick left `/var/log/dpkg.log`: its inode and the
 * byte after the last line it sent. Only what changed a package's version
 * counts (install, upgrade, remove, purge, and an upgrade to an older
 * version as a downgrade); `configure`,
 * `trigproc`, `status` and `startup` lines are passed over.
 *
 * logrotate renames the log to `dpkg.log.1` once a month and leaves it
 * uncompressed until the next rotation, so a tick that finds a new inode
 * finishes the old file from where it was before it starts the new one at
 * its first byte. A log that shrank under the same inode was truncated and
 * is read from the start.
 *
 * The first read starts at the end of the log: history starts with the
 * first tick, a year of it is not sent at once. A tick carries at most
 * `MAX_CHANGES`; the rest follow in the next ticks.
 *
 * dpkg writes its own local time without a zone, so every change is sent
 * with the offset the machine's timezone had at that moment, and with who
 * made it as `AptHistory` tells.
 *
 * @phpstan-type LoggedChange array{local: string, action: string, package: string, from: ?string, to: ?string}
 * @phpstan-type PackageChange array{at: string, action: string, package: string, from: ?string, to: ?string, by: ?string}
 * @phpstan-type Cursor array{inode: int, offset: int}
 */
class PackageLog
{
    public const MAX_CHANGES = 200;

    /** Bytes one tick reads at most, so a log that grew a lot is caught up over a few ticks. */
    public const MAX_BYTES = 1_048_576;

    private const LINE = '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) (install|upgrade|remove|purge) (\S{1,255}) (\S{1,255}) (\S{1,255})$/';

    public function __construct(
        private string $dpkgLog = '/var/log/dpkg.log',
        private AptHistory $aptHistory = new AptHistory,
        private ?string $timezone = null,
        private string $localtime = '/etc/localtime',
        private string $timezoneFile = '/etc/timezone',
    ) {}

    /**
     * @return array{changes: list<PackageChange>, cursor: Cursor}|null null when the log cannot be read
     */
    public function read(mixed $cursor): ?array
    {
        $log = @fopen($this->dpkgLog, 'r');

        if ($log === false) {
            return null;
        }

        try {
            $stat = @fstat($log);

            if ($stat === false) {
                return null;
            }

            $inode = (int) $stat['ino'];
            $size = (int) $stat['size'];
            $cursor = $this->validCursor($cursor);

            if ($cursor === null) {
                return ['changes' => [], 'cursor' => ['inode' => $inode, 'offset' => $size]];
            }

            $changes = [];
            $bytesLeft = self::MAX_BYTES;
            $start = $cursor['offset'] <= $size ? $cursor['offset'] : 0;

            if ($cursor['inode'] !== $inode) {
                $rotated = $this->finishRotated($cursor, $changes, $bytesLeft);

                if ($rotated !== null) {
                    return $this->toSend($changes, $rotated);
                }

                $start = 0;
            }

            $offset = $this->readLines($log, $start, $changes, $bytesLeft, finished: false);

            return $this->toSend($changes, ['inode' => $inode, 'offset' => $offset]);
        } finally {
            fclose($log);
        }
    }

    /**
     * Reads what the tick before had not reached of the log logrotate moved
     * aside. Gives the cursor to resume in it when the budget ran out before
     * its end, null when it was read to the end or is not there.
     *
     * @param  Cursor  $cursor
     * @param  list<LoggedChange>  $changes
     * @return Cursor|null
     */
    private function finishRotated(array $cursor, array &$changes, int &$bytesLeft): ?array
    {
        $rotated = @fopen("{$this->dpkgLog}.1", 'r');

        if ($rotated === false) {
            return null;
        }

        try {
            $stat = @fstat($rotated);

            if ($stat === false || (int) $stat['ino'] !== $cursor['inode']) {
                return null;
            }

            $size = (int) $stat['size'];
            $offset = $this->readLines($rotated, $cursor['offset'] <= $size ? $cursor['offset'] : 0, $changes, $bytesLeft, finished: true);

            return $offset < $size ? ['inode' => $cursor['inode'], 'offset' => $offset] : null;
        } finally {
            fclose($rotated);
        }
    }

    /**
     * Reads whole lines from the offset until the file ends, the tick has
     * `MAX_CHANGES` or it read `MAX_BYTES`, and gives the offset after the
     * last line read. A last line without its newline is still being
     * written, unless the file is finished.
     *
     * @param  resource  $file
     * @param  list<LoggedChange>  $changes
     */
    private function readLines($file, int $offset, array &$changes, int &$bytesLeft, bool $finished): int
    {
        if (@fseek($file, $offset) !== 0) {
            return $offset;
        }

        while (count($changes) < self::MAX_CHANGES && $bytesLeft > 0) {
            $line = fgets($file);

            if ($line === false) {
                break;
            }

            if (! $finished && ! str_ends_with($line, "\n")) {
                break;
            }

            $offset += strlen($line);
            $bytesLeft -= strlen($line);
            $change = $this->parse($line);

            if ($change !== null) {
                $changes[] = $change;
            }
        }

        return $offset;
    }

    /** @return LoggedChange|null */
    public function parse(string $line): ?array
    {
        if (preg_match(self::LINE, rtrim($line, "\r\n"), $fields) !== 1) {
            return null;
        }

        $from = $fields[4] === '<none>' ? null : $fields[4];
        $to = $fields[5] === '<none>' ? null : $fields[5];

        return [
            'local' => $fields[1],
            'action' => $this->action($fields[2], $from, $to),
            'package' => $fields[3],
            'from' => $from,
            'to' => $to,
        ];
    }

    /** dpkg logs a downgrade as an upgrade to an older version. */
    private function action(string $logged, ?string $from, ?string $to): string
    {
        if ($logged !== 'upgrade' || $from === null || $to === null) {
            return $logged;
        }

        return DebianVersion::compare($to, $from) < 0 ? 'downgrade' : 'upgrade';
    }

    /**
     * @param  list<LoggedChange>  $changes
     * @param  Cursor  $cursor
     * @return array{changes: list<PackageChange>, cursor: Cursor}
     */
    private function toSend(array $changes, array $cursor): array
    {
        if ($changes === []) {
            return ['changes' => [], 'cursor' => $cursor];
        }

        $timezone = $this->machineTimezone();
        $who = $this->aptHistory->who(array_column($changes, 'local'));
        $sent = [];

        foreach ($changes as $index => $change) {
            $at = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $change['local'], $timezone);

            if ($at === false) {
                continue;
            }

            $sent[] = [
                'at' => $at->format('Y-m-d\TH:i:sP'),
                'action' => $change['action'],
                'package' => $change['package'],
                'from' => $change['from'],
                'to' => $change['to'],
                'by' => $who[$index] ?? null,
            ];
        }

        return ['changes' => $sent, 'cursor' => $cursor];
    }

    /**
     * The zone dpkg wrote its times in: the one `/etc/localtime` links to,
     * else the name in `/etc/timezone`, else UTC. PHP's own default zone is
     * not the machine's.
     */
    private function machineTimezone(): DateTimeZone
    {
        $link = @readlink($this->localtime);
        $candidates = [
            $this->timezone,
            is_string($link) && str_contains($link, 'zoneinfo/') ? substr($link, (int) strrpos($link, 'zoneinfo/') + 9) : null,
            trim((string) @file_get_contents($this->timezoneFile)),
        ];

        foreach ($candidates as $name) {
            if ($name === null || $name === '') {
                continue;
            }

            try {
                return new DateTimeZone($name);
            } catch (Exception) {
                continue;
            }
        }

        return new DateTimeZone('UTC');
    }

    /** @return Cursor|null */
    private function validCursor(mixed $cursor): ?array
    {
        if (! is_array($cursor) || ! is_int($cursor['inode'] ?? null) || ! is_int($cursor['offset'] ?? null) || $cursor['offset'] < 0) {
            return null;
        }

        return ['inode' => $cursor['inode'], 'offset' => $cursor['offset']];
    }
}
