<?php

namespace KobaltDigital\PingPong;

use Composer\InstalledVersions;
use Throwable;

/**
 * Which Composer packages this process can load, as Composer's runtime API
 * knows them. Unlike the inventory, which reads composer.lock, this leaves
 * out dev packages a production install skipped.
 */
class InstalledPackages
{
    /**
     * The installed version, null when the package is not installed.
     */
    public function version(string $name): ?string
    {
        try {
            if (! InstalledVersions::isInstalled($name)) {
                return null;
            }

            return InstalledVersions::getPrettyVersion($name) ?? '';
        } catch (Throwable) {
            return null;
        }
    }
}
