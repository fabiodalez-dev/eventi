<?php

declare(strict_types=1);

use App\Listeners\RejectFakeAvifConversion;
use App\Support\Media\AvifSupport;
use App\Support\Media\ImageSet;
use App\Support\Media\Variants;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Tests\Support\ImageFixtures;

/**
 * Il caso «questa macchina promette AVIF e consegna JPEG».
 *
 * ImageMagick senza il delegato libheif non protesta: scrive un JPEG e gli dà
 * il nome chiesto. Il file `.avif` esiste, il `<picture>` lo annuncia come
 * `type="image/avif"`, e un browser che accetta AVIF sceglie proprio quella
 * fonte ricevendo un file che non lo è.
 *
 * Sulla macchina di sviluppo il delegato c'è, quindi questo ramo non verrebbe
 * mai attraversato da solo — ed è esattamente come il difetto è rimasto
 * nascosto fino a quando l'integrazione continua ha cominciato a produrre
 * varianti travestite.
 */
afterEach(function (): void {
    AvifSupport::forget();
});

it('non dichiara nessuna variante AVIF dove il supporto è spento', function (): void {
    AvifSupport::fake(false);

    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-20 21:00:00')->event;
    $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg()))->toMediaCollection('poster');

    $media = $event->refresh()->getFirstMedia('poster');

    foreach (Variants::names() as $variante) {
        expect($media->hasGeneratedConversion(Variants::avif($variante)))->toBeFalse();
        /* Il WebP c'è: si perde il risparmio dell'AVIF, non l'immagine. */
        expect($media->hasGeneratedConversion($variante))->toBeTrue();
    }

    $set = ImageSet::forCollection($event, 'poster');

    expect($set->sources)->not->toHaveKey('image/avif')
        ->and($set->sources)->toHaveKey('image/webp');
});

/*
 * La rete di sicurezza: anche quando il supporto è dichiarato, il file prodotto
 * si controlla. È qui che si intercetta la macchina che mente.
 */
it('scarta la variante AVIF quando il file prodotto è un JPEG travestito', function (): void {
    /*
     * Il supporto si accende a mano: serve che la conversione sia REGISTRATA,
     * altrimenti non esiste un percorso in cui mettere il file falso e il test
     * non verifica niente. È il caso della macchina che dichiara di saper
     * scrivere AVIF e poi consegna un JPEG — quello che questo listener esiste
     * per intercettare.
     */
    AvifSupport::fake(true);

    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-20 21:00:00')->event;
    $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg()))->toMediaCollection('poster');

    $media = $event->refresh()->getFirstMedia('poster');
    $nome = Variants::avif('card');

    /* Si mette un JPEG dove dovrebbe esserci un AVIF: è ciò che fa ImageMagick
       senza il delegato, senza dire niente. */
    file_put_contents($media->getPath($nome), ImageFixtures::jpeg());

    (new RejectFakeAvifConversion)->handle(
        new ConversionHasBeenCompletedEvent($media, Conversion::create($nome))
    );

    expect($media->fresh()->hasGeneratedConversion($nome))->toBeFalse()
        ->and(is_file($media->getPath($nome)))->toBeFalse();
});

it('lascia stare una variante AVIF vera', function (): void {
    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-20 21:00:00')->event;
    $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg()))->toMediaCollection('poster');

    $media = $event->refresh()->getFirstMedia('poster');
    $nome = Variants::avif('card');

    /*
     * Si salta guardando COSA C'E' sul disco, non cosa `available()` prometteva:
     * su una macchina senza delegato la variante e' gia' stata scartata, e non
     * c'e' niente da lasciar stare.
     */
    if (! $media->hasGeneratedConversion($nome)) {
        $this->markTestSkipped('Questa macchina non ha prodotto un AVIF: non c\'è niente da lasciar stare.');
    }

    (new RejectFakeAvifConversion)->handle(
        new ConversionHasBeenCompletedEvent($media, Conversion::create($nome))
    );

    expect($media->fresh()->hasGeneratedConversion($nome))->toBeTrue()
        ->and(is_file($media->getPath($nome)))->toBeTrue();
});

it('non tocca le varianti che non sono AVIF', function (): void {
    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-20 21:00:00')->event;
    $event->addMedia(ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg()))->toMediaCollection('poster');

    $media = $event->refresh()->getFirstMedia('poster');

    (new RejectFakeAvifConversion)->handle(
        new ConversionHasBeenCompletedEvent($media, Conversion::create('card'))
    );

    expect($media->fresh()->hasGeneratedConversion('card'))->toBeTrue();
});
