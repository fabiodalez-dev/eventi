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

<div {{ $attributes->class(['flex flex-wrap items-center gap-2']) }}>
    <button
        type="button"
        class="hidden rounded-pill bg-surface px-3.5 py-1.5 text-sm font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
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
        class="rounded-pill bg-surface px-3.5 py-1.5 text-sm font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
    >
        {{ __('common.share.whatsapp') }}
    </a>

    <a
        href="https://t.me/share/url?{{ http_build_query(['url' => $url, 'text' => $message]) }}"
        rel="noopener noreferrer"
        target="_blank"
        class="rounded-pill bg-surface px-3.5 py-1.5 text-sm font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
    >
        {{ __('common.share.telegram') }}
    </a>

    <a
        href="mailto:?{{ http_build_query(['subject' => $title, 'body' => $message."\n\n".$url]) }}"
        class="rounded-pill bg-surface px-3.5 py-1.5 text-sm font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
    >
        {{ __('common.share.email') }}
    </a>
</div>
