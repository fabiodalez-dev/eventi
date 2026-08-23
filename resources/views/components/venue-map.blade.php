{{--
    La mappa di un locale e i collegamenti per arrivarci (§11.5, §11.9).

    Il riquadro è l'`iframe` di OpenStreetMap: nessuno script di terze parti,
    nessuna chiave di servizio, e l'attribuzione ODbL è già nel piè di pagina.
    Carica in differita perché sta in fondo alla scheda e non serve a chi non
    scorre fin lì.

    Le indicazioni sono due, non una: su iPhone il link di Google apre il
    browser, su Android quello di Apple non apre nulla.
--}}
@props([
    'venue',
    'title' => null,
])

@php
    $embed = \App\Support\MapLinks::embed($venue);
@endphp

<div {{ $attributes->class(['flex flex-col gap-3']) }}>
    <div class="overflow-hidden rounded-card ring-1 ring-line">
        <iframe
            src="{{ $embed }}"
            title="{{ $title ?? __('venues.detail.map_title', ['venue' => $venue->name]) }}"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            class="aspect-[16/10] w-full border-0"
        ></iframe>
    </div>

    <div class="flex flex-wrap gap-2">
        <a
            href="{{ \App\Support\MapLinks::google($venue) }}"
            rel="noopener noreferrer"
            target="_blank"
            class="rounded-pill bg-brand px-3.5 py-1.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong"
        >
            {{ __('common.actions.directions_google') }}
        </a>

        <a
            href="{{ \App\Support\MapLinks::apple($venue) }}"
            rel="noopener noreferrer"
            target="_blank"
            class="rounded-pill bg-surface px-3.5 py-1.5 text-sm font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
        >
            {{ __('common.actions.directions_apple') }}
        </a>

        <a
            href="{{ \App\Support\MapLinks::openStreetMap($venue) }}"
            rel="noopener noreferrer"
            target="_blank"
            class="rounded-pill bg-surface px-3.5 py-1.5 text-sm font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
        >
            {{ __('common.actions.directions_osm') }}
        </a>
    </div>
</div>
