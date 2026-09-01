{{--
    Una card sponsorizzata: la stessa `<x-event-card>` di tutto il sito, dentro
    una cornice che dichiara cos'è.

    **L'etichetta non è una decorazione ed è per questo che non si può
    spegnere.** La pubblicità dev'essere riconoscibile come tale — è il Codice
    del Consumo (art. 22-23), non una linea guida editoriale — e chi paga per
    stare in cima all'elenco non compra il diritto di sembrare una scelta della
    redazione. Dice anche **chi** sponsorizza: «sponsorizzato» senza un nome è
    mezza informazione, e il nome è spesso la parte che conta (un'etichetta
    discografica che promuove un concerto in un circolo che non è suo).

    **La card resta la card.** Non è più grande, non ha un colore suo, non
    pulsa. Si distingue perché sta in cima e perché lo dichiara, non perché
    grida più forte: un elenco in cui la pubblicità urla è un elenco che si
    smette di leggere.

    Le misure — quante volte è stata vista, quante aperta — le raccoglie lo
    script, e sono una stima al ribasso: vedi `SponsorshipMetricController`.
--}}
@props([
    'sponsorship',
    'occurrence' => null,
    'context' => 'upcoming',
    'index' => null,
    'level' => 'h3',
])

@php
    /* La data da mostrare: la prossima in programma di quell'evento. Se la
       campagna è viva ma l'evento non ha più date future non si disegna
       niente — meglio uno slot vuoto che una pubblicità per una serata già
       passata. */
    $data = $occurrence ?? $sponsorship->event?->occurrences
        ->filter(fn ($o) => $o->starts_at?->isFuture() ?? false)
        ->sortBy('starts_at')
        ->first();
@endphp

@if ($data !== null)
    <div
        {{ $attributes->class(['relative flex flex-col']) }}
        data-sponsorship="{{ $sponsorship->getKey() }}"
        data-sponsorship-impression="{{ route('sponsorships.metric', ['sponsorship' => $sponsorship, 'metric' => 'impressions']) }}"
        data-sponsorship-click="{{ route('sponsorships.metric', ['sponsorship' => $sponsorship, 'metric' => 'clicks']) }}"
    >
        <p class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 border-b-2 border-accent bg-accent px-[26px] py-1.5 font-display text-[0.594rem] leading-none font-extrabold tracking-[0.16em] text-on-accent uppercase">
            <span>{{ __('sponsorships.label') }}</span>
            <span class="font-normal tracking-[0.1em] normal-case opacity-80">
                {{ __('sponsorships.by', ['advertiser' => $sponsorship->advertiser_name]) }}
            </span>
        </p>

        <x-event-card
            :occurrence="$data"
            :context="$context"
            :index="$index"
            :level="$level"
            rel="sponsored"
            class="flex-auto"
        />
    </div>
@endif
