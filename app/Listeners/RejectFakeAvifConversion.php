<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ImageType;
use App\Support\Media\Variants;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;

/**
 * Butta via le varianti «AVIF» che AVIF non sono.
 *
 * ImageMagick, richiesto un formato che non sa codificare, non protesta:
 * ricade su un altro formato e restituisce quello con il nome chiesto. Il file
 * `.avif` esiste, pesa il giusto, e dentro è un JPEG. A valle il `<picture>`
 * lo annuncia come `type="image/avif"`: un browser che accetta AVIF sceglie
 * proprio quella fonte e riceve un file che non lo è — mentre la variante
 * WebP, che avrebbe funzionato, non viene nemmeno considerata.
 *
 * **Il controllo sta qui e non prima, perché qui è al sicuro.** La verifica
 * onesta sarebbe provare a codificare e guardare cosa esce, ma quella prova
 * nel percorso della richiesta ha abbattuto il sito: AV1 lavora per blocchi e
 * su certe dimensioni il codificatore AOM va in `assert` invece di restituire
 * un errore. Qui invece si legge un file già scritto, in coda (§12.1), dove
 * niente travolge nessuno.
 *
 * Eliminata la variante, `ImageSet` non la trova e non la dichiara: il
 * `<picture>` offre solo il WebP e ogni browser prende quello. Si perde il
 * 20-40 per cento di risparmio dell'AVIF su quella installazione; non si perde
 * l'immagine.
 */
class RejectFakeAvifConversion
{
    public function handle(ConversionHasBeenCompletedEvent $event): void
    {
        $nome = $event->conversion->getName();

        if (! Variants::isAvif($nome)) {
            return;
        }

        $media = $event->media;
        $percorso = $media->getPath($nome);

        if (ImageType::detect($percorso) === ImageType::Avif) {
            return;
        }

        /* Il registro serve: una macchina che promette AVIF e consegna JPEG è
           una configurazione da sistemare, non un caso da assorbire in
           silenzio per sempre. */
        Log::warning('Variante AVIF scartata: il file prodotto non è un AVIF.', [
            'media' => $media->getKey(),
            'conversione' => $nome,
        ]);

        $media->markAsConversionNotGenerated($nome);
        $media->save();

        if (is_file($percorso)) {
            @unlink($percorso);
        }
    }
}
