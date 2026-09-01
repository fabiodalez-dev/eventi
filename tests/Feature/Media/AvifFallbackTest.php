<?php

declare(strict_types=1);

use App\Enums\ImageType;
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

it('dice di saper scrivere AVIF solo se lo scrive davvero', function (): void {
    AvifSupport::forget();

    /*
     * Non si verifica QUALE sia la risposta — dipende dalla macchina — ma che
     * corrisponda a cio' che ImageMagick produce davvero.
     *
     * La prima versione di questo controllo chiedeva `queryFormats('AVIF')`,
     * che elenca i formati NOTI e non quelli scrivibili: rispondeva di si' su
     * una macchina che poi scriveva JPEG. La domanda giusta e' scrivere un
     * pixel e guardare i primi byte del risultato — che e' quello che fa
     * questo test, in modo indipendente.
     */
    $davvero = false;

    if (class_exists(Imagick::class)) {
        try {
            $imagick = new Imagick;
            $imagick->newImage(1, 1, 'white');
            $imagick->setImageFormat('avif');
            $davvero = ImageType::fromHeader($imagick->getImageBlob()) === ImageType::Avif;
            $imagick->clear();
        } catch (Throwable) {
            $davvero = false;
        }
    }

    expect(AvifSupport::available())->toBe($davvero);
});
