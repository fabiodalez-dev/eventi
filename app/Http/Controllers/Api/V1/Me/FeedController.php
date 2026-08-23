<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Api\V1\Concerns\InteractsWithMe;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\MeQueryRequest;
use App\Http\Resources\V1\CategoryResource;
use App\Http\Resources\V1\OccurrenceResource;
use App\Http\Resources\V1\VenueResource;
use App\Models\Category;
use App\Models\EventOccurrence;
use App\Models\Venue;
use App\Services\Account\PersonalFeed;
use App\Services\Api\OccurrenceFeed;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * `GET /v1/me/feed` (§15.7): le date future di ciò che si segue.
 *
 * **Mai una risposta vuota e basta.** Chi non segue ancora niente riceve
 * `data: []` insieme a `meta.onboarding`, cioè i locali più attivi e le
 * categorie con più date: è la stessa regola della pagina del sito, e un'app
 * che la ignorasse mostrerebbe una schermata bianca al primo avvio.
 */
final class FeedController extends Controller
{
    use InteractsWithMe;

    public function __construct(
        private readonly PersonalFeed $feed,
        private readonly OccurrenceFeed $occurrences,
    ) {}

    public function __invoke(MeQueryRequest $request): JsonResponse
    {
        $city = $this->city();
        $user = $this->user($request);

        if (! $user->followsAnything()) {
            return ApiResponse::collection([], ['onboarding' => $this->onboarding()]);
        }

        $paginator = $this->feed->cursor($city, $user, $request->limit(), $request->cursor())->withQueryString();

        /** @var Collection<int, EventOccurrence> $items */
        $items = new Collection($paginator->items());

        $includes = $request->includes();
        $this->occurrences->hydrate($items, $includes);

        $context = ApiContext::forOccurrences($city, $includes, $user, $this->occurrences->ids($items));

        return ApiResponse::page(
            $paginator,
            static fn (EventOccurrence $occurrence): array => OccurrenceResource::toArray($occurrence, $context),
            ['onboarding' => null],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function onboarding(): array
    {
        $city = $this->city();

        return [
            'venues' => $this->feed
                ->suggestedVenues($city, config()->integer('account.onboarding_venues'))
                ->map(static fn (Venue $venue): array => VenueResource::summary($venue))
                ->all(),
            'categories' => $this->feed
                ->suggestedCategories($city, config()->integer('account.onboarding_categories'))
                ->map(static fn (Category $category): array => CategoryResource::summary($category))
                ->all(),
        ];
    }
}
