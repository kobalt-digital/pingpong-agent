<?php

namespace KobaltDigital\PingPong\Signals;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use KobaltDigital\PingPong\Contracts\Collector;

class AppState implements Collector
{
    public function __construct(
        private Application $app,
        private Repository $config,
    ) {}

    public function name(): string
    {
        return 'app';
    }

    /** @return array{maintenance: bool, debug: bool} */
    public function collect(): array
    {
        return [
            'maintenance' => $this->app->isDownForMaintenance(),
            'debug' => (bool) $this->config->get('app.debug'),
        ];
    }
}
