<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Gate;

/**
 * Quando un contenuto nuovo rimanda in revisione un evento già pubblicato.
 *
 * È l'estrazione minima della regola che stava dentro le due pagine
 * «Modifica evento» dei pannelli, fatta per la locandina caricata dall'app:
 * le pagine e l'API la chiedono qui, e la regola resta una. Che cosa conti
 * come «contenuto nuovo» (testo, collegamenti, locandina, listino; non prezzo
 * e categoria) lo decide ancora chi chiama: l'estrazione completa del
 * salvataggio dell'evento arriva con il pacchetto Eventi dell'app.
 *
 * - Per un locale: torna in revisione solo se il locale non può pubblicare da
 *   sé (`EventPolicy::publish`, che guarda `venues.auto_publish`).
 * - Per un'organizzazione: ogni modifica a un evento pubblicato torna alla
 *   redazione, perché l'organizzazione non pubblica mai da sé.
 */
final class EventReviewRule
{
    /** Vero se un contenuto nuovo, salvato da questa persona per questo soggetto, rimanda l'evento in revisione. */
    public function sendsToReview(Event $event, Venue|Organizer $subject, User $user): bool
    {
        if ($event->status !== EventStatus::Published) {
            return false;
        }

        return $subject instanceof Organizer || Gate::forUser($user)->denies('publish', $event);
    }

    /**
     * Rimette l'evento in attesa se la regola lo chiede, e dice se l'ha fatto.
     * Per chi ha già salvato il contenuto in un altro modo (un file nella
     * raccolta media), non per i moduli che scrivono lo stato insieme ai campi.
     */
    public function applyAfterNewContent(Event $event, Venue|Organizer $subject, User $user): bool
    {
        if (! $this->sendsToReview($event, $subject, $user)) {
            return false;
        }

        $event->status = EventStatus::Pending;
        $event->save();

        return true;
    }
}
