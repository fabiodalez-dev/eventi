{{--
    La scheda di un locale (§11.9): chi è, dove sta, quando è aperto, che cosa
    ci succede adesso e che cosa ci è successo.

    L'archivio degli eventi passati ha una paginazione propria (`?archivio=2`):
    è la parte che rende la pagina interessante per un motore di ricerca e non
    deve rubare il posto ai prossimi appuntamenti.
--}}
@php
    $formatter = app(\App\Support\DateFormatter::class);
    $logo = $venue->getFirstMediaUrl('logo');
    $cover = $venue->getFirstMediaUrl('cover');
    $socials = is_array($venue->socials) ? $venue->socials : [];
    $accessibility = is_array($venue->accessibility) ? $venue->accessibility : [];
    $hours = is_array($venue->opening_hours) ? $venue->opening_hours : [];
    $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
@endphp

<x-layouts.app :meta="$meta">
    <x-slot:head>
        <x-json-ld :data="$structuredData" />
    </x-slot:head>

    <nav aria-label="{{ __('ui.breadcrumb') }}" class="text-sm text-ink-subtle">
        <a class="hover:text-ink" href="{{ route('venues.index') }}">{{ __('venues.title') }}</a>
    </nav>

    @if ($cover !== '')
        <img
            src="{{ $cover }}"
            alt="{{ __('venues.card.cover_alt', ['venue' => $venue->name]) }}"
            width="1600"
            height="900"
            fetchpriority="high"
            decoding="async"
            class="mt-4 aspect-[16/9] w-full rounded-card object-cover shadow-card"
        >
    @endif

    <header class="mt-6 flex flex-wrap items-start gap-4">
        <span class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-card bg-brand-soft text-lg font-bold text-on-brand-soft ring-1 ring-line">
            @if ($logo !== '')
                <img
                    src="{{ $logo }}"
                    alt="{{ __('venues.card.logo_alt', ['venue' => $venue->name]) }}"
                    width="128"
                    height="128"
                    decoding="async"
                    class="size-full object-cover"
                >
            @else
                <span aria-hidden="true">{{ \Illuminate\Support\Str::of($venue->name)->squish()->explode(' ')->take(2)->map(fn (string $word): string => \Illuminate\Support\Str::upper(mb_substr($word, 0, 1)))->implode('') }}</span>
            @endif
        </span>

        <div class="flex min-w-0 flex-1 flex-col gap-2">
            <h1 class="text-balance text-hero text-ink">{{ $venue->name }}</h1>

            <p class="flex flex-wrap items-center gap-2 text-sm text-ink-muted">
                <span>{{ $venue->type->label() }}</span>
                <span aria-hidden="true">{{ __('common.separator') }}</span>
                <span>{{ $venue->municipality }}</span>
            </p>

            <div class="flex flex-wrap gap-2">
                @if ($venue->is_verified)
                    <x-badge tone="brand">{{ __('venues.badge.verified') }}</x-badge>
                @endif

                @if ($venue->is_nonprofit)
                    <x-badge tone="free">{{ __('venues.badge.nonprofit') }}</x-badge>
                @endif

                @if ($venue->requires_membership)
                    <x-badge tone="muted">{{ __('venues.badge.membership') }}</x-badge>
                @endif

                @if (($accessibility['wheelchair'] ?? false) === true)
                    <x-badge tone="neutral">{{ __('venues.badge.accessible') }}</x-badge>
                @endif
            </div>
        </div>

        {{-- "Segui" esiste come promessa, non come inganno: finché non ci sono
             gli account non fa nulla, e lo dice invece di fingere. --}}
        <div class="flex flex-col items-end gap-1">
            <button
                type="button"
                disabled
                aria-describedby="segui-nota"
                class="cursor-not-allowed rounded-pill bg-surface px-4 py-2 text-sm font-semibold text-ink-subtle ring-1 ring-line"
            >
                {{ __('common.actions.follow') }}
            </button>

            <span id="segui-nota" class="text-xs text-ink-subtle">{{ __('venues.detail.follow_soon') }}</span>
        </div>
    </header>

    <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div class="flex flex-col gap-8">
            @if (filled($venue->description))
                <section aria-labelledby="descrizione-locale" class="flex flex-col gap-3">
                    <h2 id="descrizione-locale" class="text-section text-ink">{{ __('venues.detail.about') }}</h2>

                    <div class="flex flex-col gap-3 text-ink-muted">
                        @foreach (preg_split('/\R{2,}/', (string) $venue->description) ?: [] as $paragraph)
                            @if (trim($paragraph) !== '')
                                <p>{{ $paragraph }}</p>
                            @endif
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($occurrences->isNotEmpty())
                <section aria-labelledby="prossimi-locale">
                    <x-section-heading
                        id="prossimi-locale"
                        :title="__('venues.detail.upcoming_events')"
                        tone="brand"
                        :href="route('events.index', ['venue' => $venue->slug])"
                    />

                    <x-event-grid :occurrences="$occurrences->take(8)" :show-venue="false" :eager="true" />
                </section>
            @else
                <x-empty-state :title="__('events.empty.venue_title')" :description="__('events.empty.venue_body')">
                    <a
                        href="{{ route('events.index') }}"
                        class="rounded-pill bg-brand px-4 py-2 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
                    >
                        {{ __('events.redirects.to_all') }}
                    </a>
                </x-empty-state>
            @endif

            @if ($archive->total() > 0)
                <section aria-labelledby="archivio-locale">
                    <x-section-heading id="archivio-locale" :title="__('venues.detail.past_events')" tone="neutral" />

                    <ul class="flex flex-col gap-2">
                        @foreach ($archive as $occurrence)
                            <li class="flex flex-wrap items-baseline justify-between gap-3 rounded-card bg-surface px-4 py-3 ring-1 ring-line">
                                <a class="font-semibold text-ink hover:underline" href="{{ route('events.show', $occurrence->event) }}">
                                    {{ $occurrence->event->title }}
                                </a>

                                <time datetime="{{ $formatter->isoDay($occurrence->business_date) }}" class="text-sm text-ink-subtle">
                                    {{ $formatter->weekdayDate($occurrence->business_date) }}
                                </time>
                            </li>
                        @endforeach
                    </ul>

                    <x-pagination :paginator="$archive" />
                </section>
            @endif
        </div>

        <aside class="flex flex-col gap-6">
            <section class="flex flex-col gap-3 rounded-card bg-surface p-5 ring-1 ring-line" aria-labelledby="dove-locale">
                <h2 id="dove-locale" class="text-section text-ink">{{ __('venues.detail.address') }}</h2>

                <p class="text-sm text-ink-muted">
                    {{ $venue->address }}@if (filled($venue->address_extra)), {{ $venue->address_extra }}@endif<br>
                    {{ $venue->postal_code }} {{ $venue->municipality }} ({{ $venue->province_code }})
                </p>

                <x-venue-map :venue="$venue" />
            </section>

            @if ($hours !== [])
                <section class="flex flex-col gap-3 rounded-card bg-surface p-5 ring-1 ring-line" aria-labelledby="orari-locale">
                    <h2 id="orari-locale" class="text-section text-ink">{{ __('venues.detail.opening_hours') }}</h2>

                    <dl class="flex flex-col gap-1 text-sm">
                        @foreach ($days as $day)
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-muted">{{ __('dates.weekdays.'.$day) }}</dt>
                                <dd class="text-ink">
                                    @php $slots = is_array($hours[$day] ?? null) ? $hours[$day] : []; @endphp

                                    @if ($slots === [])
                                        {{ __('venues.detail.closed') }}
                                    @else
                                        {{ collect($slots)->map(fn (array $slot): string => ($slot['open'] ?? '').'–'.($slot['close'] ?? ''))->implode(', ') }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif

            @if (filled($venue->phone) || filled($venue->email) || filled($venue->website) || $socials !== [])
                <section class="flex flex-col gap-2 rounded-card bg-surface p-5 ring-1 ring-line" aria-labelledby="contatti-locale">
                    <h2 id="contatti-locale" class="text-section text-ink">{{ __('venues.detail.contacts') }}</h2>

                    @if (filled($venue->phone))
                        <a class="text-sm text-brand hover:underline" href="tel:{{ $venue->phone }}">{{ $venue->phone }}</a>
                    @endif

                    @if (filled($venue->email))
                        <a class="text-sm text-brand hover:underline" href="mailto:{{ $venue->email }}">{{ $venue->email }}</a>
                    @endif

                    @if (filled($venue->website))
                        <a class="text-sm text-brand hover:underline" href="{{ $venue->website }}" rel="noopener noreferrer" target="_blank">
                            {{ __('common.actions.website') }}
                        </a>
                    @endif

                    @foreach ($socials as $network => $url)
                        @if (is_string($url) && filled($url))
                            <a class="text-sm text-brand hover:underline" href="{{ $url }}" rel="noopener noreferrer" target="_blank">
                                {{ \Illuminate\Support\Str::title((string) $network) }}
                            </a>
                        @endif
                    @endforeach
                </section>
            @endif

            @if ($venue->requires_membership && filled($venue->membership_notes))
                <section class="flex flex-col gap-2 rounded-card bg-surface p-5 ring-1 ring-line" aria-labelledby="tessera-locale">
                    <h2 id="tessera-locale" class="text-section text-ink">{{ __('venues.detail.membership') }}</h2>
                    <p class="text-sm text-ink-muted">{{ $venue->membership_notes }}</p>
                </section>
            @endif

            {{-- Il calendario di questo locale, da sottoscrivere: è la forma
                 più utile del feed di §11.10, e quella per cui un locale ha
                 interesse a tenere aggiornate le proprie date. --}}
            <x-feed-links :filters="$feedFilters" />

            <section class="flex flex-col gap-3" aria-labelledby="condividi-locale">
                <h2 id="condividi-locale" class="text-section text-ink">{{ __('common.actions.share') }}</h2>

                <x-share-links :url="route('venues.show', $venue)" :title="$venue->name" />

                <a
                    href="{{ route('venues.report', ['slug' => $venue->slug]) }}"
                    class="self-start text-sm font-semibold text-ink-muted underline hover:text-ink"
                >
                    {{ __('common.actions.report') }}
                </a>
            </section>
        </aside>
    </div>
</x-layouts.app>
