{{--
    Il prossimo evento, a destra del calendario della pagina iniziale (sotto,
    da telefono), e sotto di lui «a seguire»: le date che vengono dopo. Per chi
    è collegato segue i suoi interessi; altrimenti è il prossimo della città,
    sempre senza le categorie e i locali nascosti.
--}}
@php
    $formatter = app(\App\Support\DateFormatter::class);
    $event = $occurrence->event;
    $venue = $occurrence->locationVenue();
    $url = \App\Support\EventUrl::occurrence($occurrence);
    $when = $soon
        ? __('events.badge.starting_soon', ['countdown' => $formatter->countdown($occurrence->starts_at)])
        : $formatter->dayAndTime($occurrence->business_date, $occurrence->starts_at);
@endphp
<aside class="home-next flex flex-col gap-5" aria-labelledby="home-next-title" data-home-next data-home-next-mode="{{ $personal ? 'personal' : 'city' }}">
    <div class="flex flex-col gap-2">
        <span class="flex items-center gap-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-accent uppercase">
            <span aria-hidden="true" class="size-[7px] shrink-0 bg-accent {{ $soon ? 'rounded-full blink-dot' : '' }}"></span>
            {{ __('calendar.home.next.eyebrow.'.($personal ? 'personal' : 'city')) }}
        </span>
        <h2 id="home-next-title" class="m-0 font-display text-[clamp(1.625rem,3vw,2.5rem)] leading-[0.94] font-extrabold tracking-[-0.04em] uppercase">
            {{ __('calendar.home.next.title.'.($soon ? 'soon' : 'next')) }}
        </h2>
    </div>

    {{-- Il primo: locandina a sinistra, tutto quello che serve per decidere a destra. --}}
    <article class="home-next__lead group relative grid grid-cols-[clamp(6.5rem,11vw,9.5rem)_minmax(0,1fr)] gap-4 border-2 border-line bg-surface p-3 transition-colors hover:border-accent">
        <div class="home-next__poster self-start">
            <x-event-artwork :event="$event" />
        </div>
        <div class="flex min-w-0 flex-col gap-2 py-1">
            @if ($event->category)
                <span class="self-start border-2 border-line px-2 py-[5px] font-display text-[0.594rem] leading-none font-extrabold tracking-[0.14em] text-ink-muted uppercase">{{ $event->category->name }}</span>
            @endif
            <h3 class="m-0 font-display text-[clamp(1.188rem,1.7vw,1.625rem)] leading-[1.03] font-extrabold tracking-[-0.025em] text-balance uppercase">
                <a href="{{ $url }}" class="after:absolute after:inset-0 after:content-[''] hover:text-accent">{{ $event->title }}</a>
            </h3>
            @if ($venue)
                <p class="m-0 text-sm text-ink-subtle">{{ $venue->name }}</p>
            @endif
            <p class="m-0 font-display text-[0.75rem] leading-[1.3] font-extrabold tracking-[0.1em] uppercase {{ $soon ? 'text-accent' : 'text-ink' }}">
                <time datetime="{{ $formatter->iso($occurrence->starts_at) }}">{{ $when }}</time>
            </p>
            <span class="mt-auto inline-flex items-center gap-2 self-start pt-1 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] uppercase text-accent">
                {{ __('calendar.home.next.open') }}
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="square"><path d="M5 12h14M13 5l7 7-7 7"></path></svg>
            </span>
        </div>
    </article>

    @if ($following->isNotEmpty())
        <div class="flex flex-col gap-3">
            <h3 class="m-0 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.18em] text-ink-muted uppercase">{{ __('calendar.home.next.following') }}</h3>
            <ul class="m-0 flex list-none flex-col gap-3 p-0">
                @foreach ($following as $dopo)
                    <li><x-service-event-row :occurrence="$dopo" size="sm" /></li>
                @endforeach
            </ul>
        </div>
    @endif
</aside>
