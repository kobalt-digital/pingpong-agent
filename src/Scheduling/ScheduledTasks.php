<?php

namespace KobaltDigital\PingPong\Scheduling;

use DateTimeZone;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Str;

class ScheduledTasks
{
    public const SLUG_MAX_LENGTH = 100;

    /**
     * PingPong treats the tick as a built in task of its own.
     */
    public const PING_SLUG = 'pingpong-ping';

    public function __construct(private TaskOverrides $overrides) {}

    /**
     * Null for what PingPong does not follow: sub minute tasks, closures
     * without a name and the Agent's own ping.
     */
    public function find(Event $event): ?ScheduledTask
    {
        if ($event->isRepeatable()) {
            return null;
        }

        $slug = $this->slug($event);

        if ($slug === null || $slug === self::PING_SLUG) {
            return null;
        }

        $overrides = $this->overrides->for($event);

        return new ScheduledTask(
            slug: $slug,
            cron: $event->expression,
            timezone: $this->timezone($event),
            maxRuntime: $overrides['max_runtime'],
            grace: $overrides['grace'],
        );
    }

    public function isUnnamedClosure(Event $event): bool
    {
        return $event instanceof CallbackEvent && blank($event->description);
    }

    /**
     * Built from what the code says, so it survives a deploy: the command
     * without the PHP and artisan binaries in front of it, or the name of a
     * closure or job.
     */
    private function slug(Event $event): ?string
    {
        $name = $event instanceof CallbackEvent
            ? $event->description
            : $this->withoutBinaries($event->command);

        if (blank($name)) {
            return null;
        }

        $slug = Str::slug(preg_replace('/[^\pL\pN]+/u', ' ', $name));

        if (strlen($slug) <= self::SLUG_MAX_LENGTH) {
            return $slug;
        }

        $hash = substr(sha1($name), 0, 8);

        return rtrim(substr($slug, 0, self::SLUG_MAX_LENGTH - 9), '-')."-{$hash}";
    }

    private function withoutBinaries(string $command): string
    {
        $phpBinary = '(?:\'[^\']*\'|"[^"]*"|\S+)';
        $artisanBinary = preg_quote(Application::artisanBinary(), '/');

        if (! preg_match("/^{$phpBinary}\s+{$artisanBinary}\s+/", $command, $prefix)) {
            return $command;
        }

        return substr($command, strlen($prefix[0]));
    }

    private function timezone(Event $event): string
    {
        $timezone = blank($event->timezone) ? config('app.timezone') : $event->timezone;

        return $timezone instanceof DateTimeZone ? $timezone->getName() : $timezone;
    }
}
