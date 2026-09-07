{{--
    Il nastro scorrevole della testata (D46).

    Nel riferimento è testo fisso. Qui dice cose vere e verificabili — quante
    date ci sono stasera, quante sono gratuite, quante ne sono state aggiunte
    oggi — perché un nastro che scorre senza dire niente è solo rumore che si
    muove.

    **Numeri, non titoli.** Per un po' il nastro elencava anche le prime tre
    date della sera, col nome dell'evento. Sembrava più vivo, e invece rompeva
    una garanzia della lista: la testata sta su OGNI pagina, quindi quei titoli
    comparivano anche in un elenco filtrato che quegli eventi li escludeva —
    chi cerca «musica dal vivo» vedeva scorrere in alto uno spettacolo di
    prosa. Un conteggio è contesto e vale ovunque; un titolo è un risultato, e
    i risultati stanno nella pagina che li ha chiesti.

    **Perché è dentro la cache.** Sta su ogni pagina del sito, comprese quelle
    che non interrogano mai le occorrenze: senza cache aggiungerebbe tre query
    a ogni richiesta. Un minuto è la stessa finestra della sezione «in corso
    adesso» (D40): l'informazione più fresca che il nastro può portare è
    comunque a grana di serata.
--}}
@php
    $currentCity = app(\App\Support\CurrentCity::class);
    $city = $currentCity->get();

    $voci = $city === null ? [] : cache()->remember(
        'ticker:'.$city->getKey().':'.now($currentCity->timezone())->format('Y-m-d-H-i'),
        now()->addMinute(),
        function () use ($city, $currentCity): array {
            $voci = [];

            $stasera = \App\Queries\EventOccurrenceQuery::for($city)->tonight()->count();

            if ($stasera > 0) {
                $voci[] = trans_choice('ui.ticker.tonight', $stasera, ['count' => $stasera]);
            }

            $gratis = \App\Queries\EventOccurrenceQuery::for($city)->today()->priceFree()->count();

            if ($gratis > 0) {
                $voci[] = trans_choice('ui.ticker.free_today', $gratis, ['count' => $gratis]);
            }

            $nuovi = \App\Queries\EventOccurrenceQuery::for($city)
                ->upcoming()
                ->updatedSince(now($currentCity->timezone())->startOfDay())
                ->count();

            if ($nuovi > 0) {
                $voci[] = trans_choice('ui.ticker.added_today', $nuovi, ['count' => $nuovi]);
            }

            return $voci;
        },
    );
@endphp

@if ($voci !== [])
    <div data-ticker class="flex h-8 items-center overflow-hidden border-t-2 border-canvas bg-accent">
        {{-- JavaScript misura una copia e ne aggiunge quante ne servono per
             coprire la finestra anche alla fine del ciclo. Senza JS resta
             leggibile e ferma. Solo la prima copia è accessibile. --}}
        <div data-ticker-track class="flex flex-none [animation-play-state:var(--anim-play)] will-change-transform">
            @foreach ([false, true] as $copia)
                <div class="flex flex-none items-center gap-[34px] pr-[34px]" @if ($copia) aria-hidden="true" @endif>
                    @foreach ($voci as $voce)
                        <span class="font-display text-[0.656rem] leading-none font-extrabold tracking-[0.16em] whitespace-nowrap text-on-accent uppercase">{{ $voce }}</span>
                        <span aria-hidden="true" class="text-on-accent">&bull;</span>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
@endif
