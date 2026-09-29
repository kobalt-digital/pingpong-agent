<?php

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use KobaltDigital\PingPong\Signals\FailedJobs;

it('reports no new failed jobs on the first tick, whatever is already in the table', function () {
    createFailedJobsTable();
    failJobs(3);

    expect(app(FailedJobs::class)->collect())->toBe([
        'new' => 0,
        'error' => null,
    ]);
});

it('reports the jobs that failed since the last tick', function () {
    createFailedJobsTable();
    failJobs(1);

    app(FailedJobs::class)->collect();

    failJobs(2);

    expect(app(FailedJobs::class)->collect()['new'])->toBe(2)
        ->and(app(FailedJobs::class)->collect()['new'])->toBe(0);
});

it('counts every job in the table as new after the table was emptied and refilled', function () {
    createFailedJobsTable();
    failJobs(5);

    app(FailedJobs::class)->collect();

    DB::statement('delete from failed_jobs');
    DB::statement("delete from sqlite_sequence where name = 'failed_jobs'");

    failJobs(2);

    expect(app(FailedJobs::class)->collect()['new'])->toBe(2);
});

it('reads failed jobs from their own connection when it is not the default', function () {
    config()->set('database.connections.failed', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('queue.failed.database', 'failed');

    Schema::connection('failed')->create('failed_jobs', function (Blueprint $table) {
        $table->id();
    });

    expect(app(FailedJobs::class)->collect())->toBe([
        'new' => 0,
        'error' => null,
    ]);
});

it('reports a missing failed jobs table as a fact', function () {
    expect(app(FailedJobs::class)->collect())->toBe([
        'new' => null,
        'error' => 'The failed_jobs table does not exist.',
    ]);
});

it('reports failed jobs that are not stored in the database as a fact', function () {
    config()->set('queue.failed.driver', 'file');

    expect(app(FailedJobs::class)->collect())->toBe([
        'new' => null,
        'error' => 'Failed jobs are not stored in the database (driver: file).',
    ]);
});

it('reports an unreachable database as a fact', function () {
    config()->set('database.connections.testing.database', '/nonexistent/pingpong.sqlite');

    DB::purge();

    $signal = app(FailedJobs::class)->collect();

    expect($signal['new'])->toBeNull()
        ->and($signal['error'])->toContain('pingpong.sqlite');
});

it('reports an unreachable cache as a fact', function () {
    createFailedJobsTable();

    $this->mock(Repository::class)->shouldReceive('get')->andThrow(new RuntimeException('Connection refused'));

    expect(app(FailedJobs::class)->collect())->toBe([
        'new' => null,
        'error' => 'Connection refused',
    ]);
});
