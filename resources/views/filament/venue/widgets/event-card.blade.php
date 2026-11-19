{{--
    La card di un evento nei pannelli: locandina 48×64 (o il segnaposto),
    titolo, righe con icona (data, luogo, prezzo, visite), distintivi e, sotto,
    i collegamenti alle pagine dell'evento. La usano i riquadri del riepilogo
    e l'anteprima del modulo rapido. Ogni colore ha la sua variante scura.

    Variabili: $poster (URL), $title, $lines (list<[icona, testo]>),
    $badges (list<[testo, colore]>), $href (facoltativo: il titolo diventa un
    collegamento), $links (facoltativo: quelli di `EventResource::eventLinks`),
    $preview (facoltativo: la card d'anteprima, con un fondo che la distingue).
--}}
@php
    $href ??= null;
    $links ??= [];
    $badges ??= [];
    $lines ??= [];
    $preview ??= false;
@endphp
<article @class([
    'flex min-w-0 flex-col gap-3',
    'rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 dark:border-white/15 dark:bg-white/5' => $preview,
]) data-panel-event-card @if ($preview) data-event-preview @endif>
    <div class="flex min-w-0 items-start gap-3">
        <img src="{{ $poster }}" alt="" width="48" height="64" loading="lazy" decoding="async"
            class="h-16 w-12 shrink-0 rounded-md bg-gray-100 object-cover ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" data-panel-event-poster>
        <div class="flex min-w-0 flex-1 flex-col gap-1">
            @if ($href)
                <a href="{{ $href }}" class="text-sm font-semibold text-gray-950 [overflow-wrap:anywhere] hover:underline focus-visible:underline dark:text-white">{{ $title }}</a>
            @else
                <p class="text-sm font-semibold text-gray-950 [overflow-wrap:anywhere] dark:text-white">{{ $title }}</p>
            @endif
            @foreach ($lines as [$icon, $text])
                <p class="flex min-w-0 items-start gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                    <x-filament::icon :icon="$icon" class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-500" />
                    <span class="min-w-0 [overflow-wrap:anywhere]">{{ $text }}</span>
                </p>
            @endforeach
            @if ($badges !== [])
                <div class="flex flex-wrap gap-1.5 pt-1">
                    @foreach ($badges as [$label, $color])
                        <x-filament::badge :color="$color">{{ $label }}</x-filament::badge>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    @if ($links !== [])
        <div class="flex flex-wrap gap-2" data-panel-event-links>
            @foreach ($links as $link)
                <x-filament::button tag="a" size="sm" :href="$link['url']" :icon="$link['icon']" :color="$link['key'] === 'edit' ? 'primary' : 'gray'" :outlined="$link['key'] !== 'edit'"
                    :target="$link['external'] ? '_blank' : null" :rel="$link['external'] ? 'noopener' : null" :data-event-link="$link['key']">{{ $link['label'] }}</x-filament::button>
            @endforeach
        </div>
    @endif
</article>
