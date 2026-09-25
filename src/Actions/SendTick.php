<?php

namespace KobaltDigital\PingPong\Actions;

use KobaltDigital\PingPong\Signals\CacheSignal;
use KobaltDigital\PingPong\Signals\DatabaseSignal;
use KobaltDigital\PingPong\Signals\DiskSignal;
use KobaltDigital\PingPong\Transport;

class SendTick
{
    public function __construct(
        private Transport $transport,
        private DatabaseSignal $database,
        private CacheSignal $cache,
        private DiskSignal $disk,
    ) {}

    public function execute(): bool
    {
        return $this->transport->send('api/agent/tick', [
            'signals' => [
                'database' => $this->database->collect(),
                'cache' => $this->cache->collect(),
                'disk' => $this->disk->collect(),
            ],
        ]);
    }
}
