<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Imagick;
use ImagickException;
use Spatie\MediaLibrary\Conversions\Events\ConversionHasBeenCompletedEvent;
use Throwable;

/**
 * Ricomprime le varianti che pesano troppo.
 *
 * **Perché una qualità fissa non basta.** Le conversioni chiedono un numero —
 * 82 per il WebP, 55 per l'AVIF — che è una richiesta, non un risultato: quanto
 * pesi il file dipende da quanto è dettagliata l'immagine. Su una locandina
 * pulita l'AVIF esce a 35 KB; su una fotografia notturna piena di grana la
 * stessa qualità ne produce 231, e in produzione nessuno se ne accorge finché
 * la pagina iniziale non diventa lenta per tutti.
 *
 * Verificato: quel numero è comparso in un referto come «LCP alto», che manda
 * a cercare nel codice un problema che sta in un file caricato da qualcuno.
 *
 * **Il tetto sta sul risultato, non sul parametro.** Si scende di qualità a
 * gradini finché il file non rientra, e ci si ferma a un minimo: sotto una
 * certa soglia si vedono gli artefatti, e un'immagine brutta è un danno
 * peggiore di un'immagine pesante. Se al minimo non rientra ancora, si tiene
 * quella che c'è e si scrive nel registro — perché a quel punto il problema è
 * l'originale, e va risolto da chi l'ha caricato, non da qui.
 *
 * **Perché in coda e non durante il caricamento.** Ricomprimere costa, e
 * costa in modo imprevedibile: qui siamo nello stesso posto dove Spatie ha
 * appena finito di scrivere la variante (§12.1), dove il tempo non lo aspetta
 * nessuno.
 */
class CompressOversizedConversion
{
    /**
     * Oltre questo peso una variante si ricomprime.
     *
     * 120 KB su banda simulata lenta sono poco più di mezzo secondo: sopra,
     * un'immagine comincia a spostare il momento in cui la pagina si vede.
     * Sotto, il guadagno non vale la perdita di qualità.
     */
    private const TETTO_BYTE = 120 * 1024;

    /** I gradini di qualità, dal più conservativo al più aggressivo. */
    private const GRADINI = [70, 60, 50, 40];

    public function handle(ConversionHasBeenCompletedEvent $event): void
    {
        $percorso = $event->media->getPath($event->conversion->getName());

        if (! is_file($percorso) || filesize($percorso) <= self::TETTO_BYTE) {
            return;
        }

        $partenza = (int) filesize($percorso);

        try {
            $this->ricomprimi($percorso, $partenza, $event->conversion->getName());
        } catch (Throwable $e) {
            /*
             * Una ricompressione fallita lascia il file com'era: pesante ma
             * valido. Fallire qui distruggerebbe una variante buona per non
             * essere riusciti a farla più piccola.
             */
            Log::warning('[media] Non sono riuscito a ricomprimere una variante troppo pesante.', [
                'file' => basename($percorso),
                'errore' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @throws ImagickException
     */
    private function ricomprimi(string $percorso, int $partenza, string $variante): void
    {
        foreach (self::GRADINI as $qualita) {
            $immagine = new Imagick($percorso);
            $immagine->setImageCompressionQuality($qualita);

            $blob = $immagine->getImageBlob();
            $immagine->clear();

            if (strlen($blob) <= self::TETTO_BYTE) {
                file_put_contents($percorso, $blob);

                Log::info('[media] Variante ricompressa per rientrare nel tetto.', [
                    'file' => basename($percorso),
                    'variante' => $variante,
                    'da' => $partenza,
                    'a' => strlen($blob),
                    'qualita' => $qualita,
                ]);

                return;
            }
        }

        /*
         * Nemmeno al minimo rientra: il problema è l'originale — una foto
         * enorme, o piena di grana che nessuna compressione perdona. Si tiene
         * quella che c'è, perché brutta e leggera è peggio di grande e bella,
         * e si lascia scritto dove guardare.
         */
        Log::warning('[media] Variante sopra il tetto anche alla qualità minima: guardare l\'originale.', [
            'file' => basename($percorso),
            'variante' => $variante,
            'byte' => $partenza,
            'tetto' => self::TETTO_BYTE,
        ]);
    }
}
