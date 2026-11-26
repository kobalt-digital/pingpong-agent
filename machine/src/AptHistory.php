<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * Who made a change dpkg logged, from the apt run it fell in: apt writes
 * every run to `/var/log/apt/history.log` as a block from its `Start-Date`
 * to its `End-Date`, with the `Commandline` and, run through sudo or
 * pkexec, the user who asked in `Requested-By`. A change belongs to the run
 * whose dates hold its time; apt holds dpkg's lock, so runs never overlap.
 *
 * A run of the `unattended-upgrade` program is `unattended-upgrade`, else
 * the user who asked.
 * Unknown (null) for a bare `dpkg -i`, which apt never sees, and for root
 * running apt without sudo, which names nobody.
 *
 * logrotate compresses the history straight away, so a run before this
 * month's rotation is read from `history.log.1.gz` with zlib; without zlib
 * who made those changes is unknown.
 *
 * @phpstan-type Run array{start: string, end: ?string, by: ?string}
 */
class AptHistory
{
    private const DATE = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    public function __construct(private string $log = '/var/log/apt/history.log') {}

    /**
     * @param  list<string>  $times  as dpkg logs them, `2026-11-26 06:12:04` in the machine's time
     * @return list<?string> who made the change at each of the times
     */
    public function who(array $times): array
    {
        if ($times === []) {
            return [];
        }

        $runs = $this->runs($this->log, gzipped: false);

        if ($runs === [] || min($times) < $runs[0]['start']) {
            $runs = [...$this->runs("{$this->log}.1.gz", gzipped: true), ...$runs];
        }

        return array_map(fn (string $time): ?string => $this->whoAt($runs, $time), $times);
    }

    /** @param  list<Run>  $runs */
    private function whoAt(array $runs, string $time): ?string
    {
        for ($index = count($runs) - 1; $index >= 0; $index--) {
            $run = $runs[$index];

            if ($run['start'] > $time) {
                continue;
            }

            return $run['end'] === null || $time <= $run['end'] ? $run['by'] : null;
        }

        return null;
    }

    /** @return list<Run> */
    private function runs(string $path, bool $gzipped): array
    {
        if ($gzipped && ! function_exists('gzopen')) {
            return [];
        }

        $file = $gzipped ? @gzopen($path, 'r') : @fopen($path, 'r');

        if ($file === false) {
            return [];
        }

        $runs = [];
        $run = null;

        try {
            while (($line = $gzipped ? gzgets($file) : fgets($file)) !== false) {
                [$key, $value] = array_pad(explode(':', rtrim($line, "\r\n"), 2), 2, '');
                $value = trim($value);

                if ($key === 'Start-Date') {
                    $runs = $this->withRun($runs, $run);
                    $start = $this->date($value);
                    $run = $start === null ? null : ['start' => $start, 'end' => null, 'unattended' => false, 'requested_by' => null];

                    continue;
                }

                if ($run === null) {
                    continue;
                }

                match ($key) {
                    'Commandline' => $run['unattended'] = basename(explode(' ', $value)[0]) === 'unattended-upgrade',
                    'Requested-By' => $run['requested_by'] = preg_match('/^(\S{1,64})/', $value, $user) === 1 ? $user[1] : null,
                    'End-Date' => $run['end'] = $this->date($value),
                    default => null,
                };
            }
        } finally {
            $gzipped ? gzclose($file) : fclose($file);
        }

        return $this->withRun($runs, $run);
    }

    /**
     * @param  list<Run>  $runs
     * @param  array{start: string, end: ?string, unattended: bool, requested_by: ?string}|null  $run
     * @return list<Run>
     */
    private function withRun(array $runs, ?array $run): array
    {
        if ($run === null) {
            return $runs;
        }

        $runs[] = ['start' => $run['start'], 'end' => $run['end'], 'by' => $run['unattended'] ? 'unattended-upgrade' : $run['requested_by']];

        return $runs;
    }

    /** apt writes two spaces between the date and the time. */
    private function date(string $value): ?string
    {
        $date = (string) preg_replace('/\s+/', ' ', $value);

        return preg_match(self::DATE, $date) === 1 ? $date : null;
    }
}
