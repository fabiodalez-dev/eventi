<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Account;

use App\DTOs\PageMeta;
use App\Enums\AccessibilityFeature;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\User;
use App\Models\Venue;
use App\Services\Account\ContentPreferences;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContentPreferencesController extends Controller
{
    public function index(Request $request, ContentPreferences $preferences): View|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $selection = $preferences->selection($user);
        $data = [
            'selection' => $selection,
            'options' => Category::query()->active()->ordered()->get(['id', 'name']),
            'hidden_venue_options' => Venue::query()->whereKey($selection['hidden_venues'])->orderBy('name')->get(['id', 'name', 'slug'])
                ->map(static fn (Venue $venue): array => ['id' => (int) $venue->getKey(), 'name' => $venue->name, 'slug' => $venue->slug])->all(),
            'access_options' => AccessibilityFeature::options(),
        ];

        return $request->is('api/*') ? ApiResponse::item($data) : view('account.content-preferences', [
            ...$data,
            'meta' => new PageMeta(title: 'I miei interessi', heading: 'I miei interessi', indexable: false),
        ]);
    }

    public function update(Request $request, ContentPreferences $preferences): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if (! $request->isJson()) {
            $choices = $request->validate(['choices' => ['sometimes', 'array', 'max:1000'], 'choices.*' => [Rule::in(['neutral', 'interested', 'hidden'])]])['choices'] ?? [];
            $request->merge(['categories' => array_keys(array_filter($choices, fn ($choice) => $choice === 'interested')), 'hidden_categories' => array_keys(array_filter($choices, fn ($choice) => $choice === 'hidden'))]);
            $request->merge(['categories' => $request->input('categories', []), 'hidden_categories' => $request->input('hidden_categories', []), 'inferred_ads' => $request->boolean('inferred_ads')]);
            /* La casella delle esigenze è nel modulo principale: se nessuna è
               spuntata il browser non manda niente, e il segnaposto distingue
               «tolte tutte» da «modulo che non le contiene». */
            if ($request->boolean('access_needs_present')) {
                $request->merge(['access_needs' => $request->input('access_needs', [])]);
            }
        }
        $data = $request->validate([
            'mode' => ['required', Rule::in(['all', 'selected'])],
            'categories' => ['present', 'array', 'max:1000'],
            'categories.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
            'hidden_categories' => ['present', 'array', 'max:1000'],
            'hidden_categories.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('is_active', true)],
            'inferred_ads' => ['required', 'boolean'],
            'hidden_venues' => ['sometimes', 'array', 'max:500'],
            'hidden_venues.*' => ['integer', 'distinct'],
            'access_needs' => ['sometimes', 'array', 'max:10'],
            'access_needs.*' => ['string', 'distinct', Rule::in(AccessibilityFeature::values())],
        ]);
        if (array_intersect($data['categories'], $data['hidden_categories']) !== []) {
            throw ValidationException::withMessages(['categories' => 'Una categoria non può essere contemporaneamente preferita e nascosta.']);
        }
        /* I campi nuovi sono facoltativi: chi non li manda (un'app vecchia,
           il modulo di ripristino) non deve cancellarli. */
        $stored = $user->content_preferences ?? [];
        $data['hidden_venues'] = array_values(array_map('intval', $data['hidden_venues'] ?? ($stored['hidden_venues'] ?? [])));
        $data['access_needs'] = array_values($data['access_needs'] ?? ($stored['access_needs'] ?? []));
        $user->update(['content_preferences' => $data]);

        return $request->is('api/*') ? $this->index($request, $preferences) : to_route('account.content-preferences')->with('status', 'Interessi aggiornati. Puoi cambiarli quando vuoi.');
    }

    /** «Non mostrarmi questo locale» e il contrario, dal sito e dall'app. */
    public function hideVenue(Request $request, int $venue, ContentPreferences $preferences): RedirectResponse|JsonResponse
    {
        return $this->toggleVenue($request, $venue, $preferences, true);
    }

    public function showVenue(Request $request, int $venue, ContentPreferences $preferences): RedirectResponse|JsonResponse
    {
        return $this->toggleVenue($request, $venue, $preferences, false);
    }

    private function toggleVenue(Request $request, int $venueId, ContentPreferences $preferences, bool $hide): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(Venue::query()->whereKey($venueId)->exists(), 404);
        $preferences->toggleVenue($user, $venueId, $hide);

        return $request->is('api/*') || $request->expectsJson()
            ? response()->json(null, 204)
            : back()->with('status', $hide ? __('account.hidden_venues.hidden') : __('account.hidden_venues.shown'));
    }
}
