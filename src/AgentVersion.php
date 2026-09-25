<?php

namespace KobaltDigital\PingPong;

use Composer\InstalledVersions;

class AgentVersion
{
    public function current(): string
    {
        return InstalledVersions::getPrettyVersion('kobaltdigital/pingpong-agent') ?? 'unknown';
    }
}
