<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Jobs\Social\PublishSocial;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;
use App\Models\SocialConnection;
use App\Models\SocialPublication;
use App\Queries\EventOccurrenceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SocialPublisher
{
    public const TOKENS = ['data', 'giorno', 'citta', 'eventi', 'numero', 'link', 'parte', 'parti'];

    /** @return list<string> */
    public function captions(SocialBatch $batch): array
    {
        if (isset($batch->options['captions'])) {
            return $batch->options['captions'];
        }
        $connection = SocialConnection::where('city_id', $batch->city_id)->first();
        $template = $connection?->caption ?: __('social.default_template');
        $chunks = array_chunk($batch->items, 10);
        $date = CarbonImmutable::parse($batch->date)->locale('it');
        $city = City::findOrFail($batch->city_id);
        $captions = [];
        foreach ($chunks as $part => $items) {
            $captions[] = strtr($template, [':data' => $date->translatedFormat('j F Y'), ':giorno' => $date->translatedFormat('l'), ':citta' => $city->name, ':eventi' => collect($items)->map(fn ($item, $i) => $this->eventLine($item, $i + 1))->implode("\n\n"), ':numero' => (string) count($items), ':link' => route('city.events.date', ['city' => $city->slug, 'date' => $date->format('Y-m-d')]), ':parti' => (string) count($chunks), ':parte' => (string) ($part + 1)]);
        }

        return $captions;
    }

    /** @param array<string, mixed> $item */
    private function eventLine(array $item, int $number): string
    {
        // Older prepared batches predate these snapshots. Read missing metadata without inventing an organizer.
        $event = isset($item['venue']) ? null : Event::find($item['event_id']);
        $fallbackVenue = $event !== null && $event->venue !== null ? $event->venue->name : '';
        $venue = $item['venue'] ?? $fallbackVenue;
        $organizer = $item['organizer'] ?? ($event !== null ? (string) $event->organizer_name : '');
        $meta = implode(' · ', array_filter([$item['time'] ?? '', $venue]));

        return $number.'. '.$item['title'].($meta !== '' ? "\n".$meta : '').($organizer !== '' && $organizer !== $venue ? "\n".__('social.organized_by', ['name' => $organizer]) : '');
    }

    public function enqueue(SocialBatch $batch, bool $automatic = false, ?CarbonImmutable $scheduledAt = null): void
    {
        if ($scheduledAt !== null && $scheduledAt->isPast()) {
            throw new RuntimeException('Scegli una data e un orario futuri.');
        }
        $connection = SocialConnection::where('city_id', $batch->city_id)->first();
        if (! $connection || ((! $connection->verified_at || (! $connection->facebook_enabled && ! $connection->instagram_enabled)) && (! $connection->telegram_enabled || ! $connection->telegram_verified_at)) || $batch->venue_id !== null) {
            throw new RuntimeException(__('social.not_connected'));
        }
        if ((($connection->facebook_enabled || $connection->instagram_enabled) && ! $connection->verified_at) || ($connection->telegram_enabled && ! $connection->telegram_verified_at)) {
            throw new RuntimeException('Verifica tutti i canali abilitati oppure disattiva quelli che non vuoi usare.');
        }
        if ($batch->format === 'story') {
            throw new RuntimeException(__('social.story_manual'));
        }
        $this->assertFresh($batch);
        $captions = $this->captions($batch);
        if (collect($captions)->contains(fn ($text) => mb_strlen($text) > 2200)) {
            throw new RuntimeException(__('social.caption_long'));
        }
        if ($connection->telegram_enabled && collect($captions)->contains(fn ($text) => mb_strlen($text) > 1024)) {
            throw new RuntimeException('Telegram ammette 1024 caratteri: accorcia il modello della didascalia prima di programmare.');
        }
        // Store precisely the captions shown at enqueue time; later settings edits cannot change a queued post.
        $options = $batch->options;
        $options['captions'] = $captions;
        $batch->update(['options' => $options, 'caption' => implode("\n\n", $captions)]);
        foreach (['facebook', 'instagram', 'telegram'] as $platform) {
            if (! $connection->{$platform.'_enabled'} || ($platform === 'telegram' ? ! $connection->telegram_verified_at : ! $connection->verified_at)) {
                continue;
            }
            foreach ($captions as $part => $caption) {
                $key = $connection->id.':'.$platform.':'.($automatic ? $batch->date->format('Y-m-d') : $batch->id).':'.$part;
                $publication = SocialPublication::firstOrCreate(['dedupe_key' => $key], ['social_batch_id' => $batch->id, 'social_connection_id' => $connection->id, 'platform' => $platform, 'part' => $part, 'status' => $scheduledAt === null ? 'queued' : 'scheduled', 'scheduled_at' => $scheduledAt, 'remote_ids' => ['page_id' => $connection->page_id, 'instagram_id' => $connection->instagram_id, 'telegram_chat_id' => $connection->telegram_chat_id]]);
                if ($publication->wasRecentlyCreated && $scheduledAt === null) {
                    PublishSocial::dispatch($publication->id)->afterCommit();
                }
            }
        }
    }

    public function assertFresh(SocialBatch $batch): void
    {
        foreach ($batch->items as $item) {
            $date = EventOccurrence::with('event')->whereKey($item['occurrence_id'])->first();
            if (! $date || $date->status !== OccurrenceStatus::Scheduled || $date->event->status !== EventStatus::Published || $date->getRawOriginal('updated_at') !== $item['source_updated_at'] || $date->event->getRawOriginal('updated_at') !== $item['event_updated_at']) {
                throw new RuntimeException(__('social.stale'));
            }
            if (isset($item['source_hash']) && ! hash_equals($item['source_hash'], SocialSource::fingerprint($date))) {
                throw new RuntimeException(__('social.stale'));
            }
            if (! EventOccurrenceQuery::for($date->event->city)->forOccurrence($date)->get()->isNotEmpty()) {
                throw new RuntimeException(__('social.stale'));
            }
        }
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function request(SocialConnection $connection, string $method, string $path, array $data = []): array
    {
        // Endpoint is never user supplied. Credentials stay out of URL query strings and application logs.
        if (! preg_match('/^v\d+\.\d+$/', $connection->graph_version)) {
            throw new RuntimeException(__('social.verify_failed'));
        }
        $response = Http::withToken($connection->access_token)->connectTimeout(10)->timeout(30)->{$method}('https://graph.facebook.com/'.$connection->graph_version.'/'.$path, $data);
        if (! $response->successful()) {
            throw new RuntimeException(__('social.verify_failed').' (Meta '.(int) $response->json('error.code', $response->status()).')');
        }

        return $response->json() ?: [];
    }

    public function verify(SocialConnection $connection): void
    {
        $connection->update(['verified_at' => null]);
        if ($connection->facebook_enabled) {
            $page = $this->request($connection, 'get', $connection->page_id, ['fields' => 'id,name']);
            if ((string) ($page['id'] ?? '') !== $connection->page_id) {
                throw new RuntimeException(__('social.verify_failed'));
            }
        }
        if ($connection->instagram_enabled) {
            $page = $this->request($connection, 'get', $connection->page_id, ['fields' => 'instagram_business_account']);
            if ((string) data_get($page, 'instagram_business_account.id') !== $connection->instagram_id) {
                throw new RuntimeException(__('social.verify_failed'));
            }
            $this->request($connection, 'get', $connection->instagram_id, ['fields' => 'id,username']);
        }
        $connection->update(['verified_at' => now()]);
    }
}
