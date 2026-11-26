<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * Orders Debian package versions the way `dpkg --compare-versions` does:
 * the epoch as a number, then the upstream version and the revision part by
 * part, where `~` sorts before everything, even the end of the version.
 */
final class DebianVersion
{
    /** Below 0 when the first version is older, 0 when equal, above 0 when newer. */
    public static function compare(string $first, string $second): int
    {
        [$firstEpoch, $firstUpstream, $firstRevision] = self::parts($first);
        [$secondEpoch, $secondUpstream, $secondRevision] = self::parts($second);

        if ($firstEpoch !== $secondEpoch) {
            return $firstEpoch <=> $secondEpoch;
        }

        return self::compareParts($firstUpstream, $secondUpstream) ?: self::compareParts($firstRevision, $secondRevision);
    }

    /** @return array{int, string, string} */
    private static function parts(string $version): array
    {
        $colon = strpos($version, ':');
        $epoch = $colon === false ? 0 : (int) substr($version, 0, $colon);
        $rest = $colon === false ? $version : substr($version, $colon + 1);
        $hyphen = strrpos($rest, '-');

        return $hyphen === false ? [$epoch, $rest, ''] : [$epoch, substr($rest, 0, $hyphen), substr($rest, $hyphen + 1)];
    }

    /** dpkg's `verrevcmp`: runs of non-digits by `order()`, then runs of digits as numbers. */
    private static function compareParts(string $first, string $second): int
    {
        $firstIndex = 0;
        $secondIndex = 0;
        $firstLength = strlen($first);
        $secondLength = strlen($second);

        while ($firstIndex < $firstLength || $secondIndex < $secondLength) {
            while (($firstIndex < $firstLength && ! ctype_digit($first[$firstIndex])) || ($secondIndex < $secondLength && ! ctype_digit($second[$secondIndex]))) {
                $difference = self::order($first[$firstIndex] ?? null) - self::order($second[$secondIndex] ?? null);

                if ($difference !== 0) {
                    return $difference;
                }

                $firstIndex++;
                $secondIndex++;
            }

            while ($firstIndex < $firstLength && $first[$firstIndex] === '0') {
                $firstIndex++;
            }

            while ($secondIndex < $secondLength && $second[$secondIndex] === '0') {
                $secondIndex++;
            }

            $firstDifference = 0;

            while ($firstIndex < $firstLength && $secondIndex < $secondLength && ctype_digit($first[$firstIndex]) && ctype_digit($second[$secondIndex])) {
                $firstDifference = $firstDifference ?: ord($first[$firstIndex]) - ord($second[$secondIndex]);
                $firstIndex++;
                $secondIndex++;
            }

            if ($firstIndex < $firstLength && ctype_digit($first[$firstIndex])) {
                return 1;
            }

            if ($secondIndex < $secondLength && ctype_digit($second[$secondIndex])) {
                return -1;
            }

            if ($firstDifference !== 0) {
                return $firstDifference;
            }
        }

        return 0;
    }

    private static function order(?string $character): int
    {
        return match (true) {
            $character === null, ctype_digit($character) => 0,
            ctype_alpha($character) => ord($character),
            $character === '~' => -1,
            default => ord($character) + 256,
        };
    }
}
