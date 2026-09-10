<?php

namespace App\Http\Controllers\Web;

use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\TonightRequest;
use App\Http\Resources\V1\OccurrenceResource;
use App\Models\Category;
use App\Models\User;
use App\Services\Account\ContentPreferences;
use App\Services\Search\TonightDiscovery;
use App\Services\Seo\EditorialContent;
use App\Support\Api\ApiContext;
use App\Support\Api\ApiResponse;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class TonightController extends Controller
{
    use Concerns\InteractsWithCity;

    public function __invoke(TonightRequest $request, TonightDiscovery $discovery, ContentPreferences $preferences): View|JsonResponse|RedirectResponse
    {
        $city = $this->city();
        $input = $request->validated();
        // Keep the existing API step=3 contract; guided web screens are independently named.
        $guided = ! $request->is('api/*') && ! $request->has('step') || $request->has('question');
        if ($guided && ! array_key_exists('municipality', $input)) {
            $input['municipality'] = $city->name;
        }
        if (array_key_exists('municipality', $input) && $input['municipality'] !== 'Padova') {
            $input['zone'] = null;
        }
        $question = $input['question'] ?? ($guided ? 'when' : match ((int) ($input['step'] ?? 1)) {
            3 => 'results', 2 => 'categories', default => 'municipality'
        });
        if ($question === 'district' && ($input['municipality'] ?? null) !== 'Padova') {
            $question = 'budget';
        }
        $questions = ['when', 'municipality', ...(($input['municipality'] ?? null) === 'Padova' ? ['district'] : []), 'budget', 'categories', 'results'];
        $user = $request->is('api/*') ? $request->user('sanctum') : $request->user();
        $user = $user instanceof User ? $user : null;
        $categories = Category::query()->active()->ordered()->whereNotIn('id', $preferences->hidden($user))->get();
        if (! $request->is('api/*') && $question === 'results') {
            $selected = Category::query()->whereIn('id', $input['categories'] ?? [])->pluck('slug')->implode(',');

            return redirect()->route('events.index', array_filter([
                'date' => $input['when'] ?? 'tonight', 'municipality' => $input['municipality'] ?? null,
                'zone' => $input['zone'] ?? null, 'budget' => $input['budget'] ?? null,
                'category' => $selected, 'discovery' => '1',
            ], fn ($value) => $value !== null && $value !== ''));
        }
        $zones = collect(config()->array('discovery-geography.'.$city->slug.'.districts', []));
        $municipalities = config()->array('discovery-geography.'.$city->slug.'.municipalities', [$city->name]);
        $dates = $question === 'results' ? $discovery->find($city, $input) : new Collection;
        $counts = $discovery->counts($city, $input);
        $municipalities = collect($municipalities)->sortBy(fn (string $name): int => ($counts['municipalities'][$name] ?? 0) > 0 ? 0 : 1)->values()->all();
        $zones = $zones->sortBy(fn (string $name): int => ($counts['zones'][$name] ?? 0) > 0 ? 0 : 1)->values();
        $apiCounts = [...$counts, 'municipalities' => (object) $counts['municipalities'], 'zones' => (object) $counts['zones'], 'categories' => (object) $counts['categories'], 'budgets' => (object) $counts['budgets']];
        if ($request->boolean('preview')) {
            return response()->json(['data' => $apiCounts])->header('Cache-Control', 'private, no-store');
        }
        if ($request->is('api/*')) {
            $context = ApiContext::forOccurrences($city, [], $user, $dates->modelKeys());

            return ApiResponse::item([
                'counts' => $apiCounts,
                'categories' => $categories->map(fn ($category) => ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug])->all(),
                'soon_minutes' => $city->starting_soon_minutes,
                'zones' => $zones->all(),
                'municipalities' => $municipalities,
                'neighborhood_municipality' => $city->slug === 'padova' ? 'Padova' : null,
                'results' => $dates->map(fn ($date) => [
                    'occurrence' => OccurrenceResource::toArray($date, $context),
                    'reasons' => $discovery->reasons($date, $input),
                    'practical' => $discovery->practical($date),
                    'content_details' => app(EditorialContent::class)->details($date->event, $date),
                ])->all(),
            ]);
        }

        return view('tonight.wizard', [
            'input' => $input, 'step' => (int) ($input['step'] ?? 1), 'city' => $city,
            'categories' => $categories, 'zones' => $zones, 'dates' => $dates,
            'municipalities' => $municipalities, 'question' => $question, 'questions' => $questions,
            'discovery' => $discovery,
            'counts' => $counts,
            'meta' => new PageMeta(title: __('tonight.title'), heading: __('tonight.title'), indexable: false),
        ]);
    }
}
