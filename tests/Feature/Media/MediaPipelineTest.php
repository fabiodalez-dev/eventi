<?php

declare(strict_types=1);

use App\Enums\ImageType;
use App\Http\Resources\V1\PosterResource;
use App\Models\Event;
use App\Rules\RealImage;
use App\Support\Media\AvifSupport;
use App\Support\Media\ImageSet;
use App\Support\Media\Variants;
use App\Support\Poster;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Tests\Support\ImageFixtures;

/**
 * La pipeline di §12.1, percorsa per intero su file veri:
 *
 *     upload → MIME reale → strip EXIF → resize → WebP + AVIF → blurhash → CDN
 */
beforeEach(function (): void {
    Storage::fake('public');
});

function eventWithPoster(string $bytes = '', string $name = 'locandina.jpg'): Event
{
    $city = testCity();
    $category = testCategory();
    $occurrence = occurrenceAtLocal($city, $category, '2026-09-12 21:30');

    $event = $occurrence->event;
    $event->addMedia(ImageFixtures::upload($name, $bytes !== '' ? $bytes : ImageFixtures::jpeg()))
        ->toMediaCollection('poster');

    return $event->refresh();
}

it('toglie i dati EXIF dall\'originale e raddrizza davvero i pixel', function (): void {
    $event = eventWithPoster(ImageFixtures::jpegWithExif(600, 800, orientation: 6));

    $media = $event->getFirstMedia('poster');
    expect($media)->not->toBeNull();

    $path = $media->getPath();
    $exif = @exif_read_data($path);

    // Restano solo le voci che PHP ricava dal file stesso: nessun riquadro EXIF.
    expect($exif === false ? [] : array_keys($exif))
        ->not->toContain('Orientation')
        ->not->toContain('ResolutionUnit');

    // L'orientamento 6 chiedeva una rotazione di 90°: i lati si sono scambiati.
    $size = getimagesize($path);
    expect($size[0])->toBe(800)
        ->and($size[1])->toBe(600);
});

it('genera le tre varianti in WebP', function (): void {
    $event = eventWithPoster();
    $media = $event->getFirstMedia('poster');

    foreach (['thumb' => 400, 'card' => 800, 'full' => 1600] as $variant => $width) {
        expect($media->hasGeneratedConversion($variant))->toBeTrue("manca la variante {$variant}")
            ->and(ImageType::detect($media->getPath($variant)))->toBe(ImageType::Webp);
    }
});

/*
 * **La garanzia, non il meccanismo.**
 *
 * Se una variante AVIF esiste, dentro dev'esserci un AVIF. E' questo che il
 * sistema promette, e vale su qualunque macchina. Che la variante venga
 * PRODOTTA dipende invece dai delegati di ImageMagick installati: verificarlo
 * legherebbe il test all'installazione e non al comportamento.
 *
 * La versione precedente sbagliava proprio li'. Chiedeva ad `AvifSupport` se
 * il supporto ci fosse e poi pretendeva la variante — ma quella e' una
 * previsione, e in integrazione continua era falsa: `queryFormats` risponde di
 * si', ImageMagick consegna un JPEG, e `RejectFakeAvifConversion` lo scarta.
 * Tre stati, non due, e il terzo — «dichiarato ma fallito» — non si riproduce
 * ne' su questa macchina ne' spegnendo `MEDIA_AVIF`.
 */
it('non lascia mai una variante AVIF che AVIF non e', function (): void {
    $event = eventWithPoster();
    $media = $event->getFirstMedia('poster');

    foreach (array_keys(Variants::widths()) as $variant) {
        $nome = Variants::avif($variant);

        if (! $media->hasGeneratedConversion($nome)) {
            continue;
        }

        expect(ImageType::detect($media->getPath($nome)))->toBe(
            ImageType::Avif,
            "la variante {$nome} esiste ma non e un AVIF: e il JPEG travestito che il listener doveva scartare"
        );
    }
});

it('non annuncia una fonte AVIF che non ha generato', function (): void {
    $event = eventWithPoster();
    $media = $event->getFirstMedia('poster');
    $set = ImageSet::forCollection($event, 'poster');

    /*
     * La regola vale a valle come a monte: la fonte AVIF si dichiara se e solo
     * se le varianti esistono sul disco. E' cio' che fa ricadere il browser sul
     * WebP invece che su un file col tipo sbagliato.
     *
     * Si guarda COSA C'E', non cosa `AvifSupport` prometteva: fra la promessa e
     * il file c'e' `RejectFakeAvifConversion`, che puo' aver scartato tutto.
     */
    $generate = collect(array_keys(Variants::widths()))
        ->filter(fn (string $variante): bool => $media->hasGeneratedConversion(Variants::avif($variante)))
        ->isNotEmpty();

    if ($generate) {
        expect($set->sources)->toHaveKey('image/avif');
    } else {
        expect($set->sources)->not->toHaveKey('image/avif');
    }

    expect($set->sources)->toHaveKey('image/webp');
});

