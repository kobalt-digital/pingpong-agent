<?php

namespace KobaltDigital\PingPong\Machine;

final class OomKills
{
    private const MAX_EVENTS = 100;

    /**
     * Read the newest kills first and collapse repeated entries for a victim.
     *
     * @return list<array{at: int, pid: int, process: string, scope: string, uid: ?int, rss_bytes: int}>
     */
    public function read(string $path, int $now): array
    {
        $journal = @fopen($path, 'r');

        if ($journal === false) {
            return [];
        }

        $events = [];

        try {
            while (count($events) < self::MAX_EVENTS && ($line = fgets($journal)) !== false) {
                $event = $this->parse($line, $now);

                if ($event !== null) {
                    $key = intdiv($event['at'], 1_000_000).':'.$event['pid'];
                    $events[$key] ??= $event;
                }
            }
        } finally {
            fclose($journal);
        }

        return array_values($events);
    }

    /**
     * Extract process details from a single journal entry.
     *
     * @return array{at: int, pid: int, process: string, scope: string, uid: ?int, rss_bytes: int}|null
     */
    private function parse(string $line, int $now): ?array
    {
        $entry = json_decode($line, true);

        if (! is_array($entry)) {
            return null;
        }

        $message = $entry['MESSAGE'] ?? null;
        $timestamp = $entry['__REALTIME_TIMESTAMP'] ?? null;

        if (! is_string($timestamp) || ! ctype_digit($timestamp) || ! is_string($message)) {
            return null;
        }

        $at = (int) $timestamp;
        $second = intdiv($at, 1_000_000);

        if ($second <= $now - 86400 || $second > $now
            || preg_match('/^(Memory cgroup out of memory|Out of memory): Killed process ([1-9][0-9]*) \((.{1,64})\) total-vm:[0-9]+kB, anon-rss:([0-9]+)kB, file-rss:([0-9]+)kB, shmem-rss:([0-9]+)kB(?:, UID:([0-9]+))?/u', $message, $fields) !== 1) {
            return null;
        }

        $process = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $fields[3]));

        return [
            'at' => $at,
            'pid' => (int) $fields[2],
            'process' => $process !== '' ? $process : '(unknown)',
            'scope' => $fields[1] === 'Out of memory' ? 'host' : 'cgroup',
            'uid' => isset($fields[7]) ? (int) $fields[7] : null,
            'rss_bytes' => ((int) $fields[4] + (int) $fields[5] + (int) $fields[6]) * 1024,
        ];
    }
}
