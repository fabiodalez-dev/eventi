<?php

declare(strict_types=1);

use App\Enums\ImageType;
use App\Jobs\Media\GenerateOpenGraphImage;
use App\Services\Media\OpenGraphImage;
use App\Support\Poster;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImageFixtures;

/**
 * L'anteprima social 1200×630 di §12.1, composta con `spatie/image`.
 */
beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media.open_graph.enabled', true);
});

it('compone un\'anteprima 1200x630 con la locandina, il titolo e la data', function (): void {
    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-12 21:30', event: [
        'title' => 'Rassegna di musica sperimentale con un titolo lungo abbastanza da andare a capo',
    ])->event;

    $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg(900, 1200)))
        ->toMediaCollection('poster');

    $path = app(OpenGraphImage::class)->render($event->refresh());

    expect($path)->toBe('og/eventi/'.$event->getKey().'.jpg');

    $disk = Storage::disk('public');
    expect($disk->exists($path))->toBeTrue();

    $file = $disk->path($path);
    $size = getimagesize($file);

    expect(ImageType::detect($file))->toBe(ImageType::Jpeg)
        ->and($size[0])->toBe(1200)
        ->and($size[1])->toBe(630);
});

it('compone l\'anteprima anche quando l\'evento non ha locandina', function (): void {
    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-12 21:30')->event;

    $path = app(OpenGraphImage::class)->render($event);

    expect($path)->not->toBeNull()
        ->and(getimagesize(Storage::disk('public')->path($path)))
        ->toMatchArray([0 => 1200, 1 => 630]);
});

it('non porta dentro l\'anteprima i metadati della locandina', function (): void {
    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-12 21:30')->event;

    $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpegWithExif(900, 1200)))
        ->toMediaCollection('poster');

    $path = app(OpenGraphImage::class)->render($event->refresh());
    $exif = @exif_read_data(Storage::disk('public')->path($path));

    expect($exif === false ? [] : array_keys($exif))->not->toContain('Orientation');
});

it('chiede l\'anteprima alla coda e intanto ricade sulla locandina', function (): void {
    Queue::fake();

    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-12 21:30')->event;
    $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg()))->toMediaCollection('poster');

    $url = Poster::social($event->refresh())?->url;

    Queue::assertPushed(GenerateOpenGraphImage::class);

    expect($url)->toBe(Poster::absoluteUrl($event));
});

it('usa l\'anteprima appena esiste, con una versione nell\'indirizzo', function (): void {
    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-12 21:30')->event;

    app(OpenGraphImage::class)->render($event);

    $url = Poster::social($event)?->url;

    expect($url)->toContain('og/eventi/'.$event->getKey().'.jpg')
        ->and($url)->toContain('?v=');
});
