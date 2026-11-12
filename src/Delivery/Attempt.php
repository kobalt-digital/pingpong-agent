<?php

namespace KobaltDigital\PingPong\Delivery;

use Illuminate\Http\Client\Response;

readonly class Attempt
{
    public function __construct(
        public Outcome $outcome,
        public ?Response $response = null,
    ) {}
}
