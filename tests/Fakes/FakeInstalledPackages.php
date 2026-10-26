<?php

namespace KobaltDigital\PingPong\Tests\Fakes;

use KobaltDigital\PingPong\InstalledPackages;

class FakeInstalledPackages extends InstalledPackages
{
    /** @param  array<string, string>  $versions */
    public function __construct(private array $versions = []) {}

    public function version(string $name): ?string
    {
        return $this->versions[$name] ?? null;
    }
}
