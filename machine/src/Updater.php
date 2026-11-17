<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * Replaces the running phar with the one attached to the newest release of
 * this repository, when that one differs. Never from a branch: a push to
 * `main` reaches no server until someone publishes a release, so one bad
 * commit cannot take every machine agent down in a night.
 *
 * The checksum next to it is checked first, so a download that broke off
 * halfway never replaces a phar that works. The new phar is then written
 * next to the old one and has to answer `version` before it is renamed over
 * it: a phar that does not even start stays where it is, and the old one
 * keeps reporting. Cron's next run picks the new one up.
 */
class Updater
{
    public const RELEASE_URL = 'https://github.com/kobalt-digital/pingpong-agent/releases/latest/download/pingpong-machine.phar';

    public const TIMEOUT_SECONDS = 60;

    /** How long the new phar may take to answer `version`. */
    public const CHECK_SECONDS = 30;

    public function __construct(
        private string $pharUrl = self::RELEASE_URL,
        private Runner $runner = new Runner,
        private string $php = PHP_BINARY,
    ) {}

    /**
     * @return 'updated'|'current'|string the outcome, or what went wrong
     */
    public function update(string $phar): string
    {
        $expected = strtolower(trim(explode(' ', (string) $this->download($this->pharUrl.'.sha256'))[0]));

        if (preg_match('/^[0-9a-f]{64}$/', $expected) !== 1) {
            return 'Could not read the checksum of the new phar.';
        }

        if (@hash_file('sha256', $phar) === $expected) {
            return 'current';
        }

        $contents = $this->download($this->pharUrl);

        if ($contents === null || hash('sha256', $contents) !== $expected) {
            return 'The downloaded phar does not match its checksum.';
        }

        /* Named as a phar, so PHP runs it as one for the check, and made
           new for this run alone: an update started by hand while cron's
           runs never writes over the one this run checked. */
        $temporary = dirname($phar).'/pingpong-machine.'.bin2hex(random_bytes(6)).'.new.phar';
        $handle = @fopen($temporary, 'x');

        if ($handle === false) {
            return "Could not write {$phar}.";
        }

        $written = @fwrite($handle, $contents);
        fclose($handle);

        if ($written !== strlen($contents) || ! @chmod($temporary, 0755)) {
            @unlink($temporary);

            return "Could not write {$phar}.";
        }

        $problem = $this->problemRunning($temporary);

        if ($problem !== null) {
            @unlink($temporary);

            return "The new phar does not run, so the old one stays: {$problem}";
        }

        if (! @rename($temporary, $phar)) {
            @unlink($temporary);

            return "Could not write {$phar}.";
        }

        return 'updated';
    }

    /** What is wrong with the new phar, or null when it answers `version` with a version. */
    private function problemRunning(string $phar): ?string
    {
        $result = $this->runner->run([$this->php, $phar, 'version'], self::CHECK_SECONDS);

        return match (true) {
            $result === null => 'PHP could not be started.',
            $result['timed_out'] => 'it did not answer within '.self::CHECK_SECONDS.' seconds.',
            $result['exit'] !== 0 => "it exited with {$result['exit']}.",
            preg_match('/^\d+\.\d+\.\d+/', trim($result['output'])) !== 1 => 'it did not name its version.',
            default => null,
        };
    }

    private function download(string $url): ?string
    {
        $context = stream_context_create(['http' => ['timeout' => self::TIMEOUT_SECONDS, 'follow_location' => 1, 'header' => 'User-Agent: pingpong-machine/'.Agent::VERSION]]);
        $contents = @file_get_contents($url, false, $context);

        return $contents === false ? null : $contents;
    }
}
