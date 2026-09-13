<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('fails the runtime check when Chromium cannot start', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'Chromium binary or native library missing')]);
    $this->artisan('facebook:check', ['--browser' => true])->assertFailed();
});

it('does not accept an executable that exits successfully without running browser JavaScript', function (): void {
    Process::fake(['*' => Process::result(output: 'Chrome 147')]);
    $this->artisan('facebook:check', ['--browser' => true])->assertFailed();
});

it('passes only when the real runtime probe confirms Chromium and JavaScript', function (): void {
    Process::fake(['*' => Process::result(output: '{"chromium":true,"javascript":true}')]);
    $this->artisan('facebook:check', ['--browser' => true])->assertSuccessful();
});

it('verifies the HTTP parser without requiring a browser', function (): void {
    Process::fake(['*' => Process::result(output: '{"http_parser":true}')]);
    $this->artisan('facebook:check')->assertSuccessful();
    Process::assertRan(fn ($process): bool => str_contains(implode(' ', $process->command), 'parse-scripts.mjs'));
});

it('fails the HTTP runtime check when Node or its parser is unavailable', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    $this->artisan('facebook:check')->assertFailed();
});
