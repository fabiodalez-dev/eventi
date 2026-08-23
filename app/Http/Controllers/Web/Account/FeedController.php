<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithAccount;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
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

        $follows = $user->followsAnything();

        $occurrences = $follows
            ? $this->feed->paginate($city, $user, config()->integer('account.feed_per_page'))
            : null;

        return view('account.feed', [
            'occurrences' => $occurrences,
            'venues' => $follows ? null : $this->feed->suggestedVenues($city, config()->integer('account.onboarding_venues')),
            'categories' => $follows ? null : $this->feed->suggestedCategories($city, config()->integer('account.onboarding_categories')),
            'meta' => new PageMeta(
                title: __('account.feed.title'),
                heading: __('account.feed.title'),
                description: __('account.feed.lead'),
                indexable: false,
            ),
        ]);
    }
}
