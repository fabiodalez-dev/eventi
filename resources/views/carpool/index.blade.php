<x-layouts.app :meta="$meta">@include('carpool.nav')<div class="w-full max-w-3xl">
<h1 class="text-hero">{{ __('carpool.mine') }}</h1>
@if(!$access['eligible'])<a class="my-5 inline-flex min-h-12 items-center underline" href="{{ route('carpool.requirements') }}">{{ __('carpool.requirements') }}</a>@endif
@php($selectedTab = request('tab', 'offered'))
<nav class="my-8 flex flex-wrap gap-3 border-b border-line pb-4" aria-label="{{ __('carpool.mine') }}">@foreach(['offered','requested','searches','history'] as $tab)<a href="{{ route('carpool.index', ['tab' => $tab]) }}" @if($selectedTab === $tab) aria-current="page" @endif class="inline-flex min-h-12 items-center rounded-xl border border-line px-4 {{ $selectedTab === $tab ? 'bg-accent text-on-accent' : '' }}">{{ __('carpool.'.$tab) }}</a>@endforeach</nav>
@if(in_array($selectedTab, ['offered','history']))
@forelse($offers as $offer) @include('carpool.partials.offer-row') @empty @if($selectedTab === 'offered')<p class="py-8 text-ink-muted">{{ __('carpool.empty_mine') }}</p>@endif @endforelse
@include('carpool.partials.pages')
@endif
@if(in_array($selectedTab, ['requested','history']))
@forelse($requests as $ride)<div class="mt-6"><h2 class="font-display text-xl font-bold">{{ $ride['offer']['event_title'] }}</h2><p class="mt-2 text-sm text-ink-muted">{{ $ride['offer']['zone'] }} · {{ $ride['offer']['departure_label'] }}</p>@include('carpool.partials.request-row')</div>@empty @if($selectedTab === 'requested')<p class="py-8 text-ink-muted">{{ __('carpool.empty_mine') }}</p>@endif @endforelse
@include('carpool.partials.pages', ['page_key' => 'requests_page', 'page' => $requests_page, 'has_more' => $requests_has_more])
@endif
@if($selectedTab === 'searches')
@foreach($searches as $search)<article class="border-b border-line py-6"><h2 class="font-semibold">{{ $search['event_title'] }} · {{ $search['leg_label'] }}</h2><p class="my-3">{{ $search['zone'] }} · {{ __('carpool.requested_seats', ['count' => $search['seats']]) }}</p><form method="post" action="{{ route('carpool.discovery','toggle-search') }}">@include('carpool.partials.key')<input type="hidden" name="search_id" value="{{ $search['id'] }}"><input type="hidden" name="active" value="{{ $search['active'] ? 0 : 1 }}"><x-button type="submit" variant="secondary">{{ __('carpool.'.($search['active'] ? 'search_stop' : 'search_start')) }}</x-button></form></article>@endforeach
@include('carpool.partials.pages', ['page_key' => 'searches_page', 'page' => $searches_page, 'has_more' => $searches_has_more])
@endif
@if(count($templates))<details class="mt-8 border-t border-line py-5"><summary class="min-h-12 cursor-pointer font-semibold">{{ __('carpool.templates') }}</summary><p class="my-4 text-sm text-ink-muted">{{ __('carpool.repeat') }}</p>@foreach($templates as $template)<div class="flex items-center justify-between gap-4 border-b border-line py-4"><span>{{ $template['name'] }}</span><form method="post" action="{{ route('carpool.discovery','delete-template') }}">@include('carpool.partials.key')<input type="hidden" name="template_id" value="{{ $template['id'] }}"><x-button type="submit" variant="secondary">{{ __('carpool.delete') }}</x-button></form></div>@endforeach</details>@endif
</div></x-layouts.app>
