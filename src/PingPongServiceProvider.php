<?php

namespace KobaltDigital\PingPong;

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
}
