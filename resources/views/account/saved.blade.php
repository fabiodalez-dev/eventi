{{-- Le date salvate: lista paginata oppure calendario mensile completo. --}}
<x-layouts.app :meta="$meta">
    <header class="flex flex-col gap-2" data-saved-page>
        <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
        <p class="text-sm text-ink-muted">{{ __('account.saved.lead') }}</p>

        <nav data-peek-tabs class="mt-3 flex w-full max-w-md gap-0.5 overflow-x-auto whitespace-nowrap bg-line p-0.5 [&>a]:min-h-12 [&>a]:shrink-0 [&>a]:grow" aria-label="{{ __('account.saved.view') }}">
            <a
                href="{{ route('account.saved') }}"
                @class([
                    'px-4 py-3 text-center font-display text-xs font-extrabold tracking-[0.14em] uppercase',
                    'bg-accent text-on-accent' => ! $calendarView && ! $past,
                    'bg-canvas text-ink' => $calendarView || $past,
                ])
                @if (! $calendarView && ! $past) aria-current="page" @endif
            >{{ __('account.saved.list_view') }}</a>
            <a
                href="{{ route('account.saved', ['vista' => 'calendario']) }}"
                @class([
                    'px-4 py-3 text-center font-display text-xs font-extrabold tracking-[0.14em] uppercase',
                    'bg-accent text-on-accent' => $calendarView,
                    'bg-canvas text-ink' => ! $calendarView,
                ])
                @if ($calendarView) aria-current="page" @endif
            >{{ __('account.saved.calendar_view') }}</a>
            <a href="{{ route('account.saved', ['passate' => 1]) }}" @if ($past && ! $calendarView) aria-current="page" @endif
               class="px-2 py-3 text-center font-display text-xs font-extrabold uppercase {{ $past && ! $calendarView ? 'bg-accent text-on-accent' : 'bg-canvas text-ink' }}">{{ __('account.saved.past_tab') }}</a>
        </nav>
    </header>

    <section class="my-6 border-y-2 border-line py-5" aria-label="{{ __('subscriptions.saved_title') }}">
        <div class="flex flex-wrap gap-3">
            <x-button :href="route('account.saved.calendar')" class="min-h-12">{{ __('subscriptions.saved_export') }}</x-button>
            <x-button :href="route('feeds.wizard')" class="min-h-12">{{ __('subscriptions.create_calendar') }}</x-button>
        </div>
        <p class="mt-3 max-w-prose text-sm text-ink-muted">{{ __('subscriptions.saved_export_help') }}</p>
    </section>

    @if ($calendarView)
        @php
            $daysByDate = $calendarOccurrences->groupBy(fn ($occurrence) => $occurrence->business_date->format('Y-m-d'));
            $gridStart = $month->startOfWeek();
            $gridDays = collect(range(0, 41))->map(fn (int $offset) => $gridStart->addDays($offset));
            $monthLabel = $month->locale('it')->translatedFormat('F Y');
            $weekdays = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];
        @endphp

        <section class="mt-7" aria-labelledby="saved-calendar-title" data-saved-calendar>
            <div class="flex items-center justify-between gap-3 border-y-2 border-line py-3">
                <a
                    href="{{ route('account.saved', ['vista' => 'calendario', 'mese' => $month->subMonth()->format('Y-m')]) }}"
                    class="border-2 border-line px-3 py-2 font-display text-[0.625rem] font-extrabold tracking-[0.12em] uppercase hover:border-accent hover:text-accent"
                    aria-label="{{ __('account.saved.previous_month') }}"
                >← <span class="hidden sm:inline">{{ __('account.saved.previous_month') }}</span></a>

                <h2 id="saved-calendar-title" class="font-display text-xl font-extrabold tracking-[-0.03em] uppercase">
                    {{ $monthLabel }}
                </h2>

                <a
                    href="{{ route('account.saved', ['vista' => 'calendario', 'mese' => $month->addMonth()->format('Y-m')]) }}"
                    class="border-2 border-line px-3 py-2 font-display text-[0.625rem] font-extrabold tracking-[0.12em] uppercase hover:border-accent hover:text-accent"
                    aria-label="{{ __('account.saved.next_month') }}"
                ><span class="hidden sm:inline">{{ __('account.saved.next_month') }}</span> →</a>
            </div>

            <div class="mt-4 grid grid-cols-7 gap-0.5 bg-line p-0.5" role="grid" aria-label="{{ $monthLabel }}">
                @foreach ($weekdays as $weekday)
                    <div class="bg-surface px-1 py-2 text-center font-display text-[0.5625rem] font-extrabold tracking-[0.12em] text-ink-subtle uppercase sm:text-[0.625rem]" role="columnheader">
                        {{ $weekday }}
                    </div>
                @endforeach

                @foreach ($gridDays as $day)
                    @php
                        $dayKey = $day->format('Y-m-d');
                        $dayItems = $daysByDate->get($dayKey, collect());
                        $inMonth = $day->month === $month->month;
                    @endphp
                    <div
                        @class([
                            'min-h-16 bg-canvas p-1.5 sm:min-h-28 sm:p-2',
                            'opacity-35' => ! $inMonth,
                            'ring-2 ring-inset ring-accent' => $day->isToday(),
                        ])
                        role="gridcell"
                        aria-label="{{ $day->locale('it')->translatedFormat('l j F') }}{{ $dayItems->isNotEmpty() ? ', '.trans_choice('account.saved.saved_on_day', $dayItems->count(), ['count' => $dayItems->count()]) : '' }}"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-1">
                            <time datetime="{{ $dayKey }}" class="font-display text-xs font-extrabold {{ $day->isToday() ? 'text-accent' : 'text-ink' }}">
                                {{ $day->day }}
                            </time>
                            @if ($dayItems->isNotEmpty())
                                <a data-calendar-preview="saved-day-{{ $dayKey }}" href="#saved-{{ $dayItems->first()->getKey() }}" aria-haspopup="dialog" aria-label="{{ trans_choice('account.saved.saved_on_day', $dayItems->count(), ['count' => $dayItems->count()]) }}" class="flex min-h-12 w-full items-center justify-center bg-accent font-display text-[0.625rem] font-extrabold text-on-accent">
                                    {{ $dayItems->count() }}
                                </a>
                            @endif
                        </div>

                        @foreach ($dayItems->take(2) as $occurrence)
                            <a
                                href="{{ \App\Support\EventUrl::occurrence($occurrence) }}"
                                data-calendar-preview="saved-preview-{{ $occurrence->getKey() }}" aria-haspopup="dialog"
                                class="mt-1 hidden text-[0.625rem] leading-tight text-ink-muted hover:text-accent sm:block"
                            >
                                {{ $occurrence->is_all_day ? __('events.badge.all_day') : app(\App\Support\DateFormatter::class)->time($occurrence->starts_at) }}
                                · {{ $occurrence->event->title }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </section>

        <section class="mt-8" aria-labelledby="saved-month-events">
            <h2 id="saved-month-events" class="font-display text-xl font-extrabold tracking-[-0.03em] uppercase">
                {{ __('account.saved.month_events', ['month' => $monthLabel]) }}
            </h2>

            @if ($calendarOccurrences->isEmpty())
                <p class="mt-4 border-2 border-line p-5 text-sm text-ink-muted">{{ __('account.saved.no_events_in_month') }}</p>
            @else
                <div class="mt-4 flex flex-col gap-0.5" data-results>
                    @foreach ($daysByDate as $items)
                        <h3 class="bg-accent px-4 py-3 font-display text-sm font-extrabold tracking-[0.12em] text-on-accent uppercase">
                            {{ $items->first()->business_date->locale('it')->translatedFormat('l j F Y') }}
                        </h3>
                        @foreach ($items as $occurrence)
                            <article id="saved-{{ $occurrence->getKey() }}" class="scroll-mt-28 grid gap-3 border-2 border-line bg-canvas p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                                <a href="{{ \App\Support\EventUrl::occurrence($occurrence) }}" data-calendar-preview="saved-preview-{{ $occurrence->getKey() }}" aria-haspopup="dialog" class="group min-w-0">
                                    <p class="font-display text-[0.625rem] font-extrabold tracking-[0.14em] text-accent uppercase">
                                        {{ $occurrence->is_all_day ? __('filters.time_of_day.any') : $occurrence->starts_at->format('H:i') }}
                                    </p>
                                    <h4 class="mt-1 text-card text-ink transition-colors group-hover:text-accent">{{ $occurrence->event->title }}</h4>
                                    @if ($occurrence->effectiveVenue() !== null)
                                        <p class="mt-1 text-sm text-ink-muted">{{ $occurrence->effectiveVenue()->name }}</p>
                                    @endif
                                </a>
                                <a
                                    href="{{ $calendar->googleUrl($occurrence) }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="border-2 border-accent px-4 py-3 text-center font-display text-[0.625rem] font-extrabold tracking-[0.12em] text-ink uppercase transition-colors hover:bg-accent hover:text-on-accent"
                                >{{ __('common.actions.google_calendar') }}</a>
                            </article>
                        @endforeach
                    @endforeach
                </div>
            @endif
        </section>
        @foreach ($daysByDate as $dateKey => $items)
            <x-saved-calendar-preview :id="'saved-day-'.$dateKey" :occurrences="$items" />
            @foreach ($items as $occurrence)
                <x-saved-calendar-preview :id="'saved-preview-'.$occurrence->getKey()" :occurrences="collect([$occurrence])" />
            @endforeach
        @endforeach
    @elseif ($occurrences->total() > 0)
        <div class="mt-6" data-results>
            <x-event-grid :occurrences="$occurrences->getCollection()" :adaptive="false" />
        </div>
        <div class="mt-8" data-pagination>
            <x-pagination :paginator="$occurrences" :summary="true" />
        </div>
    @else
        <x-empty-state class="mt-8" :title="__('account.saved.empty_title')" :description="__('account.saved.empty_body')">
            <a href="{{ route('events.index') }}" class="ui-action bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('events.title') }}
            </a>
        </x-empty-state>
    @endif
</x-layouts.app>
