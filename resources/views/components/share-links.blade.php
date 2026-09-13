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
    'iconsOnly' => false,
])

@php
    $message = $text ?? $title;
@endphp

<div {{ $attributes->class(['share-links', 'share-links--icons flex flex-nowrap gap-2' => $iconsOnly, 'grid grid-cols-2 gap-2 sm:grid-cols-4 [&>a]:flex [&>a]:items-center [&>a]:justify-center [&>a]:text-center [&>a]:min-h-12 [&>button]:min-h-12 [&>a]:px-2 [&>button]:px-2 [&:not(:has(button:not(.hidden)))]:grid-cols-3' => ! $iconsOnly]) }}>
    <button
        type="button"
        class="hidden event-utility-action bg-surface px-3.5 py-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase border-2 border-line transition hover:border-accent"
        title="{{ __('common.actions.share') }}"
        data-share
        data-share-url="{{ $url }}"
        data-share-title="{{ $title }}"
        data-share-text="{{ $message }}"
        data-share-copied="{{ __('common.actions.copied') }}"
    >
        @if ($iconsOnly)
            <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-4m-6.8 7 6.8 4"/></svg>
        @endif
        <span @class(['sr-only' => $iconsOnly]) data-share-label>{{ __('common.actions.share') }}</span>
    </button>

    <a
        title="{{ __('common.share.whatsapp') }}"
        href="https://wa.me/?{{ http_build_query(['text' => $message.' '.$url]) }}"
        rel="noopener noreferrer"
        target="_blank"
        class="event-utility-action bg-surface px-3.5 py-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase border-2 border-line transition hover:border-accent"
    >
        @if ($iconsOnly)
            <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a9 9 0 0 1-13.4 7.9L3 21l1.5-4.5A9 9 0 1 1 21 11.5Z"/><path d="M8 7c-1 1 0 4 2 6s5 3 6 2l1-2-3-1-1 1c-1-0.5-2.5-2-3-3l1-1-1-2Z"/></svg>
        @endif
        <span @class(['sr-only' => $iconsOnly])>{{ __('common.share.whatsapp') }}</span>
    </a>

    <a
        title="{{ __('common.share.telegram') }}"
        href="https://t.me/share/url?{{ http_build_query(['url' => $url, 'text' => $message]) }}"
        rel="noopener noreferrer"
        target="_blank"
        class="event-utility-action bg-surface px-3.5 py-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase border-2 border-line transition hover:border-accent"
    >
        @if ($iconsOnly)
            <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 18-7-4 18-6-5-3 3v-6l10-7-12 6Z"/></svg>
        @endif
        <span @class(['sr-only' => $iconsOnly])>{{ __('common.share.telegram') }}</span>
    </a>

    <a
        title="{{ __('common.share.email') }}"
        href="mailto:?{{ http_build_query(['subject' => $title, 'body' => $message."\n\n".$url]) }}"
        class="event-utility-action bg-surface px-3.5 py-2 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] text-ink uppercase border-2 border-line transition hover:border-accent"
    >
        @if ($iconsOnly)
            <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 6 9 7 9-7"/></svg>
        @endif
        <span @class(['sr-only' => $iconsOnly])>{{ __('common.share.email') }}</span>
    </a>
    <span data-share-status class="sr-only" role="status"></span>
</div>
