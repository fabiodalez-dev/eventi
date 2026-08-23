<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Media\Variants;
use Spatie\Image\Enums\Constraint;
use Spatie\MediaLibrary\Conversions\Conversion;

/**
 * Le varianti di §12.1 — `thumb 400w`, `card 800w`, `full 1600w`, ciascuna in
 * **WebP e AVIF** — dichiarate una volta sola e riusate da `Event` e `Venue`.
 *
 * Perché due formati e non uno: AVIF pesa dal 20 al 40 per cento meno del
 * WebP a parità di resa, ma non tutti i browser in circolazione lo aprono. Il
 * `<picture>` li offre in ordine e il browser prende il primo che sa leggere,
 * quindi generarli entrambi non è ridondanza — è la sola forma in cui l'AVIF
 * si può usare senza lasciare indietro nessuno.
 *
 * Le larghezze e le qualità stanno in `config/media.php`: cambiare `card` in
 * un punto solo deve bastare.
 *
 * Nessuna variante ingrandisce (`DoNotUpsize`). Una locandina da 600 px non
 * diventa un `full` da 1600 px sgranato: resta 600, e il `<picture>` la serve
 * comunque perché il browser sceglie in base allo spazio che ha, non al nome
 * della variante.
 */
trait HasImageVariants
{
    /**
     * Dichiara le sei conversioni. La chiama `registerMediaConversions()` di
     * ogni modello: il metodo resta scritto dove la libreria media lo cerca,
     * e le misure restano scritte in un posto solo.
     */
    protected function registerImageVariants(): void
    {
        foreach (Variants::widths() as $name => $width) {
            $this->variant($this->addMediaConversion($name), $width, 'webp');
            $this->variant($this->addMediaConversion(Variants::avif($name)), $width, 'avif');
        }
    }

    /**
     * Ogni conversione è in coda (§12.1: «tutto in coda, mai bloccante») e
     * parte dall'originale già ripulito da `ProcessImageMedia`.
     */
    private function variant(Conversion $conversion, int $width, string $format): void
    {
        $conversion->queued();

        $conversion
            ->width($width, [Constraint::PreserveAspectRatio, Constraint::DoNotUpsize])
            ->format($format)
            ->quality(config()->integer('media.quality.'.$format));
    }
}
