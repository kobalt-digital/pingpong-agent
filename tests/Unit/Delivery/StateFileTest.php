<?php

use Illuminate\Support\Facades\File;
use KobaltDigital\PingPong\Delivery\StateFile;

beforeEach(function () {
    File::ensureDirectoryExists(storage_path());

    $this->path = storage_path('state.json');
});

it('keeps the stored state when the new one cannot be encoded', function () {
    $file = new StateFile($this->path);

    $file->update(fn () => ['entries' => [1, 2, 3]]);

    expect(fn () => $file->update(fn () => ['message' => "caf\xE9"]))->toThrow(JsonException::class)
        ->and($file->read())->toBe(['entries' => [1, 2, 3]]);
});

it('reads a state file it may not write, as another user of the server would', function () {
    $file = new StateFile($this->path);

    $file->update(fn () => ['entries' => [1]]);

    chmod($this->path, 0444);

    expect($file->read())->toBe(['entries' => [1]]);

    chmod($this->path, 0644);
})->skipOnWindows();
