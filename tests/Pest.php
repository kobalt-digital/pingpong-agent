<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use KobaltDigital\PingPong\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/* The machine agent reads /proc and runs du and df: it runs on Linux only. */
uses()->beforeEach(function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('The machine agent runs on Linux only.');
    }
})->in('Unit/Machine');

function payloadFixture(string $name): stdClass
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/schema-1/{$name}.json"), flags: JSON_THROW_ON_ERROR);
}

/**
 * Reduces a decoded JSON payload to its keys and value types, so a sent
 * payload can be compared with a fixture whose values are examples. A list
 * is reduced to the shape of its first item.
 */
function payloadShape(mixed $value): mixed
{
    if (is_array($value)) {
        return $value === [] ? 'array' : [payloadShape($value[0])];
    }

    if (! $value instanceof stdClass) {
        return get_debug_type($value);
    }

    $shape = array_map(payloadShape(...), get_object_vars($value));

    ksort($shape);

    return $shape;
}

/**
 * Runs the callback with the given environment variables, where null unsets
 * one, and restores them afterwards.
 *
 * @param  array<string, ?string>  $variables
 */
function withEnv(array $variables, Closure $callback): mixed
{
    $original = [];

    foreach ($variables as $name => $value) {
        $original[$name] = getenv($name);

        putenv($value === null ? $name : "{$name}={$value}");
    }

    try {
        return $callback();
    } finally {
        foreach ($original as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

function createFailedJobsTable(): void
{
    Schema::create('failed_jobs', function (Blueprint $table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->text('connection');
        $table->text('queue');
        $table->longText('payload');
        $table->longText('exception');
        $table->timestamp('failed_at')->useCurrent();
    });
}

function failJobs(int $count): void
{
    foreach (range(1, $count) as $ignored) {
        DB::table('failed_jobs')->insert([
            'uuid' => Str::uuid()->toString(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException: Failed',
        ]);
    }
}

function useDatabaseQueue(?string $connection = null): void
{
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', $connection);

    Schema::connection($connection)->create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

function insertJob(string $queue, int $availableAt, ?int $reservedAt = null, ?string $connection = null): void
{
    DB::connection($connection)->table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => $reservedAt,
        'available_at' => $availableAt,
        'created_at' => $availableAt,
    ]);
}

/**
 * Points the app's base path at a directory holding a composer.lock with
 * the given packages.
 *
 * @param  array<string, string>  $packages
 * @param  array<string, string>  $devPackages
 */
function useComposerLock(array $packages, array $devPackages = []): string
{
    $basePath = sys_get_temp_dir().'/pingpong-agent-'.Str::random(8);

    mkdir($basePath);

    writeComposerLock($basePath, $packages, $devPackages);

    app()->setBasePath($basePath);

    return $basePath;
}

/**
 * @param  array<string, string>  $packages
 * @param  array<string, string>  $devPackages
 */
function writeComposerLock(string $basePath, array $packages, array $devPackages = []): void
{
    $toLockEntries = fn (array $versions) => collect($versions)
        ->map(fn (string $version, string $name) => ['name' => $name, 'version' => $version])
        ->values()
        ->all();

    file_put_contents("{$basePath}/composer.lock", json_encode([
        'packages' => $toLockEntries($packages),
        'packages-dev' => $toLockEntries($devPackages),
    ]));
}

/**
 * Points storage at a regular file inside the test's storage directory, so
 * no directory can be created under it on any platform, and it is removed
 * with that directory.
 */
function useUnwritableStorage(): void
{
    File::ensureDirectoryExists(storage_path());

    $file = storage_path('not-a-directory');

    touch($file);

    app()->useStoragePath($file);
}

/* The machine agent's tests share a fake /proc and a fake du. */

/**
 * A line of /proc/<pid>/stat with the fields the table reads: flags (9),
 * utime (14), stime (15), starttime (22) and rss (24).
 */
function statLine(int $pid, string $name, int $utime, int $stime, int $started, int $rssPages, int $flags = 0): string
{
    $fields = array_fill(3, 50, '0');
    $fields[3] = 'S';
    $fields[9] = (string) $flags;
    $fields[14] = (string) $utime;
    $fields[15] = (string) $stime;
    $fields[22] = (string) $started;
    $fields[24] = (string) $rssPages;

    return "{$pid} ({$name}) ".implode(' ', $fields)."\n";
}

function fakeProc(array $processes): string
{
    $proc = sys_get_temp_dir().'/pingpong-proc-'.bin2hex(random_bytes(4));
    mkdir($proc);
    file_put_contents("{$proc}/uptime", "5000.25 70000.00\n");

    foreach ($processes as $pid => $process) {
        mkdir("{$proc}/{$pid}");
        file_put_contents("{$proc}/{$pid}/stat", $process['stat']);
        file_put_contents("{$proc}/{$pid}/cmdline", $process['cmdline'] ?? '');
        file_put_contents("{$proc}/{$pid}/status", $process['status'] ?? '');
    }

    return $proc;
}

/**
 * A stand-in for du that prints the given lines under the folder it is
 * asked about, the way du does: a size in kilobytes, a tab and the path,
 * children before their parents. Asked about the slow folder, it then
 * hangs.
 */
function fakeDu(string $output, ?string $slowFolder = null): string
{
    $script = tempnam(sys_get_temp_dir(), 'du');
    $data = $script.'.out';
    file_put_contents($data, $output);
    file_put_contents($script, <<<SH
    #!/bin/sh
    for last; do :; done
    awk -F '\t' -v p="\$last" '\$2 == p || index(\$2, p "/") == 1' '{$data}'
    if [ "\$last" = "{$slowFolder}" ]; then sleep 5; fi

    SH);
    chmod($script, 0755);

    return $script;
}

/** A folder with the given folders in it. */
function siteRoot(array $folders): string
{
    $root = sys_get_temp_dir().'/pingpong-sites-'.bin2hex(random_bytes(4));

    foreach ($folders as $folder) {
        mkdir("{$root}/{$folder}", 0755, true);
    }

    return $root;
}
