{{--
    "Sottoscrivi questa lista" (§11.10).

    Il calendario e l'RSS portano **gli stessi filtri** della pagina da cui si
    parte: chi sta guardando "musica dal vivo gratis" si porta a casa quella
    lista, non il catalogo intero. È ciò che rende il feed una leva di crescita
    e non un indirizzo nascosto nel piè di pagina: si sottoscrive ciò che si
    stava già guardando.

    Sono due link normali: nessun JavaScript, nessun pulsante che copia negli
    appunti e non dice dove.
--}}
@props(['filters'])

@php
    $parameters = $filters->toQueryString();
@endphp

<section {{ $attributes->class(['bg-surface p-4 border-2 border-line']) }} aria-labelledby="sottoscrivi">
    <h2 id="sottoscrivi" class="text-card text-ink">{{ __('feeds.subscribe') }}</h2>
    <p class="mt-1 max-w-prose text-sm text-ink-muted">{{ __('feeds.subscribe_lead') }}</p>

    <div class="mt-3 flex flex-wrap gap-2">
        <a
            href="{{ route('feeds.calendar', $parameters) }}"
            class="bg-surface px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase border-2 border-line transition hover:border-accent"
        >
            {{ __('feeds.calendar.title') }}
        </a>

        <a
            href="{{ route('feeds.rss', $parameters) }}"
            class="bg-surface px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase border-2 border-line transition hover:border-accent"
        >
            {{ __('feeds.rss.title') }}
        </a>
    </div>
</section>
