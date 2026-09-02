<?php

declare(strict_types=1);

use App\Listeners\CompressOversizedConversion;
use App\Models\Event;
use App\Support\Media\Variants;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/*
 * Il tetto sul peso delle varianti.
 *
 * Le conversioni chiedono una qualità — 82 per il WebP, 55 per l'AVIF — che è
 * una richiesta, non un risultato: quanto pesi il file dipende da quanto è
 * dettagliata l'immagine. Su una locandina pulita l'AVIF esce a 35 KB; su una
 * fotografia piena di grana la stessa qualità ne produce 231, e la pagina
 * iniziale diventa lenta per tutti senza che nessuno se ne accorga — nel
 * referto compare come «LCP alto», che manda a cercare nel posto sbagliato.
 */

/** Un file immagine di prova, con dentro rumore vero. */
function immagineDiProva(int $lato, bool $conRumore): string
{
    $percorso = sys_get_temp_dir().'/prova-'.bin2hex(random_bytes(6)).'.jpg';

    $im = new Imagick;
    $im->newImage($lato, $lato, new ImagickPixel('white'));

    if ($conRumore) {
        /* Il rumore è ciò che nessuna compressione perdona: senza, qualunque
           immagine grande si comprime a pochi kilobyte e il test non
           proverebbe niente. */
        $im->addNoiseImage(Imagick::NOISE_UNIFORM);
    }

    $im->setImageFormat('jpeg');
    $im->setImageCompressionQuality(100);
    $im->writeImage($percorso);
    $im->clear();

    return $percorso;
}

/** Fa girare il listener su un file, come farebbe Spatie a conversione finita. */
function eseguiSu(string $percorso): void
{
    $media = Mockery::mock(Media::class);
    $media->shouldReceive('getPath')->andReturn($percorso);

    $conversione = Mockery::mock(Conversion::class);
    $conversione->shouldReceive('getName')->andReturn('card');

    (new CompressOversizedConversion)->handle(
        new ConversionHasBeenCompletedEvent($media, $conversione),
    );
}

it('lascia stare una variante che sta nel tetto', function (): void {
    $percorso = immagineDiProva(200, conRumore: false);
    $prima = filesize($percorso);

    eseguiSu($percorso);

    expect(filesize($percorso))->toBe($prima);

    unlink($percorso);
});

it('ricomprime una variante troppo pesante', function (): void {
    $percorso = immagineDiProva(1400, conRumore: true);
    $prima = filesize($percorso);

    expect($prima)->toBeGreaterThan(120 * 1024, 'il campione deve superare il tetto, altrimenti il test non prova niente');

    eseguiSu($percorso);

    $dopo = filesize($percorso);

    expect($dopo)->toBeLessThan($prima)
        ->and($dopo)->toBeLessThanOrEqual(120 * 1024);

    unlink($percorso);
});

it('lascia un file valido dopo la ricompressione', function (): void {
    /*
     * Una variante ricompressa male è peggio di una pesante: il visitatore
     * vedrebbe un'immagine rotta invece di una lenta.
     */
    $percorso = immagineDiProva(1400, conRumore: true);

    eseguiSu($percorso);

    $im = new Imagick($percorso);

    expect($im->getImageWidth())->toBe(1400)
        ->and($im->getImageHeight())->toBe(1400);

    $im->clear();
    unlink($percorso);
});

it('tiene la variante e lo scrive quando nemmeno il minimo basta', function (): void {
    /*
     * A quel punto il problema è l'originale — una foto enorme, o piena di
     * grana — e va risolto da chi l'ha caricato. Brutta e leggera sarebbe
     * peggio di grande e bella: si tiene quella che c'è e si lascia scritto
     * dove guardare.
     */
    Log::spy();

    $percorso = immagineDiProva(4000, conRumore: true);
    $prima = filesize($percorso);

    eseguiSu($percorso);

    expect(file_exists($percorso))->toBeTrue()
        ->and(filesize($percorso))->toBeGreaterThan(0);

    if (filesize($percorso) > 120 * 1024) {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m): bool => str_contains($m, 'qualità minima'));
    } else {
        expect(filesize($percorso))->toBeLessThan($prima);
    }

    unlink($percorso);
});

it('non si arrabbia se il file non c e piu', function (): void {
    /* Fra la conversione e questo listener può essere passato di tutto: una
       cancellazione, un disco pieno. Un file mancante non è un errore da
       propagare. */
    eseguiSu(sys_get_temp_dir().'/questo-file-non-esiste-'.bin2hex(random_bytes(4)).'.jpg');
})->throwsNoExceptions();

it('tiene nel tetto le conversioni vere, non solo quelle di prova', function (): void {
    /*
     * **La prova di integrazione, che è quella che mancava.**
     *
     * I test qui sopra chiamano il listener a mano: verificano che sappia
     * ricomprimere, non che venga davvero invocato quando Spatie finisce una
     * conversione. È una differenza che si paga: la prima versione passava
     * tutti quei test mentre in produzione le locandine restavano da 231 KB,
     * perché una cosa è saper fare il lavoro e un'altra è essere chiamati a
     * farlo.
     *
     * Qui si carica un'immagine vera su un evento vero e si guardano i file
     * che ne escono.
     */
    $percorso = immagineDiProva(1600, conRumore: true);

    expect(filesize($percorso))->toBeGreaterThan(120 * 1024);

    $evento = Event::factory()->create();
    $media = $evento->addMedia($percorso)->preservingOriginal()->toMediaCollection('poster');

    $sopraIlTetto = [];

    foreach (['thumb', 'card', 'full'] as $variante) {
        foreach ([$variante, Variants::avif($variante)] as $nome) {
            $file = $media->getPath($nome);

            if (is_file($file) && filesize($file) > 120 * 1024) {
                $sopraIlTetto[$nome] = round(filesize($file) / 1024).' KB';
            }
        }
    }

    expect($sopraIlTetto)->toBe([], 'una locandina caricata da un locale non deve poter rallentare la pagina iniziale per tutti');

    unlink($percorso);
});
