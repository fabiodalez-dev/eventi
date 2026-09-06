<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\DTOs\PageMeta;
use App\Enums\SeoIndexing;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\Tag;
use App\Support\CurrentCity;
use App\Support\SafeUrl;
use Illuminate\Database\Eloquent\Model;

final class EditorialContent
{
    /** @return array<string, mixed> */
    public function details(Model $model): array
    {
        $own = is_array($model->getAttribute('content_details')) ? $model->getAttribute('content_details') : [];
        if ($model instanceof Category || $model instanceof Tag) {
            $city = app(CurrentCity::class)->get();
            foreach ($own['city_introductions'] ?? [] as $introduction) {
                if ((int) ($introduction['city_id'] ?? 0) === (int) $city?->id) {
                    $own['introduction'] = $introduction['text'];
                }
            }
            unset($own['city_introductions']);
        }
        if ($model instanceof Event && $model->venue !== null) {
            $defaults = array_intersect_key($this->details($model->venue), array_flip([
                'parking_type', 'parking_notes', 'transit_notes', 'entrance_notes', 'accessibility_notes', 'accessibility',
            ]));
            $own = array_replace($defaults, array_filter($own, fn ($value): bool => $value !== null && $value !== ''));
        }

        return $own;
    }

    public function indexable(Model $model): bool
    {
        $seo = $model->getAttribute('seo') ?? [];

        return ! $model->getAttribute('is_demo') && ($seo['indexing'] ?? null) !== SeoIndexing::Excluded->value;
    }

    public function taxonomyIndexable(Category|Tag $model, City $city): bool
    {
        return $this->indexable($model) && $model->events()->inCity($city)->readable()->where('is_demo', false)->exists();
    }

    public function meta(Model $model, PageMeta $fallback): PageMeta
    {
        $seo = $model->getAttribute('seo') ?? [];

        return new PageMeta(
            title: $this->text($seo['title'] ?? null) ?? $fallback->title,
            heading: $fallback->heading,
            description: $this->text($seo['description'] ?? null) ?? $fallback->description,
            canonical: $fallback->canonical,
            image: SafeUrl::href($seo['image'] ?? null) ?? $fallback->image,
            indexable: $fallback->indexable && $this->indexable($model),
            imageWidth: empty($seo['image']) ? $fallback->imageWidth : null,
            imageHeight: empty($seo['image']) ? $fallback->imageHeight : null,
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && filled($value) ? str($value)->stripTags()->squish()->value() : null;
    }
}
