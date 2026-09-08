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

class TonightController extends Controller
{
    use Concerns\InteractsWithCity;

    public function __invoke(TonightRequest $request, TonightDiscovery $discovery, ContentPreferences $preferences): View|JsonResponse
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
        $question = $input['question'] ?? ($guided ? 'municipality' : match ((int) ($input['step'] ?? 1)) {
            3 => 'results', 2 => 'categories', default => 'municipality'
        });
        if ($question === 'district' && ($input['municipality'] ?? null) !== 'Padova') {
            $question = 'when';
        }
        $questions = ['municipality', ...(($input['municipality'] ?? null) === 'Padova' ? ['district'] : []), 'when', 'budget', 'categories', 'results'];
        $user = $request->is('api/*') ? $request->user('sanctum') : $request->user();
        $user = $user instanceof User ? $user : null;
        $categories = Category::query()->active()->ordered()->whereNotIn('id', $preferences->hidden($user))->get();
        $zones = collect(config()->array('discovery-geography.'.$city->slug.'.districts', []));
        $municipalities = config('discovery-geography.'.$city->slug.'.municipalities', [$city->name]);
        $dates = $question === 'results' ? $discovery->find($city, $input) : new Collection;
        if ($request->is('api/*')) {
            $context = ApiContext::forOccurrences($city, [], $user, $dates->modelKeys());

            return ApiResponse::item([
                'categories' => $categories->map(fn ($category) => ['id' => $category->id, 'name' => $category->name])->all(),
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
            'meta' => new PageMeta(title: __('tonight.title'), heading: __('tonight.title'), indexable: false),
        ]);
    }
}
