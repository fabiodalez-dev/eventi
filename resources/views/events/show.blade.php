{{--
    La scheda di un evento (§11.5).

    Un evento non è una data: qui si elencano **tutte** le date future, ognuna
    con i propri orari, il proprio stato e il proprio pulsante per il
    calendario. È l'unica forma onesta per una rassegna di dieci serate.
--}}
@php
    $formatter = app(\App\Support\DateFormatter::class);
    $venue = $event->venue;
    $poster = \App\Support\Poster::imageSet($event)?->withSizes('(min-width: 1024px) 448px, 90vw');
    $custom = is_array($event->custom_location) ? $event->custom_location : [];
    $shareUrl = route('events.show', $event);
    $dates = $occurrences->isNotEmpty() ? $occurrences : $pastOccurrences;
    $shown = $dates->take(config('eventi.dates_shown'));
    $lineups = $dates->flatMap(fn ($occurrence) => $occurrence->lineups)->unique('id');
@endphp

<x-layouts.app :meta="$meta" :preload="$poster">
    <x-slot:head>
        <x-json-ld :data="$structuredData" />
    </x-slot:head>

    <nav aria-label="{{ __('ui.breadcrumb') }}" class="text-sm text-ink-subtle">
        <a class="hover:text-ink" href="{{ route('events.index') }}">{{ __('events.title') }}</a>
        @if ($event->category !== null)
            <span aria-hidden="true">{{ __('common.separator') }}</span>
            <a class="hover:text-ink" href="{{ route('events.category', $event->category) }}">{{ $event->category->name }}</a>
        @endif
    </nav>

    <div class="mt-4 grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <article class="flex flex-col gap-6">
            <div class="flex flex-col gap-3">
                @if ($event->category !== null)
                    <a href="{{ route('events.category', $event->category) }}" class="self-start">
                        <x-badge tone="neutral" :dot="true" :dot-color="$event->category->color">
                            {{ $event->category->name }}
                        </x-badge>
                    </a>
                @endif

                <h1 class="text-balance text-hero text-ink">{{ $event->title }}</h1>

                @if ($event->subtitle)
                    <p class="text-balance text-section text-ink-muted">{{ $event->subtitle }}</p>
                @endif

                <div class="flex flex-wrap items-center gap-2 text-sm text-ink-muted">
                    @if ($venue !== null)
                        <a class="font-semibold text-brand hover:underline" href="{{ route('venues.show', $venue) }}">{{ $venue->name }}</a>
                        <span aria-hidden="true">{{ __('common.separator') }}</span>
                        <span>{{ $venue->municipality }}</span>
                    @elseif (filled($custom['name'] ?? null))
                        <span class="font-semibold text-ink">{{ $custom['name'] }}</span>
                    @endif

                    @if (filled($event->organizer_name))
                        <span aria-hidden="true">{{ __('common.separator') }}</span>
                        <span>{{ __('events.detail.organizer', ['name' => $event->organizer_name]) }}</span>
                    @endif
                </div>
            </div>

            @if ($poster !== null)
                <x-media-image
                    :set="$poster"
                    :alt="__('events.card.poster_alt', ['title' => $event->title])"
                    width="1200"
                    height="1600"
                    :sizes="$poster->sizes"
                    :eager="true"
                    class="w-full max-w-md rounded-card object-cover shadow-card"
                />
            @endif

            @if ($occurrences->isEmpty() && $pastOccurrences->isNotEmpty())
                <p class="rounded-card bg-surface-sunken px-4 py-3 text-sm font-semibold text-ink-muted">
                    {{ __('events.detail.finished') }}
                </p>
            @endif

            @if ($shown->isNotEmpty())
                <section aria-labelledby="date-evento" class="flex flex-col gap-3">
                    <h2 id="date-evento" class="text-section text-ink">{{ __('events.detail.all_dates') }}</h2>

                    <ul class="flex flex-col gap-2">
                        @foreach ($shown as $occurrence)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-card bg-surface px-4 py-3 ring-1 ring-line">
                                <div class="flex min-w-0 flex-col gap-1">
                                    <time
                                        datetime="{{ $occurrence->is_all_day ? $formatter->isoDay($occurrence->business_date) : $formatter->iso($occurrence->starts_at) }}"
                                        class="font-semibold text-ink"
                                    >
                                        {{ $formatter->weekdayDate($occurrence->business_date) }}
                                    </time>

                                    <span class="text-sm text-ink-muted">
                                        @if ($occurrence->is_all_day)
                                            {{ __('events.badge.all_day') }}
                                        @else
                                            {{ $formatter->timeRange($occurrence->starts_at, $occurrence->ends_at) }}
                                            @if ($occurrence->ends_at === null)
                                                <span class="text-ink-subtle">{{ __('common.separator') }} {{ __('events.detail.ends_estimated') }} {{ $formatter->time($occurrence->effective_ends_at) }}</span>
                                            @endif
                                        @endif

                                        @if ($occurrence->doors_at !== null)
                                            <span aria-hidden="true">{{ __('common.separator') }}</span>
                                            {{ __('events.detail.doors_at', ['time' => $formatter->time($occurrence->doors_at)]) }}
                                        @endif
                                    </span>

                                    @if ($occurrence->status !== \App\Enums\OccurrenceStatus::Scheduled)
                                        <span class="text-sm font-semibold text-live">
                                            {{ $occurrence->status->label() }}@if (filled($occurrence->status_note)) <span class="font-normal text-ink-muted">{{ $occurrence->status_note }}</span>@endif
                                        </span>
                                    @endif
                                </div>

                                @if ($occurrences->isNotEmpty())
                                    <div class="flex flex-wrap gap-2">
                                        <a
                                            href="{{ route('events.calendar', ['slug' => $event->slug, 'occurrence' => $occurrence->getKey()]) }}"
                                            class="rounded-pill bg-surface-sunken px-3 py-1.5 text-xs font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
                                        >
                                            {{ __('common.actions.add_to_calendar') }}
                                        </a>

                                        <a
                                            href="{{ $calendar->googleUrl($occurrence) }}"
                                            rel="noopener noreferrer"
                                            target="_blank"
                                            class="rounded-pill bg-surface-sunken px-3 py-1.5 text-xs font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
                                        >
                                            {{ __('common.actions.google_calendar') }}
                                        </a>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($dates->count() > $shown->count())
                        <p class="text-sm text-ink-subtle">
                            {{ trans_choice('events.card.more_dates', $dates->count() - $shown->count(), ['count' => $dates->count() - $shown->count()]) }}
                        </p>
                    @endif
                </section>
            @endif

            {{-- Il cuore della scheda (§15.3): una data sola si salva senza
                 chiedere, più date aprono il selettore, una serie ricorrente
                 offre anche «segui questo evento». --}}
            <x-save-event
                :event="$event"
                :occurrences="$occurrences"
                :saved="app(\App\Support\CurrentSaves::class)->all()"
                :following="app(\App\Support\CurrentFollows::class)->has(\App\Enums\FollowableType::Event, (int) $event->getKey())"
            />

            @if (filled($event->description))
                <section aria-labelledby="descrizione-evento" class="flex flex-col gap-3">
                    <h2 id="descrizione-evento" class="text-section text-ink">{{ __('events.detail.description') }}</h2>

                    <div class="flex flex-col gap-3 text-ink-muted">
                        @foreach (preg_split('/\R{2,}/', (string) $event->description) ?: [] as $paragraph)
                            @if (trim($paragraph) !== '')
                                <p>{{ $paragraph }}</p>
                            @endif
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($lineups->isNotEmpty())
                <section aria-labelledby="lineup-evento" class="flex flex-col gap-3">
                    <h2 id="lineup-evento" class="text-section text-ink">{{ __('events.detail.lineup') }}</h2>

                    <ul class="flex flex-col gap-2">
                        @foreach ($lineups->sortBy('sort_order') as $act)
                            <li class="flex flex-wrap items-center gap-2 text-sm">
                                <span class="font-semibold text-ink">
                                    @if (filled($act->url))
                                        <a class="hover:underline" href="{{ $act->url }}" rel="noopener noreferrer" target="_blank">{{ $act->name }}</a>
                                    @else
                                        {{ $act->name }}
                                    @endif
                                </span>

                                <x-badge tone="neutral" size="sm">{{ $act->role->label() }}</x-badge>

                                @if ($act->starts_at !== null)
                                    <span class="text-ink-subtle">{{ $formatter->time($act->starts_at) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($event->tags->isNotEmpty())
                <section aria-labelledby="tag-evento" class="flex flex-col gap-3">
                    <h2 id="tag-evento" class="text-section text-ink">{{ __('events.detail.tags') }}</h2>

                    <div class="flex flex-wrap gap-2">
                        @foreach ($event->tags as $tag)
                            <a
                                href="{{ route('events.tag', $tag) }}"
                                class="rounded-pill bg-surface px-3 py-1.5 text-sm font-semibold text-ink-muted ring-1 ring-line transition hover:text-ink hover:ring-line-strong"
                            >
                                #{{ $tag->name }}
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        </article>

        <aside class="flex flex-col gap-6">
            <section class="flex flex-col gap-3 rounded-card bg-surface p-5 ring-1 ring-line" aria-labelledby="prezzo-evento">
                <h2 id="prezzo-evento" class="text-section text-ink">{{ __('events.detail.price') }}</h2>

                <p class="text-card text-ink"><x-price-tag :event="$event" as="text" /></p>

                @if (filled($event->price_notes))
                    <p class="text-sm text-ink-muted">{{ $event->price_notes }}</p>
                @endif

                @if ($event->booking_required)
                    <p class="text-sm font-semibold text-ink-muted">{{ __('events.detail.booking_required') }}</p>
                @endif

                <div class="flex flex-col gap-2">
                    @if (filled($event->ticket_url))
                        <a
                            href="{{ $event->ticket_url }}"
                            rel="noopener noreferrer"
                            target="_blank"
                            class="rounded-pill bg-brand px-4 py-2 text-center text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
                        >
                            {{ __('common.actions.buy_tickets') }}
                        </a>
                    @endif

                    @if (filled($event->booking_url))
                        <a
                            href="{{ $event->booking_url }}"
                            rel="noopener noreferrer"
                            target="_blank"
                            class="rounded-pill bg-surface-sunken px-4 py-2 text-center text-sm font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
                        >
                            {{ __('common.actions.book') }}
                        </a>
                    @endif

                    @if (filled($event->booking_phone))
                        <a
                            href="tel:{{ $event->booking_phone }}"
                            class="rounded-pill bg-surface-sunken px-4 py-2 text-center text-sm font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
                        >
                            {{ __('common.actions.call') }} {{ $event->booking_phone }}
                        </a>
                    @endif
                </div>

                <dl class="flex flex-col gap-1 text-sm text-ink-muted">
                    @if (filled($event->age_restriction))
                        <div class="flex gap-2">
                            <dt class="sr-only">{{ __('events.detail.age') }}</dt>
                            <dd>{{ __('events.detail.age_restriction', ['value' => $event->age_restriction]) }}</dd>
                        </div>
                    @endif

                    @if ($event->is_outdoor)
                        <div class="flex gap-2">
                            <dt class="sr-only">{{ __('events.detail.place_kind') }}</dt>
                            <dd>{{ __('events.detail.outdoor') }}</dd>
                        </div>
                    @endif
                </dl>
            </section>

            @if ($venue !== null)
                <section class="flex flex-col gap-3 rounded-card bg-surface p-5 ring-1 ring-line" aria-labelledby="luogo-evento">
                    <h2 id="luogo-evento" class="text-section text-ink">{{ __('events.detail.where') }}</h2>

                    <p class="text-sm text-ink-muted">
                        <a class="font-semibold text-ink hover:underline" href="{{ route('venues.show', $venue) }}">{{ $venue->name }}</a><br>
                        {{ $venue->address }}<br>
                        {{ $venue->postal_code }} {{ $venue->municipality }}
                    </p>

                    <x-venue-map :venue="$venue" />
                </section>
            @endif

            <section class="flex flex-col gap-3" aria-labelledby="condividi-evento">
                <h2 id="condividi-evento" class="text-section text-ink">{{ __('common.actions.share') }}</h2>

                <x-share-links :url="$shareUrl" :title="$event->title" />

                <a
                    href="{{ route('events.report', ['slug' => $event->slug]) }}"
                    class="self-start text-sm font-semibold text-ink-muted underline hover:text-ink"
                >
                    {{ __('common.actions.report') }}
                </a>
            </section>
        </aside>
    </div>

    @if ($atVenue->isNotEmpty() && $venue !== null)
        <section class="mt-section" aria-labelledby="sezione-stesso-locale">
            <x-section-heading
                id="sezione-stesso-locale"
                :title="__('events.sections.same_venue')"
                tone="neutral"
                :href="route('venues.show', $venue)"
            />

            <x-event-grid :occurrences="$atVenue" :show-venue="false" />
        </section>
    @endif

    @if ($related->isNotEmpty())
        <section class="mt-section" aria-labelledby="sezione-simili">
            <x-section-heading
                id="sezione-simili"
                :title="__('events.sections.similar')"
                tone="neutral"
                :href="$event->category !== null ? route('events.category', $event->category) : route('events.index')"
            />

            <x-event-grid :occurrences="$related" />
        </section>
    @endif
</x-layouts.app>
