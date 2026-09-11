<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\OccurrenceResource;
use App\Models\Organizer;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Services\Seo\StructuredData;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Gli organizzatori (§11.4): chi mette in piedi le serate, che non sempre
 * coincide con il locale che le ospita.
 *
 * **L'elenco con una ricerca dentro non entra in un indice.** Ogni `?q=`
 * diverso è una pagina diversa, e le stringhe possibili sono infinite: senza
 * la dichiarazione esplicita, ognuna sarebbe `index, follow` con il proprio
 * canonico, cioè esattamente la coda infinita che `EventListingMeta` e
 * `VenueController` si prendono la briga di chiudere. La pagina resta
 * navigabile — è il motore che non deve collezionarla.
 */
final class OrganizerController extends Controller
{
    use Concerns\InteractsWithCity;

    public function index(Request $request): View|JsonResponse
    {
        $term = trim((string) ($request->validate(['q' => 'nullable|string|max:120'])['q'] ?? ''));
        $city = $this->city();
        $query = Organizer::query()->visibleInCity($city)
            ->when($term !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))->orderBy('name')->orderBy('id');
        $items = $query->paginate(20)->withQueryString();
        if ($request->is('api/*')) {
            return ApiResponse::collection($items->getCollection()->map(fn ($o) => $this->summary($o))->all(), ['has_more' => $items->hasMorePages(), 'page' => $items->currentPage()]);
        }

        $meta = new PageMeta(
            title: __('organizers.title'),
            heading: __('organizers.title'),
            description: $term === ''
                ? __('organizers.meta.index', ['city' => $city->name])
                : __('organizers.meta.search', ['query' => $term, 'city' => $city->name]),
            /*
             * Il canonico è l'elenco nudo: una ricerca e la sua pagina due non
             * sono due pagine da indicizzare, sono due modi di guardare la
             * stessa.
             */
            canonical: route('organizers.index'),
            indexable: $term === '',
        );

        return view('organizers.index', [
            'organizers' => $items,
            'term' => $term,
            'meta' => $meta,
            'structuredData' => [
                app(StructuredData::class)->collection($meta->title, $meta->canonical ?? route('organizers.index'),
                    $items->getCollection()->map(fn (Organizer $organizer): array => [
                        'name' => $organizer->name, 'url' => route('organizers.show', $organizer),
                    ])->values()->all()),
            ],
        ]);
    }

    public function show(Request $request, string $slug): View|JsonResponse
    {
        $organizer = Organizer::query()->where('is_active', true)->where('slug', $slug)->firstOrFail();
        $city = $this->city();
        $past = $request->boolean('past');
        $query = ($past ? EventOccurrenceQuery::archiveFor($city)->past()->orderByNewestFirst() : EventOccurrenceQuery::for($city)->upcoming())->byOrganizer($organizer);
        $dates = $query->paginate(24)->withQueryString();
        $rows = new Collection($dates->items());
        $rows->load(['event.venue', 'event.category', 'event.media', 'venue']);
        if ($request->is('api/*')) {
            $user = auth('sanctum')->user();
            $context = ApiContext::forOccurrences($city, [], $user instanceof User ? $user : null, $rows->modelKeys());

            return ApiResponse::item([...$this->summary($organizer), 'description' => $organizer->description, 'website' => $organizer->website,
                'events' => $rows->map(fn ($o) => OccurrenceResource::toArray($o, $context))->all(),
                'has_more' => $dates->hasMorePages(), 'page' => $dates->currentPage()]);
        }

        return view('organizers.show', ['organizer' => $organizer, 'occurrences' => $dates, 'past' => $past,
            /*
             * L'archivio canonicalizza sulla scheda: `?past=1` è un modo di
             * guardare lo stesso soggetto, non un secondo soggetto.
             */
            'meta' => new PageMeta(title: $organizer->name, heading: $organizer->name,
                description: Str::limit(str($organizer->description ?? '')->stripTags()->squish()->value(), 160),
                canonical: route('organizers.show', $organizer)),
            'structuredData' => [['@context' => 'https://schema.org', '@type' => 'Organization', '@id' => route('organizers.show', $organizer).'#organizer', 'name' => $organizer->name, 'url' => route('organizers.show', $organizer)]]]);
    }

    /** @return array<string, mixed> */
    private function summary(Organizer $organizer): array
    {
        return ['id' => $organizer->id, 'name' => $organizer->name, 'slug' => $organizer->slug, 'url' => route('organizers.show', $organizer)];
    }
}
