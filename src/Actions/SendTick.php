<?php

namespace KobaltDigital\PingPong\Actions;

use KobaltDigital\PingPong\Transport;
use stdClass;

class SendTick
{
    public function __construct(private Transport $transport) {}

    public function execute(): bool
    {
        return $this->transport->send('api/agent/tick', [
            'signals' => new stdClass,
        ]);
    }
}
