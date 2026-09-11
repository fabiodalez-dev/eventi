<x-layouts.app :meta="$meta" :narrow="true">
    <header class="mb-8">
        <p class="text-eyebrow text-accent">{{ $step }} / 3 · {{ __('subscriptions.steps.'.$step) }}</p>
        <h1 class="mt-3 text-hero">{{ $meta->heading }}</h1>
        <p class="mt-4 max-w-prose text-ink-muted">{{ __('subscriptions.lead') }}</p>
    </header>
    @if ($errors->any())
        <div role="alert" class="mb-6 border-2 border-line p-4">{{ $errors->first() }}</div>
    @endif
    <form method="GET" action="{{ route('feeds.wizard') }}" class="space-y-6">
        @if ($step === 1)
            @foreach (['days', 'free', 'venue'] as $key)
                @if (isset($selection[$key]))<input type="hidden" name="{{ $key }}" value="{{ $selection[$key] }}">@endif
            @endforeach
            <fieldset>
                <legend class="mb-4 text-sm text-ink-muted">{{ __('subscriptions.all') }}</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($categories as $category)
                        <label class="flex items-center gap-3 border border-line p-4 has-checked:border-accent has-checked:bg-surface">
                            <input type="checkbox" name="categories[]" value="{{ $category->slug }}" @checked(in_array($category->slug, $selection['categories'] ?? [], true)) class="size-5 accent-accent">
                            <span>{{ $category->name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <input type="hidden" name="step" value="2">
            <x-button type="submit">{{ __('subscriptions.next') }}</x-button>
        @elseif ($step === 2)
            @foreach ($selection['categories'] ?? [] as $slug)<input type="hidden" name="categories[]" value="{{ $slug }}">@endforeach
            <section aria-labelledby="calendar-sync-heading" class="border border-line bg-surface p-4 sm:p-6">
                <h2 id="calendar-sync-heading" class="font-display text-lg font-extrabold">{{ __('subscriptions.continuous_title') }}</h2>
                <p class="mt-2 text-sm text-ink-muted">{{ __('subscriptions.continuous_help') }}</p>
                <p class="mt-2 text-sm text-ink-muted">{{ __('subscriptions.continuous_timing') }}</p>
            </section>
            <label class="block">{{ __('subscriptions.days') }}
                <select name="days" aria-describedby="calendar-horizon-help" class="mt-2 block w-full border-2 border-line bg-canvas p-3">
                    @foreach ([7, 30, 90] as $days)<option value="{{ $days }}" @selected(($selection['days'] ?? 30) == $days)>{{ __('subscriptions.days_option', ['days' => $days]) }}</option>@endforeach
                </select>
            </label>
            <p id="calendar-horizon-help" class="text-sm text-ink-muted">{{ __('subscriptions.horizon_help') }}</p>
            <div data-venue-autocomplete data-empty="{{ __('subscriptions.venue_empty') }}" data-invalid="{{ __('subscriptions.venue_invalid') }}">
              <label data-venue-fallback class="block">{{ __('subscriptions.venue') }}
                <select name="venue" class="mt-2 block w-full border-2 border-line bg-canvas p-3" data-venue-select>
                    <option value="">{{ __('subscriptions.all_venues') }}</option>
                    @foreach ($venues as $venue)<option value="{{ $venue->slug }}" @selected(($selection['venue'] ?? '') === $venue->slug)>{{ $venue->name }}</option>@endforeach
                </select>
              </label>
              <div data-venue-enhanced hidden>
                <label for="calendar-venue-search" class="block">{{ __('subscriptions.venue') }}</label>
                <div class="relative mt-2">
                    <input id="calendar-venue-search" data-venue-input type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="calendar-venue-options" aria-describedby="calendar-venue-help" autocomplete="off" placeholder="{{ __('subscriptions.venue_search') }}" class="w-full border-2 border-line bg-canvas p-3">
                    <ul id="calendar-venue-options" data-venue-options role="listbox" aria-label="{{ __('subscriptions.venue') }}" hidden class="absolute inset-x-0 top-full z-30 max-h-64 overflow-y-auto border-2 border-line bg-canvas"></ul>
                </div>
                <p id="calendar-venue-help" class="mt-2 text-sm text-ink-muted">{{ __('subscriptions.venue_search_help') }}</p>
                <p data-venue-status role="status" class="mt-2 text-sm text-ink-muted"></p>
                <button type="button" data-venue-clear hidden class="mt-2 underline">{{ __('subscriptions.venue_clear') }}</button>
              </div>
            </div>
            <label class="flex items-center gap-3"><input type="checkbox" name="free" value="1" @checked($selection['free'] ?? false) class="size-5 accent-accent">{{ __('subscriptions.free') }}</label>
            <input type="hidden" name="step" value="3">
            <div class="flex flex-wrap gap-4"><x-button type="submit">{{ __('subscriptions.next') }}</x-button><a class="p-3 underline" href="{{ route('feeds.wizard', [...$selection, 'step' => 1]) }}">{{ __('subscriptions.back') }}</a></div>
        @else
            <h2 class="text-section">{{ __('subscriptions.ready') }}</h2>
            <p>{{ $preview->isEmpty() ? __('subscriptions.empty') : __('subscriptions.count', ['count' => $preview->count()]) }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($categories->whereIn('slug', $selection['categories'] ?? []) as $category)<span class="ui-tag border border-line px-3 py-2 text-sm">{{ $category->name }}</span>@endforeach
            </div>
            <p class="text-sm text-ink-muted">{{ __('subscriptions.limit', ['count' => config('feeds.max_items')]) }}</p>
            <div class="flex flex-wrap gap-3">
                <x-button :href="route('google-calendar.index', array_diff_key($selection, ['step' => true]))">{{ __('google_calendar.manage') }}</x-button>
                <x-button :href="$calendarUrl" variant="secondary">{{ auth()->check() ? __('subscriptions.download') : __('google_calendar.download_login') }}</x-button>
            </div>
            <p class="text-sm text-ink-muted">{{ __('google_calendar.download_help') }}</p>
            <a class="inline-block p-3 underline" href="{{ route('feeds.wizard', [...$selection, 'step' => 2]) }}">{{ __('subscriptions.back') }}</a>
        @endif
    </form>
    <section class="mt-10 border-t-2 border-line pt-6">
        <h2 class="text-section">{{ __('subscriptions.alerts') }}</h2>
        <p class="my-4 text-ink-muted">{{ __('subscriptions.alerts_help') }}</p>
        <x-button :href="route('account.notifications')">{{ __('subscriptions.notifications') }}</x-button>
    </section>
</x-layouts.app>
