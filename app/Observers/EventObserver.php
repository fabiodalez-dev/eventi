<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\EventStatus;
use App\Enums\PriceType;
use App\Enums\VerificationStatus;
use App\Jobs\Media\GenerateOpenGraphImage;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Services\Media\OpenGraphImage;
use App\Services\Notifications\NotificationScheduler;
use App\Support\ContentVersion;
use App\Support\Redirect\RegistroRedirect;
use Illuminate\Auth\Access\AuthorizationException;
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

    public function saving(Event $event): void
    {
        // Confirmation follows the venue; editorial verification remains a staff decision.
        if ($event->verification_status !== VerificationStatus::EditorialChecked) {
            $event->verification_status = $event->venue?->is_verified === true
                ? VerificationStatus::VenueConfirmed : VerificationStatus::Unverified;
        }
        if ($event->price_type === PriceType::Free) {
            $event->price_min = null;
            $event->price_max = null;
        }
        if (in_array($event->status, [EventStatus::Rejected, EventStatus::Cancelled, EventStatus::Archived], true)) {
            $event->scheduled_publish_at = null;
            $event->publication_scheduled_by = null;
        }
        $user = auth()->user();
        if ($user && ! $user->can('moderate', $event)) {
            if ($event->exists && $event->scheduled_publish_at && ! $user->can('publish', $event)
                && array_diff(array_keys($event->getDirty()), ['status', 'scheduled_publish_at', 'publication_scheduled_by', 'updated_at']) !== []) {
                $event->publication_scheduled_by = $user->id;
                $event->status = EventStatus::Pending;
            }
            if ($event->isDirty('verification_status') && $event->verification_status === VerificationStatus::EditorialChecked) {
                throw new AuthorizationException('Verifica riservata alla redazione.');
            }
            foreach (['editorial_score', 'is_featured', 'featured_until', 'rejection_reason'] as $field) {
                if ($event->isDirty($field) && $event->getRawOriginal($field) !== ($event->getAttributes()[$field] ?? null) && ($event->exists || ! in_array($event->getAttributes()[$field] ?? null, [null, false, 0, '0', 'unverified'], true))) {
                    throw new AuthorizationException('Campo riservato alla redazione.');
                }
            }
        }
    }

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

        /*
         * Uno slug che cambia è un indirizzo che muore. `HasSlug` lo rigenera
         * a ogni salvataggio — `Event` non ha `doNotGenerateSlugsOnUpdate()`,
         * che solo `Page` ha — e il campo è modificabile anche a mano dal
         * pannello: basta correggere un refuso nel titolo perché ogni link
         * condiviso, ogni pagina indicizzata e ogni QR stampato su una
         * locandina rispondano 404, senza che nessuno se ne accorga.
         *
         * La riga si scrive sulla città **di prima**: è lì che il vecchio
         * indirizzo viveva. Un evento che cambia città nello stesso
         * salvataggio sposta un intero spazio di indirizzi, ed è il caso in
         * cui la redazione scrive la riga a mano dal pannello — non c'è una
         * destinazione sola che si possa indovinare.
         */
        if ($event->wasChanged('slug')) {
            app(RegistroRedirect::class)->registra(
                (int) $event->getOriginal('city_id'),
                '/eventi/'.$event->getOriginal('slug'),
                '/eventi/'.$event->slug,
            );
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
