<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialFormat;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SocialStudio
{
    /** @param Collection<int, EventOccurrence> $dates
     * @param  array<string, mixed>  $options
     */
    public function generate(City $city, string $date, Collection $dates, SocialFormat $format, array $options, ?User $user, ?int $venueId = null): SocialBatch
    {
        if ($dates->isEmpty()) {
            throw new RuntimeException(__('social.choose_events'));
        }
        foreach ($dates as $occurrence) {
            if ($venueId !== null && $occurrence->event->venue_id !== $venueId) {
                abort(403);
            }
            if ($user !== null) {
                abort_unless($user->can('update', $occurrence->event), 403);
            }
        }
        $batch = SocialBatch::create(['city_id' => $city->id, 'date' => $date, 'format' => $format->value, 'options' => $options, 'items' => [], 'user_id' => $user?->id, 'venue_id' => $venueId]);
        $items = [];
        try {
            foreach ($dates as $index => $occurrence) {
                $filename = sprintf('%02d-%s.jpg', $index + 1, Str::slug(Str::limit($occurrence->event->title, 65, '')));
                $path = 'social/'.$batch->id.'/'.$filename;
                $bytes = app(SocialGraphic::class)->render($occurrence, $format, $options);
                if (! Storage::disk('local')->put($path, $bytes)) {
                    throw new RuntimeException(__('social.storage_error'));
                }
                $items[] = ['occurrence_id' => $occurrence->id, 'event_id' => $occurrence->event_id, 'title' => $occurrence->event->title, 'path' => $path, 'filename' => $filename, 'source_updated_at' => $occurrence->getRawOriginal('updated_at'), 'event_updated_at' => $occurrence->event->getRawOriginal('updated_at')];
                $venue = $occurrence->event->venue;
                $items[array_key_last($items)]['venue'] = $venue !== null ? $venue->name : (string) data_get($occurrence->event->custom_location, 'name', '');
                $items[array_key_last($items)]['organizer'] = (string) $occurrence->event->organizer_name;
                $items[array_key_last($items)]['time'] = $occurrence->is_all_day ? __('social.all_day') : $occurrence->starts_at->timezone($city->timezone)->format('H:i');
                $items[array_key_last($items)]['source_hash'] = SocialSource::fingerprint($occurrence);
            }
            $batch->update(['items' => $items, 'caption' => __('social.caption', ['city' => $city->name, 'date' => $date])."\n\n".collect($items)->map(fn ($item, $i) => ($i + 1).'. '.$item['title'])->implode("\n")."\n\n".config('app.url')]);
        } catch (Throwable $e) {
            Storage::disk('local')->deleteDirectory('social/'.$batch->id);
            $batch->delete();
            throw $e;
        }

        return $batch;
    }

    public static function imageUrl(SocialBatch $batch, int $index): string
    {
        return URL::temporarySignedRoute('social.image', now()->addDays(7), ['batch' => $batch->id, 'index' => $index]);
    }

    public static function previewUrl(EventOccurrence $date): string
    {
        return route('social.preview', ['occurrence' => $date->id,
            'v' => SocialGraphic::version().'-'.SocialSource::fingerprint($date)]);
    }
}
