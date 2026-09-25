<?php

use KobaltDigital\PingPong\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function payloadFixture(string $name): stdClass
{
    return json_decode(file_get_contents(__DIR__."/Fixtures/schema-1/{$name}.json"), flags: JSON_THROW_ON_ERROR);
}

/**
 * Reduces a decoded JSON payload to its keys and value types, so a sent
 * payload can be compared with a fixture whose values are examples.
 */
function payloadShape(mixed $value): mixed
{
    if (! $value instanceof stdClass) {
        return get_debug_type($value);
    }

    $shape = array_map(payloadShape(...), get_object_vars($value));

    ksort($shape);

    return $shape;
}
