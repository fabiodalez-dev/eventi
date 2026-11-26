<?php

declare(strict_types=1);

namespace App\Services\Editorial\Catalog;

use App\Enums\SeoIndexing;
use App\Models\Category;
use App\Models\City;
use App\Models\EventFeature;
use App\Models\Page;
use App\Models\Tag;
use App\Models\User;
use App\Support\PracticalIcons;
use Illuminate\Support\Facades\Gate;

/**
 * L'ingresso dell'area «Catalogo» dell'app: le voci del gruppo «Contenuti»
 * del pannello che la persona vede (categorie, tag, caratteristiche,
 * città, pagine), con quante sono e se può crearne, e le scelte che servono
 * ai moduli. Quale voce si vede lo decide la stessa domanda che il pannello
 * fa per il menu (`viewAny` della policy): tutta la redazione legge
 * categorie, tag e città; caratteristiche e pagine sono di admin e super
 * admin.
 */
final class CatalogOverview
{
    /** Le voci, nell'ordine del menu del pannello. */
    public const SECTIONS = [
        'categories' => Category::class,
        'tags' => Tag::class,
        'event_features' => EventFeature::class,
        'cities' => City::class,
        'pages' => Page::class,
    ];

    /** @return list<array{key: string, count: int, can_create: bool}> */
    public function sections(User $user): array
    {
        $gate = Gate::forUser($user);
        $sections = [];
        foreach (self::SECTIONS as $key => $model) {
            if (! $gate->allows('viewAny', $model)) {
                continue;
            }
            $sections[] = ['key' => $key, 'count' => $model::query()->count(), 'can_create' => $gate->allows('create', $model)];
        }

        return $sections;
    }

    /** Se la persona vede la voce `key`: per i controller. */
    public function allows(User $user, string $key): bool
    {
        return isset(self::SECTIONS[$key]) && Gate::forUser($user)->allows('viewAny', self::SECTIONS[$key]);
    }

    /**
     * Le scelte dei moduli: categorie (padre, categoria del tag), città
     * (presentazioni per città), gruppi e icone delle caratteristiche, fusi
     * orari e lingue delle città, visibilità sui motori.
     *
     * @return array<string, mixed>
     */
    public function choices(): array
    {
        return [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name'])
                ->map(static fn (Category $category): array => ['value' => (string) $category->id, 'label' => (string) $category->name])->values()->all(),
            'cities' => City::query()->orderByDesc('is_active')->orderBy('name')->get(['id', 'name'])
                ->map(static fn (City $city): array => ['value' => (string) $city->id, 'label' => (string) $city->name])->values()->all(),
            'feature_groups' => array_map(static fn (string $group): array => ['value' => $group, 'label' => $group], EventFeature::GROUPS),
            'feature_icons' => array_map(
                static fn (string $icon, string $label): array => ['value' => $icon, 'label' => $label],
                array_keys(PracticalIcons::options()),
                PracticalIcons::options(),
            ),
            'timezones' => timezone_identifiers_list(),
            'locales' => City::LOCALES,
            'seo_indexing' => [
                ['value' => SeoIndexing::Automatic->value, 'label' => (string) __('seo.automatic')],
                ['value' => SeoIndexing::Excluded->value, 'label' => (string) __('seo.excluded')],
            ],
            'limits' => [
                'text' => CatalogContent::MAX_TEXT,
                'faqs' => CatalogContent::MAX_FAQS,
                'seo_title' => 180,
                'seo_description' => 400,
            ],
        ];
    }
}
