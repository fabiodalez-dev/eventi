<?php

declare(strict_types=1);

namespace App\Services\Carpool;

use App\Models\Booking;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class UnifiedNotifications
{
    public function archived(DatabaseNotification $notification): void
    {
        if (! Schema::hasTable('community_notification_receipts') || ! in_array($notification->notifiable_type, ['user', User::class], true)) {
            return;
        }
        DB::table('community_notification_receipts')->insertOrIgnore(['user_id' => $notification->notifiable_id, 'notification_id' => $notification->id]);
        if (($notification->data['category'] ?? null) === 'social' && isset($notification->data['path'])) {
            DB::table('community_delivery_outbox')->insertOrIgnore(['user_id' => $notification->notifiable_id,
                'notification_id' => $notification->id, 'dedupe_key' => 'social:'.$notification->id,
                'payload' => json_encode($notification->data, JSON_THROW_ON_ERROR), 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function watermark(User $user): int
    {
        return (int) DB::table('community_notification_receipts')->where('user_id', $user->id)->max('id');
    }

    public function readAll(User $user, ?int $through = null): int
    {
        $through = min($through ?? $this->watermark($user), $this->watermark($user));

        return $user->unreadNotifications()->where(fn ($q) => $q->whereNull('data->category')->orWhere('data->category', '!=', 'chat'))
            ->whereIn('id', DB::table('community_notification_receipts')->where('user_id', $user->id)->where('id', '<=', $through)->select('notification_id'))
            ->update(['read_at' => now()]);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function destination(array $data): array
    {
        if (in_array($data['category'] ?? null, ['carpool', 'chat', 'social'], true) && isset($data['entity'], $data['entity_id'])) {
            $data['url'] = app(CommunityNotices::class)->destination($data['entity'], (int) $data['entity_id']);
        }
        if (($data['category'] ?? null) === 'social' && isset($data['path']) && preg_match('~^/(persone/[a-z0-9_]+|bacheca/post/[0-9]+|persone-che-mi-seguono)$~', $data['path'])) {
            $data['url'] = url($data['path']);
        }

        return $data;
    }

    /**
     * Come si mostra un avviso nella pagina e nella tendina della campanella.
     *
     * Gli avvisi non hanno tutti la stessa forma: quelli della community e dei
     * passaggi portano `title` e `body`, quelli della biglietteria solo
     * `message` («Prenotazione confermata»). Prima la pagina leggeva soltanto
     * `title`, e ogni avviso dei biglietti compariva come «I tuoi avvisi», cioè
     * col nome della pagina invece che con quello che era successo.
     *
     * L'evento, quando l'avviso ne riguarda uno (`event_id`, `occurrence_id` o
     * `booking_id`), serve per la locandina; si carica una volta per tutta la
     * pagina, non una per avviso.
     *
     * @param  iterable<DatabaseNotification>  $notifications
     * @return list<array{id: string, title: string, body: ?string, url: ?string, unread: bool, created_at: CarbonInterface, event: ?Event}>
     */
    public function presentations(iterable $notifications): array
    {
        $notifications = collect($notifications)->values();
        $events = $this->eventsFor($notifications);

        return $notifications->map(fn (DatabaseNotification $notification): array => $this->presentation($notification, $events))->all();
    }

    /**
     * @param  array{events: Collection<int, Event>, occurrences: Collection<int, EventOccurrence>, bookings: Collection<int, Booking>}|null  $events
     * @return array{id: string, title: string, body: ?string, url: ?string, unread: bool, created_at: CarbonInterface, event: ?Event}
     */
    public function presentation(DatabaseNotification $notification, ?array $events = null): array
    {
        $data = $notification->data;
        $createdAt = $notification->getAttribute($notification->getCreatedAtColumn());
        $title = $this->text($data['title'] ?? null);
        $message = $this->text($data['message'] ?? null);
        $body = $this->text($data['body'] ?? null);

        if ($title === null) {
            // Senza titolo, il messaggio è il titolo: è la frase che dice cosa è successo.
            $title = $message ?? __('community.inbox_untitled');
            $message = null;
        }
        $body ??= $message !== $title ? $message : null;

        $events ??= $this->eventsFor(collect([$notification]));

        return [
            'id' => (string) $notification->id,
            'title' => $title,
            'body' => $body,
            'url' => $this->localUrl($this->destination($data)['url'] ?? null),
            'unread' => $notification->read_at === null,
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt : now(),
            'event' => $this->eventOf($data, $events),
        ];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Solo indirizzi di questo sito. Un avviso registrato quando il sito
     * rispondeva a un altro indirizzo (un dominio precedente, la porta di
     * sviluppo) punta comunque a una pagina di qui: si tiene il percorso e si
     * ricostruisce l'indirizzo, invece di perdere il collegamento.
     */
    private function localUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }
        if (str_starts_with($url, url('/').'/') || $url === url('/')) {
            return $url;
        }
        $parts = parse_url($url);
        $path = is_array($parts) ? ($parts['path'] ?? null) : null;
        if (! is_string($path) || ! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        return url($path).(isset($parts['query']) ? '?'.$parts['query'] : '').(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return array{events: Collection<int, Event>, occurrences: Collection<int, EventOccurrence>, bookings: Collection<int, Booking>}
     */
    private function eventsFor(Collection $notifications): array
    {
        $ids = fn (string $key): array => $notifications->map(fn (DatabaseNotification $n) => $n->data[$key] ?? null)
            ->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->unique()->values()->all();

        $eventIds = $ids('event_id');
        $occurrenceIds = $ids('occurrence_id');
        $bookingIds = $ids('booking_id');

        return [
            'events' => $eventIds === [] ? collect() : Event::query()->with('media')->whereKey($eventIds)->get()->keyBy('id'),
            'occurrences' => $occurrenceIds === [] ? collect() : EventOccurrence::query()->with('event.media')->whereKey($occurrenceIds)->get()->keyBy('id'),
            'bookings' => $bookingIds === [] ? collect() : Booking::query()->with('occurrence.event.media')->whereKey($bookingIds)->get()->keyBy('id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{events: Collection<int, Event>, occurrences: Collection<int, EventOccurrence>, bookings: Collection<int, Booking>}  $events
     */
    private function eventOf(array $data, array $events): ?Event
    {
        $id = fn (string $key): ?int => is_numeric($data[$key] ?? null) ? (int) $data[$key] : null;

        return ($id('event_id') !== null ? $events['events']->get($id('event_id')) : null)
            ?? ($id('occurrence_id') !== null ? $events['occurrences']->get($id('occurrence_id'))?->event : null)
            ?? ($id('booking_id') !== null ? $events['bookings']->get($id('booking_id'))?->occurrence?->event : null);
    }
}
