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
        return Event::query()->readable()->with(['venue', 'city', 'media'])->orderByDesc('updated_at')->limit(200)->get()
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

                return ['title' => $event->title, 'url' => route('events.show', $event), 'issues' => $issues];
            })->all();
    }
}
