<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\EventFilterRequest;
use App\Services\Search\SiteSearch;
use Illuminate\Http\Response;

final class SearchSuggestionsController extends Controller
{
    use InteractsWithCity;

    public function __invoke(EventFilterRequest $request, SiteSearch $search): Response
    {
        $city = $this->city();
        $term = trim($request->filters()->q);
        $eligible = mb_strlen($term) >= 3;

        return response()->view('search.suggestions', [
            'city' => $city,
            'term' => $term,
            'events' => $eligible ? $search->events($city, $term, 5) : collect(),
            'venues' => $eligible ? $search->venues($city, $term, 4) : collect(),
            'tags' => $eligible ? $search->tags($term, 4) : collect(),
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex');
    }
}
