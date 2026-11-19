{{--
    Le date salvate: lista paginata, calendario del mese oppure le date
    passate. Tre viste della stessa cosa, scelte con la barra segmentata sotto
    il titolo; ogni data ha la sua locandina e il segnalibro per toglierla.
--}}
@php
    $views = [
        ['label' => __('account.saved.list_view'), 'href' => route('account.saved'), 'active' => ! $calendarView && ! $past],
        ['label' => __('account.saved.calendar_view'), 'href' => route('account.saved', ['vista' => 'calendario']), 'active' => $calendarView],
        ['label' => __('account.saved.past_tab'), 'href' => route('account.saved', ['passate' => 1]), 'active' => $past && ! $calendarView],
    ];
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('account.saved.lead')" :back="route('account.profile')" data-saved-page>
        <x-segmented :label="__('account.saved.view')" :items="$views" />

        <x-card :title="__('subscriptions.saved_title')" :description="__('subscriptions.saved_export_help')">
            <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                <x-button :href="route('account.saved.calendar')" :block="true">
                    <x-lucide name="download" class="size-4" />
                    {{ __('subscriptions.saved_export') }}
                </x-button>
                <x-button :href="route('feeds.wizard')" variant="secondary" :block="true">{{ __('subscriptions.create_calendar') }}</x-button>
                <x-button :href="route('account.collections.index')" variant="secondary" :block="true">{{ __('collections.title') }}</x-button>
            </div>
        </x-card>

        @if ($calendarView)
            @php
                $daysByDate = $calendarOccurrences->groupBy(fn ($occurrence) => $occurrence->business_date->format('Y-m-d'));
                $gridStart = $month->startOfWeek();
                $gridDays = collect(range(0, 41))->map(fn (int $offset) => $gridStart->addDays($offset));
                $monthLabel = $month->locale('it')->translatedFormat('F Y');
                $weekdays = __('account.saved.weekdays_short');
                $formatter = app(\App\Support\DateFormatter::class);
            @endphp

            <x-card padding="none" aria-labelledby="saved-calendar-title" data-saved-calendar>
                <div class="flex items-center justify-between gap-3 border-b border-line px-3 py-3 sm:px-4">
                    <x-button size="sm" variant="secondary" :href="route('account.saved', ['vista' => 'calendario', 'mese' => $month->subMonth()->format('Y-m')])" :aria-label="__('account.saved.previous_month')">
                        <x-lucide name="chevron-left" class="size-4" /><span class="hidden sm:inline">{{ __('account.saved.previous_month') }}</span>
                    </x-button>
                    <h2 id="saved-calendar-title" class="m-0 font-display text-lg font-extrabold text-ink">{{ \Illuminate\Support\Str::ucfirst($monthLabel) }}</h2>
                    <x-button size="sm" variant="secondary" :href="route('account.saved', ['vista' => 'calendario', 'mese' => $month->addMonth()->format('Y-m')])" :aria-label="__('account.saved.next_month')">
                        <span class="hidden sm:inline">{{ __('account.saved.next_month') }}</span><x-lucide name="chevron-right" class="size-4" />
                    </x-button>
                </div>

                <div class="grid grid-cols-7 gap-px bg-line" role="grid" aria-label="{{ $monthLabel }}">
                    @foreach ($weekdays as $weekday)
                        <div class="bg-surface px-1 py-2 text-center text-[0.625rem] font-bold tracking-[0.08em] whitespace-nowrap text-ink-muted uppercase sm:text-xs" role="columnheader">{{ $weekday }}</div>
                    @endforeach

                    @foreach ($gridDays as $day)
                        @php
                            $dayKey = $day->format('Y-m-d');
                            $dayItems = $daysByDate->get($dayKey, collect());
                            $inMonth = $day->month === $month->month;
                        @endphp
                        <div
                            @class([
                                'flex min-h-16 min-w-0 flex-col gap-1 bg-canvas p-1 sm:min-h-24 sm:p-1.5',
                                'opacity-40' => ! $inMonth,
                                'ring-2 ring-accent ring-inset' => $day->isToday(),
                            ])
                            role="gridcell"
                            aria-label="{{ $day->locale('it')->translatedFormat('l j F') }}{{ $dayItems->isNotEmpty() ? ', '.trans_choice('account.saved.saved_on_day', $dayItems->count(), ['count' => $dayItems->count()]) : '' }}"
                        >
                            <time datetime="{{ $dayKey }}" @class(['text-xs font-bold', 'text-accent' => $day->isToday(), 'text-ink' => ! $day->isToday()])>{{ $day->day }}</time>
                            @if ($dayItems->isNotEmpty())
                                <a data-calendar-preview="saved-day-{{ $dayKey }}" href="#saved-{{ $dayItems->first()->getKey() }}" aria-haspopup="dialog" aria-label="{{ trans_choice('account.saved.saved_on_day', $dayItems->count(), ['count' => $dayItems->count()]) }}" class="flex min-h-10 w-full items-center justify-center bg-accent text-xs font-extrabold text-on-accent no-underline sm:hidden">
                                    {{ $dayItems->count() }}
                                </a>
                            @endif
                            @foreach ($dayItems->take(2) as $occurrence)
                                <a
                                    href="{{ \App\Support\EventUrl::occurrence($occurrence) }}"
                                    data-calendar-preview="saved-preview-{{ $occurrence->getKey() }}" aria-haspopup="dialog"
                                    class="hidden min-w-0 border-l-2 border-accent pl-1 text-[0.6875rem] leading-tight break-words text-ink-muted no-underline hover:text-accent sm:block"
                                >
                                    {{ $occurrence->is_all_day ? __('events.badge.all_day') : $formatter->time($occurrence->starts_at) }} · {{ $occurrence->event->title }}
                                </a>
                            @endforeach
                            @if ($dayItems->count() > 2)
                                <a href="#saved-{{ $dayItems->get(2)->getKey() }}" class="hidden text-[0.6875rem] font-semibold text-brand no-underline sm:block">{{ __('account.saved.more_on_day', ['count' => $dayItems->count() - 2]) }}</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </x-card>

            @if ($calendarOccurrences->isEmpty())
                <x-empty-state :title="__('account.saved.month_events', ['month' => $monthLabel])" :description="__('account.saved.no_events_in_month')" />
            @else
                <x-card :title="__('account.saved.month_events', ['month' => $monthLabel])" padding="none" data-results>
                    @foreach ($daysByDate as $items)
                        <p class="svc-row m-0 bg-surface-sunken px-5 py-2 text-xs font-bold tracking-[0.08em] text-ink-muted uppercase sm:px-6">
                            {{ $items->first()->business_date->locale('it')->translatedFormat('l j F Y') }}
                        </p>
                        @foreach ($items as $occurrence)
                            <div class="svc-row px-5 py-4 sm:px-6">
                                <x-service-event-row :occurrence="$occurrence" id="saved-{{ $occurrence->getKey() }}" class="scroll-mt-28">
                                    <x-slot:actions>
                                        <x-button size="sm" variant="secondary" :href="$calendar->googleUrl($occurrence)" target="_blank" rel="noopener noreferrer">
                                            <x-lucide name="external-link" class="size-4" />
                                            {{ __('common.actions.google_calendar') }}
                                        </x-button>
                                    </x-slot:actions>
                                </x-service-event-row>
                            </div>
                        @endforeach
                    @endforeach
                </x-card>
            @endif

            @foreach ($daysByDate as $dateKey => $items)
                <x-saved-calendar-preview :id="'saved-day-'.$dateKey" :occurrences="$items" />
                @foreach ($items as $occurrence)
                    <x-saved-calendar-preview :id="'saved-preview-'.$occurrence->getKey()" :occurrences="collect([$occurrence])" />
                @endforeach
            @endforeach
        @elseif ($occurrences->total() > 0)
            <x-card :title="$past ? __('account.saved.past') : trans_choice('account.saved.panel_count', $occurrences->total(), ['count' => $occurrences->total()])" padding="none" data-results>
                @foreach ($occurrences as $occurrence)
                    <div class="svc-row px-5 py-4 sm:px-6">
                        <x-service-event-row :occurrence="$occurrence">
                            @if (isset($overlaps[$occurrence->getKey()]))
                                <span class="font-semibold text-ink" data-saved-overlap>{{ __('account.saved.overlap', ['title' => $overlaps[$occurrence->getKey()]]) }}</span>
                                <x-slot:badge><x-badge tone="alert" size="sm">{{ __('account.save.overlap_label') }}</x-badge></x-slot:badge>
                            @endif
                            <x-slot:actions>
                                <x-save-heart :occurrence="$occurrence" :saved="true" variant="label" />
                                @if (config('community.enabled'))
                                    <x-button size="sm" variant="secondary" :href="route('community.compose', $occurrence->id)">{{ __('community.publish') }}</x-button>
                                @endif
                            </x-slot:actions>
                        </x-service-event-row>
                    </div>
                @endforeach
            </x-card>
            <div data-pagination>
                <x-pagination :paginator="$occurrences" :summary="true" />
            </div>
        @else
            <x-empty-state :title="__('account.saved.empty_title')" :description="__('account.saved.empty_body')">
                <x-button :href="route('events.index')">{{ __('events.title') }}</x-button>
            </x-empty-state>
        @endif
    </x-service-page>
</x-layouts.app>
