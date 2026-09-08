<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithAccount;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Models\Category;
use App\Models\Venue;
use App\Services\Account\ContentPreferences;
use App\Services\Account\PersonalFeed;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * `/il-mio-feed` (§15.7): le prossime date di ciò che si segue, in ordine di
 * data, con evidenza su ciò che si è già messo in agenda.
 *
 * **Mai una pagina vuota.** Chi non segue ancora niente non vede un elenco
 * senza righe ma i locali più attivi e le categorie con più date in programma:
 * è la differenza fra una pagina che sembra rotta e una che spiega come si usa.
 */
final class FeedController extends Controller
{
    use InteractsWithAccount;
    use InteractsWithCity;

    public function __construct(private readonly PersonalFeed $feed) {}

    public function __invoke(Request $request): View
    {
        $city = $this->city();
        $user = $this->accountUser($request);
        $input = $request->validate(['venue_q' => 'nullable|string|max:120', 'venues_page' => 'nullable|integer|min:1', 'categories_page' => 'nullable|integer|min:1']);
        $venueSearch = trim($input['venue_q'] ?? '');

        $follows = $user->followsAnything() || app(ContentPreferences::class)->selection($user)['categories'] !== [];

        $occurrences = $follows
            ? $this->feed->paginate($city, $user, min(12, config()->integer('account.feed_per_page')))->withQueryString()
            : null;

        return view('account.feed', [
            'occurrences' => $occurrences,
            'city' => $city,
            'hasFollows' => $follows,
            'venueSearch' => $venueSearch,
            'venues' => Venue::query()->approved()->where('city_id', $city->id)
                ->when($venueSearch !== '', fn ($q) => $q->where('name', 'like', '%'.addcslashes($venueSearch, '%_\\').'%'))
                ->orderBy('name')->orderBy('id')->paginate(6, ['*'], 'venues_page')->withQueryString()->fragment('feed-interests'),
            'categories' => Category::query()->active()->ordered()->paginate(6, ['*'], 'categories_page')->withQueryString()->fragment('feed-interests'),
            'meta' => new PageMeta(
                title: __('account.feed.title'),
                heading: __('account.feed.title'),
                description: __('account.feed.lead'),
                indexable: false,
            ),
        ]);
    }
}
