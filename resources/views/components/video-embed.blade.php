{{--
    Il video di un evento, caricato solo a richiesta.

    Prima del gesto c'è un riquadro nostro: nessuna richiesta a YouTube o
    Vimeo, nessun cookie. Al click il riquadro diventa l'iframe del lettore
    (`youtube-nocookie`, Vimeo con `dnt=1`). Chi preferisce non caricare
    niente ha il collegamento alla pagina del fornitore.
--}}
@props(['video', 'title'])
<section class="flex flex-col gap-3" aria-labelledby="video-evento" data-video-embed data-embed-url="{{ $video->embedUrl }}" data-embed-title="{{ $title }}">
    <h2 id="video-evento" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('events.detail.video') }}</h2>
    <div data-video-frame class="relative aspect-video w-full border-2 border-line bg-canvas">
        <button type="button" data-video-play class="absolute inset-0 flex size-full flex-col items-center justify-center gap-3 p-4 text-center">
            <span aria-hidden="true" class="flex size-16 items-center justify-center border-2 border-line bg-surface text-2xl">▶</span>
            <span class="font-display text-sm font-extrabold uppercase tracking-[0.14em]">{{ __('events.detail.video_play') }}</span>
            <span class="max-w-prose text-xs text-ink-muted">{{ __('events.detail.video_notice', ['provider' => $video->providerLabel()]) }}</span>
        </button>
    </div>
    <a href="{{ $video->watchUrl }}" rel="noopener noreferrer" target="_blank" class="self-start text-sm underline">{{ __('events.detail.video_open', ['provider' => $video->providerLabel()]) }}</a>
</section>
