<?php

namespace KobaltDigital\PingPong;

use KobaltDigital\PingPong\Actions\SendHandshake;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PingPongServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('pingpong-agent')
            ->hasConfigFile();
    }

    public function packageBooted(): void
    {
        $this->app->terminating(fn () => $this->app->make(SendHandshake::class)->execute());
    }
}
