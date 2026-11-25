<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialFormat;
use App\Exceptions\SocialStudioException;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Disegna e salva un pacchetto di grafiche, per il pannello e per l'app.
 *
 * **Il disco.** Ogni immagine pesa circa 135 KB e un pacchetto arriva a cento
 * immagini, su un hosting con 10 GB di quota. Per un locale valgono quindi
 * due tetti, qui perché pannello e API passano entrambi da questo punto: al
 * massimo `DAILY_LIMIT_PER_VENUE` pacchetti nelle ultime 24 ore, e dopo ogni
 * pacchetto nuovo si tolgono subito quelli oltre i dodici più recenti
 * (`SocialBatch::pruneOwner`, la stessa regola della pulizia notturna).
 *
 * Il tetto si conta con `RateLimiter` e non sulle righe di `social_batches`:
 * con la pulizia immediata un locale non ha mai più di dodici righe, e
 * contarle non fermerebbe nessuno.
 *
 * Gli errori previsti (un testo che non sta, lo spazio su disco, il tetto)
 * sono `SocialStudioException`; ogni altra eccezione va al gestore degli
 * errori.
 */
final class SocialStudio
{
    /** Quanti pacchetti può preparare un locale nelle ultime 24 ore. */
    public const DAILY_LIMIT_PER_VENUE = 30;

    /** @param Collection<int, EventOccurrence> $dates
     * @param  array<string, mixed>  $options
     *
     * @throws SocialStudioException
     */
    public function generate(City $city, string $date, Collection $dates, SocialFormat $format, array $options, ?User $user, ?int $venueId = null): SocialBatch
    {
        if ($dates->isEmpty()) {
            throw SocialStudioException::chooseEvents();
        }
        foreach ($dates as $occurrence) {
            if ($venueId !== null && $occurrence->event->venue_id !== $venueId) {
                abort(403);
            }
            if ($user !== null) {
                abort_unless($user->can('update', $occurrence->event), 403);
            }
        }
        if ($venueId !== null && RateLimiter::tooManyAttempts(self::dailyKey($venueId), self::DAILY_LIMIT_PER_VENUE)) {
            throw SocialStudioException::dailyLimit(self::DAILY_LIMIT_PER_VENUE);
        }
        $batch = SocialBatch::create(['city_id' => $city->id, 'date' => $date, 'format' => $format->value, 'options' => $options, 'items' => [], 'user_id' => $user?->id, 'venue_id' => $venueId]);
        $items = [];
        try {
            foreach ($dates as $index => $occurrence) {
                $filename = sprintf('%02d-%s.jpg', $index + 1, Str::slug(Str::limit($occurrence->event->title, 65, '')));
                $path = 'social/'.$batch->id.'/'.$filename;
                $bytes = app(SocialGraphic::class)->render($occurrence, $format, $options);
                if (! Storage::disk('local')->put($path, $bytes)) {
                    throw SocialStudioException::storageError();
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

        if ($venueId !== null) {
            // Conta solo i pacchetti riusciti: un titolo che non ci sta non consuma il tetto.
            RateLimiter::hit(self::dailyKey($venueId), 86400);
            // Una pulizia che non riesce non toglie il pacchetto appena pronto: ci riprova la notte.
            rescue(fn (): int => SocialBatch::pruneOwner($city->id, $venueId));
        }

        return $batch;
    }

    private static function dailyKey(int $venueId): string
    {
        return 'social-batches:venue:'.$venueId;
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
