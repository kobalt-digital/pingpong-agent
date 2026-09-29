<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use KobaltDigital\PingPong\Signals\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-09-29 12:00:00');
});

it('reports pending jobs per queue and the age of the oldest due job next to the canary', function () {
    useDatabaseQueue();

    $now = now()->getTimestamp();

    insertJob('default', $now - 40);
    insertJob('default', $now - 10);
    insertJob('mail', $now - 5);

    $signal = app(Queue::class)->collect();

    expect($signal['canary_id'])->toBeString()
        ->and($signal['error'])->toBeNull()
        ->and($signal['pending'])->toBe(['default' => 2, 'mail' => 1])
        ->and($signal['oldest_pending_seconds'])->toBe(40);
});

it('does not count the canary of the same tick', function () {
    useDatabaseQueue();

    $signal = app(Queue::class)->collect();

    expect($signal['canary_id'])->toBeString()
        ->and($signal['pending'])->toEqual(new stdClass)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('counts a job scheduled for the future as pending but never as overdue', function () {
    useDatabaseQueue();

    insertJob('default', now()->getTimestamp() + 600);

    $signal = app(Queue::class)->collect();

    expect($signal['pending'])->toBe(['default' => 1])
        ->and($signal)->not->toHaveKey('oldest_pending_seconds');
});

it('does not count a reserved job as pending', function () {
    useDatabaseQueue();

    $now = now()->getTimestamp();

    insertJob('default', $now - 40, reservedAt: $now - 1);

    $signal = app(Queue::class)->collect();

    expect($signal['pending'])->toEqual(new stdClass)
        ->and($signal)->not->toHaveKey('oldest_pending_seconds');
});

it('caps the pending count at 10000 across all queues', function () {
    useDatabaseQueue();

    $availableAt = now()->getTimestamp() - 40;
    $jobs = [];

    for ($index = 0; $index < Queue::PENDING_CAP + 5; $index++) {
        $jobs[] = [
            'queue' => $index % 2 === 0 ? 'default' : 'mail',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => $availableAt,
            'created_at' => $availableAt,
        ];
    }

    foreach (array_chunk($jobs, 500) as $chunk) {
        DB::table('jobs')->insert($chunk);
    }

    $signal = app(Queue::class)->collect();

    expect(array_sum($signal['pending']))->toBe(Queue::PENDING_CAP)
        ->and($signal['oldest_pending_seconds'])->toBe(40);
});

it('gives no backlog when the queue driver is not database', function () {
    Log::shouldReceive('warning')->never();

    expect(app(Queue::class)->collect())->toBe([
        'connection' => 'sync',
        'driver' => 'sync',
        'canary_id' => null,
        'error' => null,
    ]);
});

it('reads the backlog from the queue connection when it is not the default', function () {
    config()->set('database.connections.queue', ['driver' => 'sqlite', 'database' => ':memory:']);

    useDatabaseQueue('queue');

    insertJob('default', now()->getTimestamp() - 40, connection: 'queue');

    $signal = app(Queue::class)->collect();

    expect($signal['pending'])->toBe(['default' => 1])
        ->and($signal['oldest_pending_seconds'])->toBe(40)
        ->and($signal['canary_id'])->toBeString()
        ->and(Schema::hasTable('jobs'))->toBeFalse();
});

it('dispatches no canary and gives no backlog when the default database is unreachable', function () {
    useDatabaseQueue();

    config()->set('database.connections.testing.database', '/nonexistent/pingpong.sqlite');

    DB::purge();

    Log::shouldReceive('warning')->never();

    $signal = app(Queue::class)->collect();

    expect(array_keys($signal))->toBe(['connection', 'driver', 'canary_id', 'error'])
        ->and($signal['canary_id'])->toBeNull()
        ->and($signal['error'])->toContain('pingpong.sqlite');
});

it('keeps the canary keys and logs one warning when the backlog cannot be read', function () {
    config()->set('queue.default', 'database');

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'queue backlog'));

    $signal = app(Queue::class)->collect();

    expect(array_keys($signal))->toBe(['connection', 'driver', 'canary_id', 'error'])
        ->and($signal['driver'])->toBe('database')
        ->and($signal['error'])->toContain('jobs');
});
