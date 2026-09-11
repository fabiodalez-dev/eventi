<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EventStatus;
use App\Enums\VerificationStatus;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Copia un evento (§9.2 e §10.3). Serve ai cartelloni che si ripetono con
 * piccole varianti: stessa scheda, date nuove.
 *
 * La copia nasce **bozza**, non verificata e senza slug: lo slug lo rigenera
 * `spatie/laravel-sluggable` dal titolo, unico per città (D12). Quello che
 * *non* viene copiato conta quanto ciò che viene copiato:
 *
 * - le **date** e le **ricorrenze**, perché duplicare un evento serve appunto
 *   a dargliene di nuove — copiarle produrrebbe due eventi identici lo stesso
 *   giorno, cioè esattamente i duplicati che §14.4 chiede di scovare;
 * - la **provenienza esterna** (`source_ref`), che identifica una riga nella
 *   sorgente di import: copiarla romperebbe l'idempotenza dell'import;
 * - le **statistiche**, la data di pubblicazione e il motivo del rifiuto;
 * - la **lineup**, che appartiene alle singole date e non all'evento: senza
 *   date da copiare non c'è nessun posto dove metterla, e la si aggiunge alla
 *   prima data della copia.
 */
final class DuplicateEventAction
{
    public function execute(Event $event, User $author): Event
    {
        return DB::transaction(function () use ($event, $author): Event {
            $copy = $event->replicate([
                'slug',
                'published_at',
                'scheduled_publish_at',
                'publication_scheduled_by',
                'rejection_reason',
                'source_ref',
                'views_count',
                'saves_count',
                'created_at',
                'updated_at',
                'deleted_at',
            ]);

            $copy->title = __('admin.notifications.duplicate_title', ['title' => $event->title]);
            $copy->status = EventStatus::Draft;
            $copy->verification_status = VerificationStatus::Unverified;
            $copy->is_featured = false;
            $copy->featured_until = null;
            $copy->created_by = $author->getKey();
            $copy->save();

            $copy->tags()->sync($event->tags()->pluck('tags.id')->all());

            // La locandina sì: §10.3 la elenca fra ciò che la copia deve già
            // avere, e un evento senza immagine è la prima cosa che §14.5
            // segnala come incompleta. Il file viene duplicato davvero, non
            // condiviso: cancellare l'originale non deve svuotare la copia.
            $event->getFirstMedia('poster')?->copy($copy, 'poster');

            return $copy;
        });
    }
}
