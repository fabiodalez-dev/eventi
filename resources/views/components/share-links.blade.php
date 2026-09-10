{{--
    Condivisione (§11.5).

    Sono link, non integrazioni: nessuno script di terze parti, nessun pulsante
    che segue chi legge. Dove esiste, il browser offre il proprio menu di
    sistema (`navigator.share`); dove non esiste restano i tre link, che
    funzionano comunque.
--}}
@props([
    'url',
    'title',
    'text' => null,
])

@php
    $message = $text ?? $title;
@endphp

<div {{ $attributes->class(['share-links grid grid-cols-2 gap-2 sm:grid-cols-4 [&>a]:flex [&>a]:items-center [&>a]:justify-center [&>a]:text-center [&>a]:min-h-12 [&>button]:min-h-12 [&>a]:px-2 [&>button]:px-2 [&:not(:has(button:not(.hidden)))]:grid-cols-3']) }}>
    <button
        type="button"
        class="hidden event-utility-action bg-surface px-3.5 py-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase border-2 border-line transition hover:border-accent"
        data-share
        data-share-url="{{ $url }}"
        data-share-title="{{ $title }}"
        data-share-text="{{ $message }}"
        data-share-copied="{{ __('common.actions.copied') }}"
    >
        {{ __('common.actions.share') }}
    </button>

    <a
        href="https://wa.me/?{{ http_build_query(['text' => $message.' '.$url]) }}"
        rel="noopener noreferrer"
        target="_blank"
        class="event-utility-action bg-surface px-3.5 py-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase border-2 border-line transition hover:border-accent"
    >
        {{ __('common.share.whatsapp') }}
    </a>

    <a
        href="https://t.me/share/url?{{ http_build_query(['url' => $url, 'text' => $message]) }}"
        rel="noopener noreferrer"
        target="_blank"
        class="event-utility-action bg-surface px-3.5 py-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase border-2 border-line transition hover:border-accent"
    >
        {{ __('common.share.telegram') }}
    </a>

    <a
        href="mailto:?{{ http_build_query(['subject' => $title, 'body' => $message."\n\n".$url]) }}"
        class="event-utility-action bg-surface px-3.5 py-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase border-2 border-line transition hover:border-accent"
    >
        {{ __('common.share.email') }}
    </a>
</div>
