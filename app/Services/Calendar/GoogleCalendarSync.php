<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\DTOs\EventFilters;
use App\Enums\GoogleCalendarError;
use App\Enums\OccurrenceStatus;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Services\Feeds\EventFeed;
use Closure;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class GoogleCalendarSync
{
    public function __construct(private GoogleCalendarClient $client, private EventFeed $feed) {}

    public function run(int $userId): void
    {
        Cache::lock('google-calendar-'.$userId, 600)->block(3, function () use ($userId): void {
            $connection = GoogleCalendarConnection::where('user_id', $userId)->where('enabled', true)->first();
            if ($connection === null || ! User::whereKey($userId)->exists()) {
                return;
            }
            try {
                $this->synchronize($connection);
            } catch (\Throwable $error) {
                $connection->refresh();
                if ($connection->enabled) {
                    $connection->update(['error_code' => GoogleCalendarError::Sync]);
                }
                // Do not persist responses, authorization codes or tokens in logs.
                throw new RuntimeException('Google Calendar sync failed');
            }
        });
    }

    private function synchronize(GoogleCalendarConnection $connection): void
    {
        $city = City::findOrFail($connection->city_id);
        if ($connection->calendar_id === null) {
            $created = $this->client->request($connection, 'POST', 'calendars', ['summary' => 'inCittà · '.$city->name, 'timeZone' => $city->timezone]);
            if (! $created->successful() || ! filled($created->json('id'))) {
                throw new RuntimeException('Calendar creation failed');
            }
            // Persist immediately: retries must not create another calendar.
            $connection->update(['calendar_id' => $created->json('id')]);
        }
        $path = 'calendars/'.rawurlencode($connection->calendar_id).'/events';
        $existing = [];
        $page = null;
        do {
            $response = $this->client->request($connection, 'GET', $path, array_filter([
                'privateExtendedProperty' => 'incitta_connection='.$connection->id, 'maxResults' => 2500, 'pageToken' => $page,
            ]));
            if ($response->status() === 404 && $page === null) {
                // The user removed the dedicated calendar in Google. A retry
                // creates a new one, never switches to the primary calendar.
                $connection->update(['calendar_id' => null, 'synced_at' => null]);
            }
            if (! $response->successful()) {
                throw new RuntimeException('Calendar unavailable');
            }
            foreach ($response->json('items', []) as $event) {
                if (($event['extendedProperties']['private']['incitta_connection'] ?? null) === (string) $connection->id) {
                    $existing[$event['id']] = $event['extendedProperties']['private']['incitta_hash'] ?? '';
                }
            }
            $page = $response->json('nextPageToken');
        } while ($page !== null);

        $selection = $connection->selection;
        $filters = EventFilters::fromArray([
            'category' => implode(',', $selection['categories'] ?? []),
            'venue' => $selection['venue'] ?? null, 'price' => ! empty($selection['free']) ? 'free' : null,
        ]);
        $items = $this->feed->occurrences($city, $filters, days: (int) ($selection['days'] ?? 30))
            ->reject(fn (EventOccurrence $item) => $item->status === OccurrenceStatus::Cancelled);
        foreach ($items as $item) {
            $id = hash('sha256', 'incitta:'.$connection->id.':'.$item->id);
            $body = $this->event($item, $connection, $city);
            $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
            $body['extendedProperties']['private']['incitta_hash'] = $hash;
            if (($existing[$id] ?? null) === $hash) {
                unset($existing[$id]);

                continue;
            }
            $response = isset($existing[$id])
                ? $this->client->request($connection, 'PUT', $path.'/'.$id, $body)
                : $this->client->request($connection, 'POST', $path, ['id' => $id, ...$body]);
            if ($response->status() === 409) {
                $response = $this->client->request($connection, 'PUT', $path.'/'.$id, $body);
            }
            if (! $response->successful()) {
                throw new RuntimeException('Event update failed');
            }
            unset($existing[$id]);
        }
        // Only records carrying our connection marker are eligible for deletion.
        foreach (array_keys($existing) as $id) {
            $response = $this->client->request($connection, 'DELETE', $path.'/'.rawurlencode($id));
            if (! $response->successful() && ! in_array($response->status(), [404, 410], true)) {
                throw new RuntimeException('Event removal failed');
            }
        }
        $connection->update(['synced_at' => now(), 'error_code' => null, 'event_count' => $items->count()]);
    }

    /** @return array<string, mixed> */
    public function event(EventOccurrence $item, GoogleCalendarConnection $connection, City $city): array
    {
        $venue = $item->effectiveVenue();
        $start = $item->starts_at->copy()->setTimezone($city->timezone);
        $end = $item->effective_ends_at->copy()->setTimezone($city->timezone);

        return [
            'status' => 'confirmed',
            'summary' => $item->event->title,
            'description' => trim(strip_tags((string) $item->event->short_description))."\n\n".route('events.show', $item->event),
            'location' => implode(', ', array_filter([$venue?->name, $venue?->address, $venue?->municipality])),
            'start' => $item->is_all_day ? ['date' => $start->format('Y-m-d')] : ['dateTime' => $start->toRfc3339String(), 'timeZone' => $city->timezone],
            'end' => $item->is_all_day ? ['date' => $end->subSecond()->addDay()->format('Y-m-d')] : ['dateTime' => $end->toRfc3339String(), 'timeZone' => $city->timezone],
            'extendedProperties' => ['private' => ['incitta_connection' => (string) $connection->id, 'occurrence_id' => (string) $item->id]],
            'reminders' => ['useDefault' => false],
        ];
    }

    public function disconnect(int $userId): void
    {
        Cache::lock('google-calendar-'.$userId, 600)->block(3, function () use ($userId): void {
            $connection = GoogleCalendarConnection::where('user_id', $userId)->first();
            if ($connection === null) {
                return;
            }
            $connection->update(['enabled' => false]);
            // Keep the user's Google calendar and stop changing it. Removal is explicit in Google.
            if (filled($connection->refresh_token)) {
                $this->client->revoke($connection->refresh_token);
            }
            $connection->update(['refresh_token' => null, 'access_token' => null, 'error_code' => null]);
        });
    }

    public function forget(int $userId, ?Closure $eraseAccount = null): void
    {
        Cache::lock('google-calendar-'.$userId, 600)->block(3, function () use ($userId, $eraseAccount): void {
            $connection = GoogleCalendarConnection::where('user_id', $userId)->first();
            try {
                if ($connection !== null && filled($connection->refresh_token)) {
                    $this->client->revoke($connection->refresh_token);
                }
            } catch (\Throwable) {
                // Account erasure must not depend on Google's availability.
            }
            $connection?->delete();
            // Keep the same lock until account erasure finishes: an OAuth
            // callback must not recreate credentials between these two steps.
            $eraseAccount?->__invoke();
        });
    }
}
