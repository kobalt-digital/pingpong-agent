<?php

namespace KobaltDigital\PingPong;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use KobaltDigital\PingPong\Actions\SendHandshake;
use KobaltDigital\PingPong\Commands\PingCommand;
use KobaltDigital\PingPong\Scheduling\ReportScheduledTasks;
use KobaltDigital\PingPong\Scheduling\TaskOverrides;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PingPongServiceProvider extends PackageServiceProvider
{
    /**
     * A stale lock from a killed run blocks ticks until it expires, so it
     * expires after one minute instead of Laravel's default of a day.
     */
    public const LOCK_EXPIRES_AFTER_MINUTES = 1;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('pingpong-agent')
            ->hasConfigFile()
            ->hasCommand(PingCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ReportScheduledTasks::class);
        $this->app->singleton(TaskOverrides::class);
    }

    public function packageBooted(): void
    {
        $this->app['events']->subscribe(ReportScheduledTasks::class);

        Event::macro('pingpong', function (?int $maxRuntime = null, ?int $grace = null) {
            app(TaskOverrides::class)->set($this, $maxRuntime, $grace);

            return $this;
        });

        $this->app->terminating(fn () => $this->app->make(SendHandshake::class)->execute());

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (! $this->app->make(Transport::class)->shouldSend()) {
                return;
            }

            $schedule->command(PingCommand::class)
                ->everyMinute()
                ->withoutOverlapping(self::LOCK_EXPIRES_AFTER_MINUTES);
        });
    }
}
