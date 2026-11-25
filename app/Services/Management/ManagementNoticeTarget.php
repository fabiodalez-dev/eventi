<?php

declare(strict_types=1);

namespace App\Services\Management;

use App\Enums\NotificationType;
use App\Models\Event;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;

/**
 * Dove porta, dentro la gestione, un avviso a chi gestisce (P12).
 *
 * Gli avvisi ai gestori (`NotificationType::isForVenueStaff`) portano un
 * `url` pensato per l'email: la scheda pubblica dell'evento, la pagina
 * iniziale del pannello o la modifica nel pannello dell'organizzazione.
 * L'app non deve indovinare da quell'indirizzo quale locale o quale
 * organizzazione aprire: questa classe glielo dice con
 * `management{subject_type, subject_id, area, id}`, scritto accanto all'`url`
 * che resta com'è (l'email non cambia).
 *
 * Il soggetto è quello da cui la persona vede il contenuto nei pannelli, con
 * la stessa barriera (`User::canAccessTenant`) e le stesse regole di
 * `ManagedContent`: prima il locale dell'evento, se ne è membro attivo; poi
 * l'organizzazione principale, poi una co-organizzatrice (per i commenti
 * solo la principale, come la pagina «Commenti agli eventi»). Se la persona
 * non gestisce più niente di quell'evento il campo manca, e l'app apre
 * l'`url` come prima.
 *
 * Le aree: `event` (la scheda dell'evento, con lo stato e il motivo del
 * rifiuto), `comments` (i commenti di quell'evento da moderare), `home` (la
 * pagina del locale).
 */
final class ManagementNoticeTarget
{
    /**
     * @return array{subject_type: string, subject_id: int, area: string, id: int|null}|null
     */
    public function for(User $user, NotificationType $type, ?int $eventId = null, ?int $venueId = null): ?array
    {
        return match ($type) {
            NotificationType::EventPublished, NotificationType::EventRejected => $this->event($user, $eventId, 'event', coHosts: true),
            NotificationType::EventNewComment => $this->event($user, $eventId, 'comments', coHosts: false),
            NotificationType::VenueInactive => $this->venue($user, $venueId),
            default => null,
        };
    }

    /**
     * @return array{subject_type: string, subject_id: int, area: string, id: int|null}|null
     */
    private function event(User $user, ?int $eventId, string $area, bool $coHosts): ?array
    {
        if ($eventId === null) {
            return null;
        }
        $event = Event::query()->with(['venue', 'organizer'])->find($eventId);
        if (! $event instanceof Event) {
            return null;
        }

        $candidates = array_values(array_filter([$event->venue, $event->organizer]));
        if ($coHosts) {
            $candidates = [...$candidates, ...$event->organizers()->get()->all()];
        }

        foreach ($candidates as $subject) {
            if ($user->canAccessTenant($subject) && ManagedContent::for($subject)->events()->whereKey($event->getKey())->exists()) {
                return $this->target($subject, $area, (int) $event->getKey());
            }
        }

        return null;
    }

    /**
     * @return array{subject_type: string, subject_id: int, area: string, id: int|null}|null
     */
    private function venue(User $user, ?int $venueId): ?array
    {
        $venue = $venueId === null ? null : Venue::query()->find($venueId);

        return $venue instanceof Venue && $user->canAccessTenant($venue) ? $this->target($venue, 'home', null) : null;
    }

    /**
     * @return array{subject_type: string, subject_id: int, area: string, id: int|null}
     */
    private function target(Venue|Organizer $subject, string $area, ?int $id): array
    {
        return [
            'subject_type' => $subject instanceof Venue ? 'venue' : 'organizer',
            'subject_id' => (int) $subject->getKey(),
            'area' => $area,
            'id' => $id,
        ];
    }
}
