<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use App\Models\Event;
use App\Services\Media\OpenGraphImage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Compone l'anteprima social 1200×630 di un evento (§12.1) fuori dalla
 * richiesta.
 *
 * È `ShouldBeUnique` perché i motivi per rifarla sono molti e arrivano
 * insieme: si pubblica l'evento, si carica la locandina, si aggiunge una data.
 * Senza il vincolo, salvare un evento con tre date accodherebbe tre volte lo
 * stesso disegno.
 */
final class GenerateOpenGraphImage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Il vincolo di unicità dura un minuto: abbastanza perché la raffica di
     * salvataggi di una stessa modifica produca un disegno solo, poco
     * abbastanza perché una correzione fatta due minuti dopo si veda.
     */
    public int $uniqueFor = 60;

    public function __construct(public readonly Event $event) {}

    public function uniqueId(): string
    {
        return 'og-evento-'.$this->event->getKey();
    }

    public function handle(OpenGraphImage $images): void
    {
        if (! config()->boolean('media.open_graph.enabled')) {
            return;
        }

        $event = $this->event->loadMissing(['city', 'venue', 'category']);

        if ($images->render($event) !== null) {
            return;
        }

        Log::warning('Anteprima social non composta', [
            'event_id' => $event->getKey(),
        ]);
    }
}
