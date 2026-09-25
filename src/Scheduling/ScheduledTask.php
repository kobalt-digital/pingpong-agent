<?php

namespace KobaltDigital\PingPong\Scheduling;

class ScheduledTask
{
    public function __construct(
        public readonly string $slug,
        public readonly ?string $command,
        public readonly ?string $description,
        public readonly string $cron,
        public readonly string $timezone,
        public readonly ?int $maxRuntime,
        public readonly ?int $grace,
    ) {}

    /**
     * @return array{
     *     slug: string,
     *     command: ?string,
     *     description: ?string,
     *     cron: string,
     *     timezone: string,
     *     overrides: array{max_runtime: ?int, grace: ?int}
     * }
     */
    public function toManifestEntry(): array
    {
        return [
            'slug' => $this->slug,
            'command' => $this->command,
            'description' => $this->description,
            'cron' => $this->cron,
            'timezone' => $this->timezone,
            'overrides' => [
                'max_runtime' => $this->maxRuntime,
                'grace' => $this->grace,
            ],
        ];
    }
}
