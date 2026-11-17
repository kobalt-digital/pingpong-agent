<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * Replaces the running phar with the one on the main branch, when that one
 * differs. The checksum next to it is checked first, so a download that broke
 * off halfway never replaces a phar that works. The new phar is written
 * next to the old one and renamed over it, which cron's next run picks up.
 */
class Updater
{
    public const PHAR_URL = 'https://raw.githubusercontent.com/kobalt-digital/pingpong-agent/main/machine/dist/pingpong-machine.phar';

    public const TIMEOUT_SECONDS = 60;

    public function __construct(private string $pharUrl = self::PHAR_URL) {}

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

        $temporary = $phar.'.new';

        if (@file_put_contents($temporary, $contents) === false || ! @chmod($temporary, 0755) || ! @rename($temporary, $phar)) {
            @unlink($temporary);

            return "Could not write {$phar}.";
        }

        return 'updated';
    }

    private function download(string $url): ?string
    {
        $context = stream_context_create(['http' => ['timeout' => self::TIMEOUT_SECONDS, 'header' => 'User-Agent: pingpong-machine/'.Agent::VERSION]]);
        $contents = @file_get_contents($url, false, $context);

        return $contents === false ? null : $contents;
    }
}
