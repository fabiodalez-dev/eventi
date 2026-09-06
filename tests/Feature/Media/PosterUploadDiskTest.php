<?php

declare(strict_types=1);

use App\Filament\Support\ImageUpload;
use App\Models\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

it('uses the media disk for panel uploads even when Filament defaults to private storage', function (): void {
    config(['filament.default_filesystem_disk' => 'local', 'media-library.disk_name' => 'public']);

    expect(ImageUpload::make('poster')->getDiskName())->toBe('public');
});

it('repairs existing posters without losing originals or exposing unrelated private files', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Storage::disk('local')->put('tickets/private.pdf', 'private ticket');
    $event = Event::factory()->create();
    $media = $event->addMedia(ImageFixtures::upload('poster.jpg', ImageFixtures::jpeg()))
        ->toMediaCollection('poster', 'local');
    $path = $media->getPathRelativeToRoot();
    $bytes = Storage::disk('local')->get($path);

    $migration = require database_path('migrations/2026_09_06_170000_repair_private_event_posters.php');
    $migration->up();
    $migration->up();

    expect($media->refresh()->disk)->toBe('public')
        ->and($media->conversions_disk)->toBe('public')
        ->and(Storage::disk('public')->get($path))->toBe($bytes)
        ->and(Storage::disk('local')->get($path))->toBe($bytes);
    foreach (array_keys($media->generated_conversions) as $conversion) {
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot($conversion));
    }
    Storage::disk('public')->assertMissing('tickets/private.pdf');
});