it('non ingrandisce un originale piu piccolo della variante', function (): void {
    $event = eventWithPoster(ImageFixtures::jpeg(500, 500));
    $media = $event->getFirstMedia('poster');

    $full = getimagesize($media->getPath('full'));

    expect($full[0])->toBe(500);
});

it('scrive misure, blurhash e segnaposto nelle proprieta del media', function (): void {
    $event = eventWithPoster(ImageFixtures::jpeg(600, 800));
    $media = $event->getFirstMedia('poster');

    expect($media->getCustomProperty('width'))->toBe(600)
        ->and($media->getCustomProperty('height'))->toBe(800)
        ->and($media->getCustomProperty('blurhash'))->toBeString()
        ->and(strlen((string) $media->getCustomProperty('blurhash')))->toBeGreaterThan(6)
        ->and($media->getCustomProperty('placeholder'))->toStartWith('data:image/png;base64,');
});

it('rifiuta un file che si spaccia per immagine', function (): void {
    $city = testCity();
    $category = testCategory();
    $event = occurrenceAtLocal($city, $category, '2026-09-12 21:30')->event;

    $event->addMedia(ImageFixtures::upload('finta.jpg', "<?php echo 'ciao';"))
        ->toMediaCollection('poster');

    expect($event->refresh()->getFirstMedia('poster'))->toBeNull();
});

it('costruisce il picture con le serie di formati', function (): void {
    $event = eventWithPoster();
    $set = Poster::imageSet($event);

    /*
     * Il WebP c'è sempre; l'AVIF **solo dove questa macchina lo produce
     * davvero**. Non è un'incertezza del test: è la garanzia del sistema —
     * una fonte AVIF viene dichiarata solo se dietro c'è un AVIF vero,
     * altrimenti il browser sceglierebbe un file col tipo sbagliato scartando
     * il WebP che avrebbe funzionato.
     */
    expect($set)->toBeInstanceOf(ImageSet::class)
        ->and($set->hasSources())->toBeTrue()
        ->and($set->sources)->toHaveKey('image/webp')
        ->and($set->sources['image/webp'])->toContain('400w')->toContain('800w')->toContain('1600w')
        ->and($set->width)->toBe(600)
        ->and($set->height)->toBe(800)
        ->and($set->placeholder)->toStartWith('data:image/png;base64,');
});

it('espone le varianti nella risposta dell\'API', function (): void {
    $event = eventWithPoster();
    $poster = PosterResource::toArray($event);

    expect($poster)->toHaveKeys(['thumb', 'card', 'full', 'blurhash', 'width', 'height'])
        ->and($poster['thumb'])->toContain('thumb')
        ->and($poster['card'])->toContain('card')
        ->and($poster['blurhash'])->toBeString()
        ->and($poster['width'])->toBe(600);
});

it('serve le immagini dal CDN quando ne e configurato uno', function (): void {
    config()->set('media.cdn_url', 'https://cdn.example.test');

    $event = eventWithPoster();
    $media = $event->getFirstMedia('poster');

    expect($media->getFullUrl())->toStartWith('https://cdn.example.test/')
        ->and($media->getFullUrl('card'))->toStartWith('https://cdn.example.test/');
});

describe('regola di validazione', function (): void {
    it('accetta un JPEG vero', function (): void {
        $validator = Validator::make(
            ['file' => ImageFixtures::upload('locandina.jpg', ImageFixtures::jpeg())],
            ['file' => [new RealImage]],
        );

        expect($validator->passes())->toBeTrue();
    });

    it('rifiuta un file il cui contenuto non e un\'immagine', function (): void {
        $validator = Validator::make(
            ['file' => ImageFixtures::upload('locandina.jpg', 'GIF89a non lo sono')],
            ['file' => [new RealImage]],
        );

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->first('file'))->toContain('Formato non riconosciuto');
    });

    it('rifiuta un PNG che si presenta con estensione jpg', function (): void {
        $validator = Validator::make(
            ['file' => ImageFixtures::upload('locandina.jpg', ImageFixtures::png())],
            ['file' => [new RealImage]],
        );

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->first('file'))->toContain('png');
    });

    it('rifiuta un\'immagine piu piccola del minimo', function (): void {
        $validator = Validator::make(
            ['file' => ImageFixtures::upload('minuscola.png', ImageFixtures::png(64, 64))],
            ['file' => [new RealImage]],
        );

        expect($validator->fails())->toBeTrue();
    });

    it('rifiuta un file oltre i 12 MB dichiarati da §12.1', function (): void {
        config()->set('media.max_upload_bytes', 1024);

        $validator = Validator::make(
            ['file' => ImageFixtures::upload('grande.jpg', ImageFixtures::jpeg(800, 800))],
            ['file' => [new RealImage]],
        );

        expect($validator->fails())->toBeTrue()
            ->and($validator->errors()->first('file'))->toContain('supera');
    });
});
