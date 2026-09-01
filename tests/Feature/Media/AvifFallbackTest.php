<?php

declare(strict_types=1);

use App\Support\Media\AvifSupport;
use App\Support\Media\ImageSet;
use App\Support\Media\Variants;
use Tests\Support\ImageFixtures;

/**
 * Il ramo «questa macchina non sa scrivere AVIF», forzato.
 *
 * Sulla macchina di sviluppo il delegato c'e', e senza forzarlo questo ramo non
 * verrebbe mai attraversato — che e' esattamente come il difetto e' rimasto
 * nascosto fino a quando l'integrazione continua, che il delegato non ce l'ha,
 * ha cominciato a produrre JPEG chiamati AVIF.
 */
afterEach(function (): void {
    AvifSupport::forget();
});

it('non genera nessuna variante AVIF dove il delegato manca', function (): void {
    AvifSupport::fake(false);

    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-20 21:00:00')->event;
    $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg()))->toMediaCollection('poster');

    $media = $event->refresh()->getFirstMedia('poster');

    foreach (array_keys(Variants::widths()) as $variante) {
        expect($media->hasGeneratedConversion(Variants::avif($variante)))->toBeFalse();
        /* Il WebP invece c'e': si perde il risparmio dell'AVIF, non l'immagine. */
        expect($media->hasGeneratedConversion($variante))->toBeTrue();
    }

    $set = ImageSet::forCollection($event, 'poster');

    expect($set->sources)->not->toHaveKey('image/avif')
        ->and($set->sources)->toHaveKey('image/webp');
});

it('riconosce il supporto reale di questa installazione', function (): void {
    AvifSupport::forget();

    /* Non si verifica QUALE sia la risposta — dipende dalla macchina — ma che
       la domanda venga fatta a ImageMagick e non data per scontata. */
    $atteso = class_exists(Imagick::class) && Imagick::queryFormats('AVIF') !== [];

    expect(AvifSupport::available())->toBe($atteso);
});
