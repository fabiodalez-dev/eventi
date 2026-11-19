{{--
    `/calendario/personalizza`: il calendario su misura in tre passi —
    categorie, periodo e locale, risultato. Un passo per volta, con l'indicatore
    «passo N di 3» sopra il titolo, una card per argomento e i pulsanti in
    fondo (Continua per primo, Indietro accanto). È un modulo GET: ogni passo ha
    il suo indirizzo e funziona senza JavaScript.
--}}
@php
    $steps = __('subscriptions.steps');
    $fieldClass = 'min-h-12 w-full border-2 border-line bg-canvas px-3 text-ink';
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$meta->heading"
        :subtitle="__('subscriptions.lead')"
        :eyebrow="__('feeds.wizard.step', ['step' => $step, 'total' => 3, 'name' => $steps[(string) $step]])"
        :back="auth()->check() ? route('account.profile') : null"
    >
        <ol class="m-0 flex list-none gap-2 p-0" aria-label="{{ __('feeds.wizard.progress') }}" data-wizard-steps>
            @foreach ($steps as $number => $label)
                <li class="flex min-w-0 flex-1 flex-col gap-1.5" @if ((int) $number === $step) aria-current="step" @endif>
                    <span @class(['block h-1.5', 'bg-accent' => (int) $number <= $step, 'bg-line' => (int) $number > $step])></span>
                    <span @class(['truncate text-xs font-semibold', 'text-ink' => (int) $number === $step, 'text-ink-muted' => (int) $number !== $step])>{{ $label }}</span>
                </li>
            @endforeach
        </ol>

        <form method="GET" action="{{ route('feeds.wizard') }}" class="flex flex-col gap-6">
            @if ($step === 1)
                @foreach (['days', 'free', 'venue'] as $key)
                    @if (isset($selection[$key]))<input type="hidden" name="{{ $key }}" value="{{ $selection[$key] }}">@endif
                @endforeach
                <x-card :title="$steps['1']" :description="__('subscriptions.all')" padding="none">
                    <fieldset class="m-0 min-w-0 border-0 p-0">
                        <legend class="sr-only">{{ $steps['1'] }}</legend>
                        @foreach ($categories as $category)
                            <label class="svc-row flex min-h-12 cursor-pointer items-center gap-3 px-5 py-2 text-ink sm:px-6">
                                <input type="checkbox" name="categories[]" value="{{ $category->slug }}" @checked(in_array($category->slug, $selection['categories'] ?? [], true)) class="size-5 shrink-0 accent-accent">
                                <span class="min-w-0 break-words">{{ $category->name }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                </x-card>
                <input type="hidden" name="step" value="2">
                <x-form-actions>
                    <x-button type="submit">{{ __('subscriptions.next') }}<x-lucide name="arrow-right" class="size-4" /></x-button>
                </x-form-actions>
            @elseif ($step === 2)
                @foreach ($selection['categories'] ?? [] as $slug)<input type="hidden" name="categories[]" value="{{ $slug }}">@endforeach

                <x-card :title="$steps['2']">
                    <label class="flex flex-col gap-2 text-sm font-semibold text-ink">{{ __('subscriptions.days') }}
                        <select name="days" aria-describedby="calendar-horizon-help" class="{{ $fieldClass }} font-normal">
                            @foreach ([7, 30, 90] as $days)<option value="{{ $days }}" @selected(($selection['days'] ?? 30) == $days)>{{ __('subscriptions.days_option', ['days' => $days]) }}</option>@endforeach
                        </select>
                    </label>
                    <p id="calendar-horizon-help" class="m-0 text-sm text-ink-muted">{{ __('subscriptions.horizon_help') }}</p>

                    <div data-venue-autocomplete data-empty="{{ __('subscriptions.venue_empty') }}" data-invalid="{{ __('subscriptions.venue_invalid') }}" class="flex flex-col gap-2">
                        <label data-venue-fallback class="flex flex-col gap-2 text-sm font-semibold text-ink">{{ __('subscriptions.venue') }}
                            <select name="venue" class="{{ $fieldClass }} font-normal" data-venue-select>
                                <option value="">{{ __('subscriptions.all_venues') }}</option>
                                @foreach ($venues as $venue)<option value="{{ $venue->slug }}" @selected(($selection['venue'] ?? '') === $venue->slug)>{{ $venue->name }}</option>@endforeach
                            </select>
                        </label>
                        <div data-venue-enhanced hidden class="flex flex-col gap-2">
                            <label for="calendar-venue-search" class="text-sm font-semibold text-ink">{{ __('subscriptions.venue') }}</label>
                            <div class="relative">
                                <input id="calendar-venue-search" data-venue-input type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="calendar-venue-options" aria-describedby="calendar-venue-help" autocomplete="off" placeholder="{{ __('subscriptions.venue_search') }}" class="{{ $fieldClass }}">
                                <ul id="calendar-venue-options" data-venue-options role="listbox" aria-label="{{ __('subscriptions.venue') }}" hidden class="absolute inset-x-0 top-full z-30 m-0 max-h-64 list-none overflow-y-auto border-2 border-line bg-canvas p-0"></ul>
                            </div>
                            <p id="calendar-venue-help" class="m-0 text-sm text-ink-muted">{{ __('subscriptions.venue_search_help') }}</p>
                            <p data-venue-status role="status" class="m-0 text-sm text-ink-muted"></p>
                            <x-button type="button" variant="ghost" size="sm" class="self-start" data-venue-clear hidden>{{ __('subscriptions.venue_clear') }}</x-button>
                        </div>
                    </div>

                    <label class="flex min-h-12 items-center gap-3 text-ink"><input type="checkbox" name="free" value="1" @checked($selection['free'] ?? false) class="size-5 shrink-0 accent-accent">{{ __('subscriptions.free') }}</label>
                </x-card>

                <x-card tone="muted" :title="__('subscriptions.continuous_title')" id="calendar-sync-heading">
                    <p class="m-0 text-sm text-ink-muted">{{ __('subscriptions.continuous_help') }}</p>
                    <p class="m-0 text-sm text-ink-muted">{{ __('subscriptions.continuous_timing') }}</p>
                </x-card>
                <input type="hidden" name="step" value="3">
                <x-form-actions>
                    <x-button type="submit">{{ __('subscriptions.next') }}<x-lucide name="arrow-right" class="size-4" /></x-button>
                    <x-button variant="secondary" :href="route('feeds.wizard', [...$selection, 'step' => 1])"><x-lucide name="arrow-left" class="size-4" />{{ __('subscriptions.back') }}</x-button>
                </x-form-actions>
            @else
                <x-card :title="__('subscriptions.ready')" tone="accent" :description="$preview->isEmpty() ? __('subscriptions.empty') : __('subscriptions.count', ['count' => $preview->count()])">
                    @php($chosen = $categories->whereIn('slug', $selection['categories'] ?? []))
                    @if ($chosen->isNotEmpty())
                        <div class="flex flex-wrap gap-2">
                            @foreach ($chosen as $category)<x-badge tone="neutral">{{ $category->name }}</x-badge>@endforeach
                        </div>
                    @endif
                    <p class="m-0 text-sm text-ink-muted">{{ __('subscriptions.limit', ['count' => config('feeds.max_items')]) }}</p>
                    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        <x-button :block="true" :href="route('google-calendar.index', array_diff_key($selection, ['step' => true]))">{{ __('google_calendar.manage') }}</x-button>
                        <x-button :block="true" :href="$calendarUrl" variant="secondary"><x-lucide name="download" class="size-4" />{{ auth()->check() ? __('subscriptions.download') : __('google_calendar.download_login') }}</x-button>
                    </div>
                    <p class="m-0 text-sm text-ink-muted">{{ __('google_calendar.download_help') }}</p>
                </x-card>
                <x-form-actions>
                    <x-button variant="secondary" :href="route('feeds.wizard', [...$selection, 'step' => 2])"><x-lucide name="arrow-left" class="size-4" />{{ __('subscriptions.back') }}</x-button>
                </x-form-actions>
            @endif
        </form>

        <x-card :title="__('subscriptions.alerts')" :description="__('subscriptions.alerts_help')" tone="muted">
            <div><x-button variant="secondary" :href="route('account.notifications')">{{ __('subscriptions.notifications') }}</x-button></div>
        </x-card>
    </x-service-page>
</x-layouts.app>
