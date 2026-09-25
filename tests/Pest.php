<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use KobaltDigital\PingPong\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

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

function useDatabaseQueue(): void
{
    config()->set('queue.default', 'database');

    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}
