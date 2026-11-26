<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\OccurrenceStatus;
use App\Enums\SocialFormat;
use App\Http\Resources\V1\PosterResource;
use App\Models\EventOccurrence;
use App\Models\SocialBatch;

/**
 * Le forme JSON dello studio delle grafiche social, uguali per l'app di
 * gestione (P10, studio del locale) e per la redazione (R9): una serata come
 * card, un pacchetto in breve, un pacchetto intero. Stavano dentro il
 * controller di P10; la redazione ne ha bisogno identiche, e una forma
 * scritta due volte prima o poi diverge.
 */
final class SocialStudioPayload
{
    /**
     * Una card: la serata con locandina, quando, stato, se è dimostrativa e
     * se si può preparare (solo le serate in programma).
     *
     * @return array<string, mixed>
     */
    public static function card(EventOccurrence $date, string $timezone): array
    {
        $event = $date->event;

        return [
            'id' => (int) $date->getKey(),
            'event_id' => (int) $date->event_id,
            'title' => $event->title,
            'starts_at' => $date->starts_at->copy()->utc()->toIso8601String(),
            'timezone' => $timezone,
            'is_all_day' => (bool) $date->is_all_day,
            'business_date' => $date->business_date->format('Y-m-d'),
            'status' => ['value' => $date->status->value, 'label' => $date->status->label()],
            'selectable' => $date->status === OccurrenceStatus::Scheduled,
            'demo' => $event->isDemo(),
            'poster' => PosterResource::toArray($event),
            'venue' => $date->effectiveVenue()?->name,
        ];
    }

    /**
     * Un pacchetto in breve, per l'elenco dei recenti.
     *
     * @return array<string, mixed>
     */
    public static function summary(SocialBatch $batch): array
    {
        $format = SocialFormat::tryFrom($batch->format) ?? SocialFormat::Portrait;

        return [
            'id' => (string) $batch->getKey(),
            'date' => $batch->date->format('Y-m-d'),
            'format' => ['value' => $format->value, 'label' => (string) __('social.'.$format->value), 'width' => 1080, 'height' => $format->height()],
            'count' => count($batch->items),
            'created_at' => $batch->created_at?->copy()->utc()->toIso8601String(),
        ];
    }

    /**
     * Un pacchetto intero: le immagini nell'ordine dello ZIP e la didascalia
     * del post, come la sezione «Grafiche pronte» della pagina.
     *
     * @return array<string, mixed>
     */
    public static function batch(SocialBatch $batch, string $timezone): array
    {
        return [
            ...self::summary($batch),
            'timezone' => $timezone,
            'caption' => (string) $batch->caption,
            'items' => collect($batch->items)->values()->map(fn (array $item, int $index): array => [
                'index' => $index,
                'title' => (string) $item['title'],
                'filename' => (string) $item['filename'],
                'occurrence_id' => (int) $item['occurrence_id'],
                'event_id' => (int) $item['event_id'],
                'time' => isset($item['time']) ? (string) $item['time'] : null,
                'venue' => isset($item['venue']) ? (string) $item['venue'] : null,
                'url' => SocialStudio::imageUrl($batch, $index),
            ])->all(),
        ];
    }
}
