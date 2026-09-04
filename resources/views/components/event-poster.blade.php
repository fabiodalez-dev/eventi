{{--
    La locandina di un evento, intera, che si apre a schermo pieno.

    **Perché intera e non ritagliata.** In cima alla pagina la locandina fa da
    sfondo: ritagliata, coperta da un gradiente e in bianco e nero, perché lì
    serve a dare un tono, non a essere letta. Ma una locandina è un manifesto —
    qualcuno l'ha disegnata, ci ha messo il nome degli ospiti, l'orario, il
    prezzo, a volte l'unica informazione che da nessun'altra parte esiste. Chi
    la vuole leggere deve poterla vedere per intero.

    **A colori, qui e nel dialogo.** Il resto del sito passa le fotografie in
    bianco e nero, e in cima a questa pagina la locandina fa lo stesso: lì è
    una texture sotto il titolo. Ma qui è la locandina, non una fotografia di
    corredo — qualcuno l'ha disegnata scegliendo quei colori, e sono parte di
    ciò che dice. Toglierli sarebbe applicare una regola oltre il punto in cui
    serve.

    **Un `<dialog>` e non un riquadro fatto a mano.** Porta con sé il fondo
    oscurato, la chiusura con Esc, il fuoco intrappolato dentro e il ritorno
    del fuoco al pulsante che l'ha aperto: tutte cose che un `<div>` con
    `position: fixed` va poi a rifare peggio.
--}}
@props(['event', 'set'])

@php
    $titolo = __('events.card.poster_alt', ['title' => $event->title]);
    $id = 'locandina-'.$event->getKey();
@endphp

<figure class="flex flex-col gap-2">
    <button
        type="button"
        data-apre-dialogo="{{ $id }}"
        class="group relative block w-full overflow-hidden border-2 border-line bg-canvas transition-colors hover:border-accent"
        aria-label="{{ __('events.poster.open') }}"
    >
        <x-media-image
            :set="$set"
            :alt="$titolo"
            width="800"
            height="1131"
            sizes="(min-width: 1024px) 22rem, 100vw"
            :color="true"
            class="w-full"
        />

        {{-- L'invito compare al passaggio, ma su un telefono il passaggio non
             esiste: resta sempre visibile sotto il tocco, e su schermo grande
             si accende quando serve. --}}
        <span
            aria-hidden="true"
            class="absolute right-0 bottom-0 bg-accent px-3 py-2 font-display text-[0.594rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-opacity md:opacity-0 md:group-hover:opacity-100 md:group-focus-visible:opacity-100"
        >
            {{ __('events.poster.open') }}
        </span>
    </button>

    <figcaption class="text-xs text-ink-subtle">{{ __('events.poster.caption') }}</figcaption>
</figure>

<dialog
    id="{{ $id }}"
    class="max-h-[100dvh] max-w-[100vw] bg-transparent p-0 backdrop:bg-[rgba(11,11,11,0.92)]"
    aria-label="{{ $titolo }}"
>
    <div class="flex max-h-[100dvh] flex-col items-center justify-center gap-3 p-4">
        {{-- Senza ritaglio: `object-contain` e non `cover`, perché una
             locandina tagliata è una locandina non letta. --}}
        {{-- Larghezza e altezza dichiarate anche qui, dove sembrano
             superflue: il dialogo si apre su un'immagine non ancora scaricata,
             e senza misure il riquadro cresce di colpo sotto gli occhi. È la
             stessa regola che vale per tutte le immagini del sito (§11.11), e
             un test la fa rispettare su ognuna. --}}
        <img
            src="{{ $set->src }}"
            alt="{{ $titolo }}"
            width="{{ $set->width ?? 800 }}"
            height="{{ $set->height ?? 1131 }}"
            class="max-h-[calc(100dvh-6rem)] w-auto max-w-full object-contain"
        />

        <button
            type="button"
            data-chiude-dialogo
            class="bg-accent px-4 py-2.5 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-on-accent uppercase transition-colors hover:bg-brand-strong"
        >
            {{ __('common.actions.close') }}
        </button>
    </div>
</dialog>
