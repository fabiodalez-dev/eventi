<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Enums\EventStatus;
use App\Enums\NotificationType;
use App\Models\EditionAlert;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Services\Notifications\NotificationScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * «Avvisami alla prossima edizione».
 *
 * Si chiede su un evento che non ha più date in programma. La prossima
 * edizione è la prima data futura e pubblicata di uno di questi:
 *
 * - lo stesso evento, che torna con una data nuova;
 * - un evento pubblicato dopo la richiesta, dello stesso organizzatore (o
 *   dello stesso locale, se l'evento non aveva un organizzatore) e con lo
 *   stesso titolo una volta tolti anni, numeri d'edizione e punteggiatura:
 *   «Sagra del Bisi 2026 – 12ª edizione» e «Sagra del Bisi 2027» sono la
 *   stessa festa.
 *
 * Il confronto è prudente di proposito: meglio un avviso mancato che uno
 * sbagliato. `editions:notify` controlla ogni ora; l'avviso parte una volta e
 * la richiesta si chiude. Chi vuole tutte le date di una rassegna la segue.
 */
final class EditionAlerts
{
    public function __construct(private readonly NotificationScheduler $scheduler) {}

    public function offered(Event $event): bool
    {
        if (! in_array($event->status, [EventStatus::Published, EventStatus::Archived], true) || $event->city === null) {
            return false;
        }

        return EventOccurrenceQuery::archiveFor($event->city)->forEvent($event)->upcoming()->availableForDiscovery()->count() === 0;
    }

    public function subscribe(User $user, Event $event): void
    {
        if (! $this->offered($event)) {
            throw ValidationException::withMessages(['event' => __('events.edition_alert.not_offered')]);
        }
        EditionAlert::query()->firstOrCreate(['user_id' => $user->getKey(), 'event_id' => $event->getKey()]);
    }

    public function unsubscribe(User $user, int $eventId): void
    {
        EditionAlert::query()->where('user_id', $user->getKey())->where('event_id', $eventId)->delete();
    }

    public function active(User $user, Event $event): bool
    {
        return EditionAlert::query()->where('user_id', $user->getKey())->where('event_id', $event->getKey())->exists();
    }

    /** @return list<int> */
    public function ids(User $user): array
    {
        return EditionAlert::query()->where('user_id', $user->getKey())->orderBy('event_id')
            ->pluck('event_id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /** Il titolo senza anni, numeri d'edizione, accenti e punteggiatura. */
    public static function titleKey(string $title): string
    {
        $key = Str::lower(Str::ascii($title));
        $key = (string) preg_replace('/\b(19|20)\d{2}\b/', ' ', $key);
        $key = (string) preg_replace('/\b\d+\s*(a|o|st|nd|rd|th)?\b/', ' ', $key);
        $key = (string) preg_replace('/\b[ivxlc]+\b(?=\s*(edizione|edition)\b)/', ' ', $key);
        $key = (string) preg_replace('/\b(edizione|edition|ed)\b/', ' ', $key);
        $key = (string) preg_replace('/[^a-z0-9]+/', ' ', $key);

        return trim((string) preg_replace('/\s+/', ' ', $key));
    }

    /** La prima data della prossima edizione per questa richiesta, se c'è. */
    public function nextFor(EditionAlert $alert): ?EventOccurrence
    {
        $source = $alert->event;
        if ($source === null || $source->city === null) {
            return null;
        }
        $key = self::titleKey((string) $source->title);
        $since = CarbonImmutable::instance($alert->created_at ?? now());
        $siblings = $key === '' || ($source->organizer_id === null && $source->venue_id === null) ? collect() : Event::query()
            ->where('city_id', $source->city_id)->where('status', EventStatus::Published)->whereKeyNot($source->getKey())
            ->where(fn ($query) => $query->where('published_at', '>=', $since)->orWhere('created_at', '>=', $since))
            ->when($source->organizer_id !== null, fn ($query) => $query->where('organizer_id', $source->organizer_id), fn ($query) => $query->where('venue_id', $source->venue_id))
            ->limit(200)->get(['id', 'title'])
            ->filter(static fn (Event $event): bool => self::titleKey((string) $event->title) === $key)->modelKeys();
        $ids = [(int) $source->getKey(), ...array_map('intval', $siblings)];

        return EventOccurrenceQuery::competitionFor($source->city)->forEvents($ids)->upcoming()->availableForDiscovery()->take(20)
            ->first(static fn (EventOccurrence $date): bool => (int) $date->event_id !== (int) $source->getKey() || CarbonImmutable::parse((string) $date->getAttribute('created_at'))->greaterThanOrEqualTo($since));
    }

    /** Avvisa chi aspettava una prossima edizione ora in programma; restituisce quanti avvisi sono partiti. */
    public function announce(): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;
        EditionAlert::query()->with('event.city')->orderBy('id')->chunkById(200, function ($alerts) use ($now, &$sent): void {
            foreach ($alerts as $alert) {
                if ($alert->event === null || $alert->event->trashed()) {
                    $alert->delete();

                    continue;
                }
                $next = $this->nextFor($alert);
                if ($next === null) {
                    continue;
                }
                $sent += (int) $this->scheduler->queue((int) $alert->user_id, NotificationType::NextEdition,
                    sprintf('next_edition:user_%d:event_%d:occ_%d', $alert->user_id, $alert->event_id, $next->getKey()), $now, $next);
                $alert->delete();
            }
        });

        return $sent;
    }
}
