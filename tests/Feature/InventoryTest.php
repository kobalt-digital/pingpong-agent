<?php

use Illuminate\Support\Facades\Http;
use KobaltDigital\PingPong\Inventory;

const INVENTORY_TICK_URL = 'https://pingpong.kobaltdigital.nl/api/agent/tick';

beforeEach(function () {
    config()->set('pingpong-agent.key', 'pp_agent_test');
});

/**
 * @return array<int, array<string, mixed>>
 */
function sentInventoryTicks(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => $pair[0]->url() === INVENTORY_TICK_URL)
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

it('reports the php and laravel versions and the locked packages, sorted by name', function () {
    useComposerLock(
        ['spatie/laravel-backup' => '9.3.2', 'laravel/framework' => 'v13.2.0'],
        ['pestphp/pest' => 'v4.1.0'],
    );

    expect(app(Inventory::class)->collect())->toBe([
        'php_version' => PHP_VERSION,
        'laravel_version' => app()->version(),
        'packages' => [
            ['name' => 'laravel/framework', 'version' => 'v13.2.0', 'dev' => false],
            ['name' => 'pestphp/pest', 'version' => 'v4.1.0', 'dev' => true],
            ['name' => 'spatie/laravel-backup', 'version' => '9.3.2', 'dev' => false],
        ],
        'error' => null,
    ]);
});

it('reports a missing composer.lock as a fact', function () {
    app()->setBasePath(sys_get_temp_dir().'/pingpong-agent-missing');

    expect(app(Inventory::class)->collect())->toBe([
        'php_version' => PHP_VERSION,
        'laravel_version' => app()->version(),
        'packages' => null,
        'error' => 'There is no composer.lock in the base path of the app.',
    ]);
});

it('reports an unreadable composer.lock as a fact', function () {
    $basePath = useComposerLock([]);

    file_put_contents("{$basePath}/composer.lock", '{"packages": [');

    $inventory = app(Inventory::class)->collect();

    expect($inventory['packages'])->toBeNull()
        ->and($inventory['error'])->toBeString()->not->toBeEmpty();
});

it('changes its hash when the lock changes', function () {
    $basePath = useComposerLock(['laravel/framework' => 'v13.2.0']);

    $before = app(Inventory::class)->hash();

    writeComposerLock($basePath, ['laravel/framework' => 'v13.3.0']);

    expect(app(Inventory::class)->hash())->not->toBe($before);
});

it('sends the inventory with the first tick', function () {
    useComposerLock(['laravel/framework' => 'v13.2.0']);

    Http::fake([INVENTORY_TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping');

    expect(sentInventoryTicks()[0]['inventory_hash'])->toBeString()->toHaveLength(64)
        ->and(sentInventoryTicks()[0]['inventory']['packages'])->toBe([
            ['name' => 'laravel/framework', 'version' => 'v13.2.0', 'dev' => false],
        ]);
});

it('sends no inventory while the lock is unchanged', function () {
    useComposerLock(['laravel/framework' => 'v13.2.0']);

    Http::fake([INVENTORY_TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping');
    $this->artisan('pingpong:ping');

    [$first, $second] = sentInventoryTicks();

    expect($second)->not->toHaveKey('inventory')
        ->and($second['inventory_hash'])->toBe($first['inventory_hash']);
});

it('sends the inventory once after the lock changes', function () {
    $basePath = useComposerLock(['laravel/framework' => 'v13.2.0']);

    Http::fake([INVENTORY_TICK_URL => Http::response()]);

    $this->artisan('pingpong:ping');

    writeComposerLock($basePath, ['laravel/framework' => 'v13.3.0']);

    $this->artisan('pingpong:ping');
    $this->artisan('pingpong:ping');

    [, $changed, $unchanged] = sentInventoryTicks();

    expect($changed['inventory']['packages'][0]['version'])->toBe('v13.3.0')
        ->and($unchanged)->not->toHaveKey('inventory');
});

it('sends the inventory again when the tick that carried it was not delivered', function () {
    useComposerLock(['laravel/framework' => 'v13.2.0']);

    Http::fake([INVENTORY_TICK_URL => Http::sequence()->push(status: 500)->push()]);

    $this->artisan('pingpong:ping');
    $this->artisan('pingpong:ping');

    expect(sentInventoryTicks()[1])->toHaveKey('inventory');
});

it('sends the inventory again on the next tick when PingPong asks for it', function () {
    useComposerLock(['laravel/framework' => 'v13.2.0']);

    Http::fake([INVENTORY_TICK_URL => Http::sequence()
        ->push(['status' => 'recorded', 'send_inventory' => true])
        ->push(['status' => 'recorded'])
        ->push(['status' => 'recorded']),
    ]);

    $this->artisan('pingpong:ping');
    $this->artisan('pingpong:ping');
    $this->artisan('pingpong:ping');

    [, $resent, $after] = sentInventoryTicks();

    expect($resent)->toHaveKey('inventory')
        ->and($after)->not->toHaveKey('inventory');
});
