<?php

use Illuminate\Support\Facades\Log;
use KobaltDigital\PingPong\Actions\CollectSignals;
use KobaltDigital\PingPong\Metrics;
use KobaltDigital\PingPong\PingPong;

beforeEach(function () {
    config()->set('pingpong-agent.signals', []);
});

it('reads every registered metric into the tick', function () {
    PingPong::metric('orders.pending', fn () => 12);
    PingPong::metric('import.lag', fn () => 1.5, unit: 'seconds');

    expect(app(CollectSignals::class)->execute())->toBe(['metrics' => ['values' => [
        ['name' => 'orders.pending', 'value' => 12, 'unit' => 'count'],
        ['name' => 'import.lag', 'value' => 1.5, 'unit' => 'seconds'],
    ]]]);
});

it('sends no metrics signal while the app registered none', function () {
    expect(app(CollectSignals::class)->execute())->toBe([]);
});

it('ignores a name or unit PingPong would refuse', function (string $name, string $unit) {
    Log::spy();

    PingPong::metric($name, fn () => 1, $unit);

    expect(app(Metrics::class)->read())->toBeNull();
    Log::shouldHaveReceived('warning')->once();
})->with([
    'upper case' => ['Orders.Pending', 'count'],
    'a space' => ['orders pending', 'count'],
    'a trailing dot' => ['orders.', 'count'],
    'too long' => [str_repeat('a', 65), 'count'],
    'an unknown unit' => ['orders.pending', 'euros'],
]);

it('keeps at most twenty metrics, but lets a registered one be replaced', function () {
    Log::spy();

    foreach (range(1, Metrics::MAX_METRICS) as $number) {
        PingPong::metric("metric.m{$number}", fn () => $number);
    }

    PingPong::metric('metric.one_too_many', fn () => 0);
    PingPong::metric('metric.m1', fn () => 100);

    $values = collect(app(Metrics::class)->read());
    expect($values)->toHaveCount(Metrics::MAX_METRICS)
        ->and($values->firstWhere('name', 'metric.m1')['value'])->toBe(100)
        ->and($values->firstWhere('name', 'metric.one_too_many'))->toBeNull();
    Log::shouldHaveReceived('warning')->once();
});

it('leaves out a metric that throws or reads no finite number, and keeps the rest', function () {
    Log::spy();

    PingPong::metric('broken.throws', fn () => throw new RuntimeException('database gone'));
    PingPong::metric('broken.text', fn () => '12');
    PingPong::metric('broken.infinite', fn () => INF);
    PingPong::metric('broken.huge', fn () => 1e16);
    PingPong::metric('orders.pending', fn () => 3);

    expect(app(Metrics::class)->read())->toBe([['name' => 'orders.pending', 'value' => 3, 'unit' => 'count']]);
    Log::shouldHaveReceived('warning')->times(4);
});
