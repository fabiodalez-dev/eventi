{{--
    La scheda di un locale (§11.9): chi è, dove sta, quando è aperto, che cosa
    ci succede adesso e che cosa ci è successo.

    L'archivio degli eventi passati ha una paginazione propria (`?archivio=2`):
    è la parte che rende la pagina interessante per un motore di ricerca e non
    deve rubare il posto ai prossimi appuntamenti.
--}}
@php
    $formatter = app(\App\Support\DateFormatter::class);
    $logo = \App\Support\Media\ImageSet::forCollection($venue, 'logo');
    $cover = \App\Support\Media\ImageSet::forCollection($venue, 'cover')?->withSizes('(min-width: 1280px) 1200px, 100vw');
    $socials = is_array($venue->socials) ? $venue->socials : [];
    $accessibility = $venue->accessibility;
    $hours = is_array($venue->opening_hours) ? $venue->opening_hours : [];
    $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
@endphp

<x-layouts.app :meta="app(\App\Services\Seo\EditorialContent::class)->meta($venue, $meta)" :preload="$cover">
    <x-slot:head>
        <x-json-ld :data="$structuredData" />

        {{-- **Senza questo la mappa non si accende.**

             Il riquadro MapLibre è nel markup — `x-venue-map` lo disegna — ma
             chi lo anima è `map.js`, e qui non c'era: restava un rettangolo
             vuoto con la sua frase, senza un errore in console e senza niente
             che dicesse cosa mancava. È il modo peggiore di rompersi, perché
             sembra un problema di dati o di rete.

             Lo stesso `@vite` sta in home, nella mappa, nella lista eventi e
             nella scheda evento. Questa pagina era l'unica ad averne bisogno e
             a non averlo. --}}
        @vite('resources/js/map.js')
    </x-slot:head>

    <nav aria-label="{{ __('ui.breadcrumb') }}" class="text-sm text-ink-subtle">
        <a class="hover:text-ink" href="{{ route('venues.index') }}">{{ __('venues.title') }}</a>
    </nav>

    @if ($cover !== null)
        <x-media-image
            :set="$cover"
            :alt="__('venues.card.cover_alt', ['venue' => $venue->name])"
            width="1600"
            height="900"
            :sizes="$cover->sizes"
            :eager="true"
            class="mt-4 aspect-[16/9] w-full object-cover "
        />
    @endif

    <header class="mt-6 flex flex-wrap items-start gap-4">
        <span class="flex size-16 shrink-0 items-center justify-center overflow-hidden bg-brand-soft text-lg font-bold text-on-brand-soft border-2 border-line">
            @if ($logo !== null)
                <x-media-image
                    :set="$logo"
                    :alt="__('venues.card.logo_alt', ['venue' => $venue->name])"
                    width="128"
                    height="128"
                    sizes="64px"
                    class="size-full object-cover"
                />
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
                @if (filled($venue->zone))
                    <span aria-hidden="true">{{ __('common.separator') }}</span>
                    <a class="hover:text-ink hover:underline" href="{{ route('events.index', ['zone' => $venue->zone]) }}">{{ $venue->zone }}</a>
                @endif
            </p>

            {{-- «Segui questo locale» (§15.7): alimenta il feed personale, non
                 i promemoria — quelli arrivano per le date che si salvano. --}}
            <x-follow-button
                type="venue"
                :id="$venue->getKey()"
                :following="app(\App\Support\CurrentFollows::class)->has(\App\Enums\FollowableType::Venue, (int) $venue->getKey())"
                :hint="true"
                class="mt-1"
            />

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

                @if ($accessibility->has(\App\Enums\AccessibilityFeature::StepFreeEntrance))
                    <x-badge tone="neutral">{{ __('venues.badge.accessible') }}</x-badge>
                @endif
            </div>
        </div>

        {{-- «Segui» ora segue. Era spento con una nota — «funzionerà quando
             arriveranno gli account» — scritta quando gli account non c'erano;
             nel frattempo sono arrivati, e il feed usa già i follow per
             scegliere cosa mostrare. Mancava solo il gesto per crearne uno. --}}
        <x-follow-button :type="\App\Enums\FollowableType::Venue" :id="$venue->getKey()" />
    </header>

    <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div class="flex flex-col gap-8">
            <x-editorial-content :model="$venue" />
            @if (filled($venue->description))
                <section aria-labelledby="descrizione-locale" class="flex flex-col gap-3">
                    <h2 id="descrizione-locale" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('venues.detail.about') }}</h2>

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
                        class="bg-brand px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-on-brand uppercase transition hover:bg-brand-strong"
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
                            <li class="flex flex-wrap items-baseline justify-between gap-3 bg-canvas px-4 py-3 border-2 border-line">
                                <a class="font-semibold text-ink hover:underline" href="{{ \App\Support\EventUrl::occurrence($occurrence) }}">
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
            <section class="flex flex-col gap-3 bg-canvas p-5 border-2 border-line" aria-labelledby="dove-locale">
                <h2 id="dove-locale" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('venues.detail.address') }}</h2>

                <p class="text-sm text-ink-muted">
                    {{ $venue->address }}@if (filled($venue->address_extra)), {{ $venue->address_extra }}@endif<br>
                    {{ $venue->postal_code }} {{ $venue->municipality }} ({{ $venue->province_code }})
                </p>

                <x-venue-map :venue="$venue" />
            </section>

            @if ($hours !== [])
                <section class="flex flex-col gap-3 bg-canvas p-5 border-2 border-line" aria-labelledby="orari-locale">
                    <h2 id="orari-locale" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('venues.detail.opening_hours') }}</h2>

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

            {{-- «Come arrivare»: sta sul locale perché cambia col luogo e non
                 con la serata, ed è la domanda che si fa dopo aver deciso di
                 andarci. --}}
            <x-transit-guide :transit="$venue->transit" class="bg-canvas p-5 border-2 border-line" />

            <x-accessibility-list :accessibility="$accessibility" class="bg-canvas p-5 border-2 border-line" />

            <x-fact-table
                :facts="$venue->info"
                :heading="__('venues.detail.info')"
                heading-id="info-locale"
                class="bg-canvas p-5 border-2 border-line"
            />

            @if (filled($venue->phone) || filled($venue->email) || filled($venue->website) || $socials !== [])
                <section class="flex flex-col gap-2 bg-canvas p-5 border-2 border-line" aria-labelledby="contatti-locale">
                    <h2 id="contatti-locale" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('venues.detail.contacts') }}</h2>

                    @if (filled($venue->phone))
                        <a class="text-sm text-brand hover:underline" href="tel:{{ $venue->phone }}">{{ $venue->phone }}</a>
                    @endif

                    @if (filled($venue->email))
                        <a class="text-sm text-brand hover:underline" href="mailto:{{ $venue->email }}">{{ $venue->email }}</a>
                    @endif

                    @php $sitoLocale = \App\Support\SafeUrl::href($venue->website); @endphp
                    @if ($sitoLocale !== null)
                        <a class="text-sm text-brand hover:underline" href="{{ $sitoLocale }}" rel="noopener noreferrer" target="_blank">
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
                <section class="flex flex-col gap-2 bg-canvas p-5 border-2 border-line" aria-labelledby="tessera-locale">
                    <h2 id="tessera-locale" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('venues.detail.membership') }}</h2>
                    <p class="text-sm text-ink-muted">{{ $venue->membership_notes }}</p>
                </section>
            @endif

            {{-- Il calendario di questo locale, da sottoscrivere: è la forma
                 più utile del feed di §11.10, e quella per cui un locale ha
                 interesse a tenere aggiornate le proprie date. --}}
            <x-feed-links :filters="$feedFilters" />

            <section class="flex flex-col gap-3" aria-labelledby="condividi-locale">
                <h2 id="condividi-locale" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('common.actions.share') }}</h2>

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
