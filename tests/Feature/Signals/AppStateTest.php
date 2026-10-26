<?php

use Illuminate\Contracts\Foundation\Application;
use KobaltDigital\PingPong\Signals\AppState;

it('reports the environment, maintenance and debug state', function () {
    config()->set('app.debug', true);

    $app = Mockery::mock(Application::class);
    $app->shouldReceive('environment')->once()->andReturn('acceptance');
    $app->shouldReceive('isDownForMaintenance')->once()->andReturn(true);

    $appState = new AppState($app, config());

    expect($appState->name())->toBe('app')
        ->and($appState->collect())->toBe(['env' => 'acceptance', 'maintenance' => true, 'debug' => true]);
});

it('reports a healthy app as up and not debugging', function () {
    config()->set('app.debug', false);

    expect(app(AppState::class)->collect())->toBe(['env' => 'local', 'maintenance' => false, 'debug' => false]);
});

it('is configured by default', function () {
    expect(config('pingpong-agent.signals'))->toContain(AppState::class);
});
