<?php

namespace KobaltDigital\PingPong\Machine;

/**
 * What one run leaves for the next, in one JSON file in the state directory:
 * the CPU counters of every process, the pending updates by name and how
 * far PingPong took the Package changes of `dpkg.log`. A file that is missing or cannot be read is
 * an empty state, which costs one tick without CPU figures, never the tick.
 *
 * Written to a temporary file and renamed, so a run that dies halfway never
 * leaves half a file behind.
 */
final class State
{
    /** @var array<string, mixed> */
    private array $values;

    public function __construct(private string $directory)
    {
        $contents = @file_get_contents($this->file());
        $values = $contents === false ? null : json_decode($contents, true);

        $this->values = [];

        foreach (is_array($values) ? $values : [] as $key => $value) {
            $this->values[(string) $key] = $value;
        }
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function save(): bool
    {
        if (! $this->prepare()) {
            return false;
        }

        $temporary = $this->file().'.'.getmypid();
        $json = json_encode($this->values, JSON_UNESCAPED_SLASHES);

        if ($json === false || @file_put_contents($temporary, $json) === false) {
            return false;
        }

        @chmod($temporary, 0640);

        return @rename($temporary, $this->file());
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Makes the directory on the first run, readable for its owner and group
     * only: the counters say what runs on the machine.
     */
    public function prepare(): bool
    {
        return @is_dir($this->directory) || @mkdir($this->directory, 0750, true);
    }

    private function file(): string
    {
        return "{$this->directory}/state.json";
    }
}
