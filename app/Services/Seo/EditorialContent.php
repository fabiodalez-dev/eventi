<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\DTOs\PageMeta;
use App\Enums\SeoIndexing;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Tag;
use App\Models\Venue;
use App\Support\BeforeGoingDefaults;
use App\Support\CurrentCity;
use App\Support\DeclaredCosts;
use App\Support\SafeUrl;
use Illuminate\Database\Eloquent\Model;

final class EditorialContent
{
    /** @return array<string, mixed> */
    public function details(Model $model, ?EventOccurrence $occurrence = null): array
    {
        if ($model instanceof Event && $occurrence !== null) {
            $model = clone $model;
            $model->setRelation('venue', $occurrence->effectiveVenue());
        }
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
            $own = BeforeGoingDefaults::merge(BeforeGoingDefaults::forVenue($model->venue), $own);
        }

        if ($model instanceof Event) {
            $own['membership'] = $model->membershipRequirement()?->value;
            if ($occurrence !== null) {
                $own = BeforeGoingDefaults::merge($own, $occurrence->practical_details ?? []);
            }
            $own['practical_items'] = app(BeforeGoing::class)->items($model, $own);
            $own['declared_costs'] = DeclaredCosts::for($occurrence);
        } elseif ($model instanceof Venue) {
            /*
             * Le stesse voci pratiche, per il locale che le ha compilate.
             *
             * Caratteristiche, servizi, fasce d'età e note erano campi che un
             * locale riempiva e nessuna pagina stampava: il codice che le
             * disegna girava solo per gli eventi. `BeforeGoing` parte da un
             * evento, e qui un evento non c'è: un evento non salvato che porta
             * soltanto il `content_details` del locale evita di riscrivere
             * quelle regole una seconda volta, con il rischio che le due copie
             * divergano al primo campo nuovo.
             *
             * Il locale resta volutamente scollegato dall'evento fittizio:
             * `items()` chiuderebbe l'elenco con i servizi del locale, che la
             * scheda elenca già nella colonna a fianco.
             */
            $own['practical_items'] = app(BeforeGoing::class)
                ->items((new Event)->forceFill(['content_details' => $own]), $own);
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
