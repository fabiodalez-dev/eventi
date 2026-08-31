{{--
    I link esterni di un evento (§7.6, §11.5).

    **Ogni link esce con `rel="nofollow noopener noreferrer"` e `target="_blank"`.**
    Non è una decorazione: sono indirizzi scritti da terzi — la redazione, un
    gestore, domani un import — e senza `nofollow` la scheda regalerebbe
    autorità a chiunque incolli un indirizzo; senza `noopener` la pagina
    aperta potrebbe riscrivere la finestra che l'ha aperta con
    `window.opener`, che è il modo più economico per rifare la nostra pagina
    di accesso altrove.

    Se non ci sono link **non si rende niente**: nessun titolo, nessun
    contenitore vuoto (§8.6).
--}}
@props(['links'])

@php
    $items = \App\DTOs\ExternalLinkList::fromMixed($links);
@endphp

@if ($items->isNotEmpty())
    <section class="flex flex-col gap-3 rounded-card bg-surface p-5 ring-1 ring-line" aria-labelledby="link-evento">
        <h2 id="link-evento" class="text-section text-ink">{{ __('events.detail.external_links') }}</h2>

        <ul class="flex flex-col gap-2">
            @foreach ($items as $link)
                <li>
                    <a
                        href="{{ $link->url }}"
                        rel="nofollow noopener noreferrer"
                        target="_blank"
                        class="text-sm font-semibold text-brand hover:underline"
                    >
                        {{ $link->label }}
                        <span class="sr-only">({{ __('events.external_links.new_tab') }})</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
