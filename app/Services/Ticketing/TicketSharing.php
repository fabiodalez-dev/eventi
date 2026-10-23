<?php

declare(strict_types=1);

namespace App\Services\Ticketing;

use App\Enums\AdmissionStatus;
use App\Models\AdmissionTicket;
use App\Models\User;
use App\Notifications\TicketShared;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Un biglietto per ciascun partecipante.
 *
 * **Il link, non l'indirizzo.** Ogni biglietto valido ha un indirizzo firmato
 * che mostra solo quel QR; chi ha prenotato lo inoltra lui, con il canale che
 * usa già con quella persona. Il server non scrive a nessuno che non l'abbia
 * chiesto, e non conserva recapiti di terzi.
 *
 * **L'email è facoltativa e dichiarata.** Chi vuole può farla partire da qui,
 * spuntando che la persona sa e gli ha dato l'indirizzo. L'indirizzo serve
 * solo all'invio: non si salva, e nel registro resta soltanto che quel
 * biglietto è stato mandato. Una email per biglietto al giorno.
 */
final class TicketSharing
{
    public const EXPIRES_AFTER_HOURS = 6;

    public function url(AdmissionTicket $ticket): ?string
    {
        if ($ticket->displayStatus() !== AdmissionStatus::Valid) {
            return null;
        }
        $date = $ticket->booking?->occurrence;
        if ($date === null) {
            return null;
        }
        $end = CarbonImmutable::instance($date->effective_ends_at ?? $date->ends_at ?? $date->starts_at->copy()->addHours(6));

        return URL::temporarySignedRoute('tickets.shared', $end->addHours(self::EXPIRES_AFTER_HOURS), ['ticket' => $ticket->getKey()]);
    }

    public function email(AdmissionTicket $ticket, User $sender, string $email): void
    {
        abort_unless((int) $ticket->booking?->user_id === (int) $sender->getKey(), 403);
        $url = $this->url($ticket);
        if ($url === null) {
            throw ValidationException::withMessages(['email' => __('ticketing.share.not_valid')]);
        }
        $key = 'ticket-shared:'.$ticket->getKey();
        if (! Cache::add($key, true, now()->addDay())) {
            throw ValidationException::withMessages(['email' => __('ticketing.share.already_sent')]);
        }
        Notification::route('mail', $email)->notify(new TicketShared((int) $ticket->getKey(), $sender->name ?: __('ticketing.share.someone'), $url));
        activity('ticketing')->causedBy($sender)->performedOn($ticket)->withProperties(['mailed' => true])->log('ticket_shared');
    }
}
