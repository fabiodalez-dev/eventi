<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Enums\PriceType;
use App\Models\Event;
use App\Support\Poster;

final class SeoAudit
{
    /** @return list<array{title: string, url: string, issues: list<string>}> */
    public function report(): array
    {
        return Event::query()->readable()->with(['venue', 'city', 'media', 'organizer'])->orderByDesc('updated_at')->limit(200)->get()
            ->map(function (Event $event): array {
                $issues = [];
                if (! app(EditorialContent::class)->indexable($event)) {
                    $issues[] = __('seo.audit.excluded');
                }
                if (blank($event->description) && blank($event->short_description)) {
                    $issues[] = __('seo.audit.description');
                }
                if (Poster::url($event) === null) {
                    $issues[] = __('seo.audit.image');
                }
                if ($event->venue === null && empty($event->custom_location)) {
                    $issues[] = __('seo.audit.location');
                }
                if ($event->price_type === PriceType::Unknown) {
                    $issues[] = __('seo.audit.price');
                }

                if (app(StructuredData::class)->organizer($event) === []) {
                    $issues[] = 'Organizzatore non disponibile: indicare un organizzatore oppure il locale dell’evento.';
                }
                $text = strip_tags(($event->short_description ?? '').' '.($event->description ?? ''));
                if ($event->price_min > 0 && preg_match('/\b(ingresso libero|ingresso gratuito|gratis)\b/iu', $text)) {
                    $issues[] = 'Possibile incoerenza: il testo indica gratuità, ma il prezzo è maggiore di zero. Verificare prima di pubblicare.';
                }

                return ['title' => $event->title, 'url' => route('events.show', $event), 'issues' => $issues];
            })->all();
    }
}
