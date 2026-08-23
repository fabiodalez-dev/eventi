<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\EventStatus;
use App\Jobs\Media\GenerateOpenGraphImage;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Services\Media\OpenGraphImage;
use App\Services\Notifications\NotificationScheduler;
use App\Support\ContentVersion;
use Illuminate\Database\Eloquent\Collection;

/**
 * `business_date` ed `effective_ends_at` dipendono dalla categoria dell'evento
 * (`is_nightlife`, `default_duration_minutes`) e dalla sua città (fuso orario e
 * `night_cutoff_time`). Se uno dei due cambia, le occorrenze già salvate
 * restano ferme su un calcolo che non vale più: qui vengono risalvate, così
 * che `EventOccurrenceObserver` le ricalcoli.
 */
final class EventObserver
{
    /**
     * Occorrenze ricaricate a blocchi: un evento con una ricorrenza
     * pluriennale ne ha centinaia.
     */
    private const CHUNK = 200;

    /**
     * I conteggi del calendario mensile stanno in cache per mezz'ora (§12.3),
     * ma un evento pubblicato, ritirato o spostato deve comparire subito: la
     * cache della città si invalida qui, che è il punto attraversato da ogni
     * salvataggio comunque sia avvenuto — pannello, import o comando.
     */
    public function saved(Event $event): void
    {
        ContentVersion::bump((int) $event->city_id);
    }

    public function deleted(Event $event): void
    {
        ContentVersion::bump((int) $event->city_id);

        OpenGraphImage::forget($event);
    }

    public function updated(Event $event): void
    {
        /*
         * L'anteprima social porta scritti dentro il titolo e la data (§12.1):
         * se cambiano, il file sul disco racconta un evento che non esiste più.
         * Rifarla è un lavoro di coda, non una cosa da fare mentre qualcuno
         * aspetta il salvataggio.
         */
        if ($event->wasChanged(['title', 'subtitle', 'status', 'venue_id'])) {
            GenerateOpenGraphImage::dispatch($event);
        }

        /*
         * §15.4, ultima riga: «ai gestori — evento pubblicato / rifiutato».
         * L'esito di una proposta è la sola notizia che chi l'ha scritta sta
         * davvero aspettando, ed è quindi l'unica che parte dal cambio di
         * stato dell'evento e non da una data.
         */
        if ($event->wasChanged('status')) {
            $scheduler = app(NotificationScheduler::class);

            match ($event->status) {
                EventStatus::Published => $scheduler->announceEventPublished($event),
                EventStatus::Rejected => $scheduler->announceEventRejected($event),
                default => null,
            };
        }

        if (! $event->wasChanged(['category_id', 'city_id'])) {
            return;
        }

        $event->load(['city', 'category', 'venue']);

        $event->occurrences()
            ->orderBy('id')
            ->chunkById(self::CHUNK, function (Collection $occurrences) use ($event): void {
                /** @var EventOccurrence $occurrence */
                foreach ($occurrences as $occurrence) {
                    $occurrence->setRelation('event', $event);
                    $occurrence->save();
                }
            });
    }
}
