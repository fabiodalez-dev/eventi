<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\EventFilters;
use App\DTOs\PageMeta;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\InteractsWithCity;
use App\Http\Requests\Web\CalendarWizardRequest;
use App\Models\Category;
use App\Models\Venue;
use App\Services\Feeds\EventFeed;
use Illuminate\Contracts\View\View;

final class CalendarWizardController extends Controller
{
    use InteractsWithCity;

    public function __invoke(CalendarWizardRequest $request, EventFeed $feed): View
    {
        $city = $this->city();
        $selection = $request->validated();
        $step = (int) ($selection['step'] ?? 1);
        $parameters = array_filter([
            'category' => implode(',', $selection['categories'] ?? []),
            'venue' => $selection['venue'] ?? null,
            'price' => $request->boolean('free') ? 'free' : null,
            'days' => $selection['days'] ?? 30,
        ], static fn ($value) => $value !== null && $value !== '');

        return view('feeds.wizard', [
            'city' => $city,
            'step' => $step,
            'selection' => $selection,
            'categories' => Category::query()->active()->ordered()->get(),
            'venues' => Venue::query()->approved()->where('city_id', $city->getKey())->orderBy('name')->get(['id', 'name', 'slug']),
            'calendarUrl' => route('feeds.calendar', $parameters),
            'preview' => $step === 3 ? $feed->occurrences($city, EventFilters::fromArray($parameters), days: (int) $parameters['days']) : collect(),
            'meta' => new PageMeta(title: __('subscriptions.title'), heading: __('subscriptions.title'), description: __('subscriptions.lead'), indexable: $step === 1, canonical: route('feeds.wizard')),
        ]);
    }
}
