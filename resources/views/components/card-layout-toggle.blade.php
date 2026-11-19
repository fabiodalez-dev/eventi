{{--
    La scelta della vista degli elenchi sul telefono: card a tutta riga
    (`full`, predefinita), griglia a due colonne (`grid`), riga compatta con
    la locandina a sinistra (`list`).

    **La scelta resta nel browser** (`localStorage['incitta:card-layout']`) e
    la applica lo script in testa al layout prima del primo disegno, come il
    tema: l'HTML è uguale per tutti e può stare nella cache di pagina. Per lo
    stesso motivo qui il server segna sempre `full`: lo stato vero lo
    allinea `resources/js/card-layout.js` appena parte, e fino ad allora la
    voce scelta si vede già evidenziata dal CSS (`pages/home.css`).

    È un gruppo di tre pulsanti radio (`role="radiogroup"`, `aria-checked`,
    frecce per spostarsi): si sceglie una vista sola fra tre. Si vede solo
    sotto i 640 px, dove le card occupano tutta la riga, e solo con
    JavaScript (`scripting`): senza, sarebbe un comando che non comanda niente.

    Una volta per pagina: lo stampa la prima `<x-event-grid>`, oppure la
    pagina stessa dove vuole lei; le chiamate successive non stampano niente.
--}}
@props([
    /* Stampa anche se un frammento precedente ne ha già stampato uno. Lo usa la
       pagina iniziale: «In corso adesso» sta in un frammento che la cache di
       pagina ritaglia e ridisegna a ogni risposta, e il selettore che avesse
       stampato lui non resterebbe nella copia salvata. I doppioni li toglie
       lo script, tenendo il primo. */
    'force' => false,
])

@php
    $alreadyPrinted = request()->attributes->get('card_layout_toggle') === true;
    request()->attributes->set('card_layout_toggle', true);

    $options = [
        'full' => [
            'label' => __('ui.card_layout.full'),
            'icon' => '<rect width="16" height="18" x="4" y="3" rx="2"/><path d="M4 14h16"/><path d="M8 17.5h5"/>',
        ],
        'grid' => [
            'label' => __('ui.card_layout.grid'),
            'icon' => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        ],
        'list' => [
            'label' => __('ui.card_layout.list'),
            'icon' => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/><path d="M14 4h7"/><path d="M14 9h7"/><path d="M14 15h7"/><path d="M14 20h7"/>',
        ],
    ];
@endphp

@if ($force || ! $alreadyPrinted)
    <div {{ $attributes->class(['card-layout-toggle items-center justify-end gap-3']) }} data-card-layout-toggle>
        <span class="card-layout-toggle__label text-xs font-semibold text-ink-muted" id="{{ $labelId = 'vista-elenchi-'.\Illuminate\Support\Str::random(6) }}">{{ __('ui.card_layout.label') }}</span>

        <div role="radiogroup" aria-labelledby="{{ $labelId }}" class="card-layout-toggle__group inline-flex">
            @foreach ($options as $value => $option)
                <button
                    type="button"
                    role="radio"
                    data-card-layout-option="{{ $value }}"
                    aria-checked="{{ $value === 'full' ? 'true' : 'false' }}"
                    tabindex="{{ $value === 'full' ? '0' : '-1' }}"
                    aria-label="{{ $option['label'] }}"
                    title="{{ $option['label'] }}"
                    class="card-layout-toggle__option grid size-11 place-items-center"
                >
                    <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $option['icon'] !!}</svg>
                </button>
            @endforeach
        </div>
    </div>
@endif
