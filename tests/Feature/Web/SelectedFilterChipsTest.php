<?php

use App\DTOs\EventFilters;
use App\Enums\DatePreset;
use App\Enums\PriceFilter;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;

function selectedFilterHtml(EventFilters $filters): string
{
    view()->share('errors', new ViewErrorBag);

    return Blade::render('<x-filter-bar :filters="$filters" :categories="$categories" :tags="[]" :municipalities="[]" :venues="[]" />', [
        'filters' => $filters,
        'categories' => collect([(object) ['slug' => 'cinema', 'name' => 'Cinema'], (object) ['slug' => 'teatro', 'name' => 'Teatro']]),
    ]);
}

it('shows only selected category chips and preserves other filters on removal', function () {
    $html = selectedFilterHtml(new EventFilters(categories: ['cinema'], price: PriceFilter::Free));
    $chips = explode('<details', $html)[0];
    expect($chips)->toContain('Cinema')->not->toContain('Teatro')
        ->and($chips)->toContain('price=free');
});

it('restores all category choices when no category is selected', function () {
    expect(selectedFilterHtml(new EventFilters))->toContain('Cinema', 'Teatro');
});

it('keeps advanced filters collapsed even with active filters', function () {
    $html = selectedFilterHtml(new EventFilters(categories: ['cinema'], price: PriceFilter::Free));
    preg_match('/<details[^>]*>/', $html, $details);
    expect($details[0])->not->toContain('open');
    expect($html)->toContain('Filtri avanzati');
});

it('keeps every selected category available for removal', function () {
    expect(selectedFilterHtml(new EventFilters(categories: ['cinema', 'teatro'])))->toContain('Cinema', 'Teatro');
});

it('keeps advanced fields readable inside a narrow desktop sidebar', function () {
    $html = selectedFilterHtml(new EventFilters);
    preg_match('/<div data-advanced-filter-fields class="[^"]*"/', $html, $grid);

    expect($grid[0])->toContain('grid-cols-1', '[&_select]:text-base', '[&_input]:text-base')
        ->not->toContain('sm:grid-cols-2', 'lg:grid-cols-3');
    expect($html)->toContain('min-h-12 cursor-pointer items-center', 'size-5 shrink-0');
});

it('collapses date price and feature groups to their active choices', function () {
    $html = explode('<details', selectedFilterHtml(new EventFilters(preset: DatePreset::Tonight, price: PriceFilter::Free, outdoor: true)))[0];
    expect($html)->toContain(DatePreset::Tonight->label(), PriceFilter::Free->label(), e(__('filters.features.outdoor')))
        ->not->toContain(DatePreset::Tomorrow->label(), PriceFilter::Donation->label(), __('filters.features.family'));
});
