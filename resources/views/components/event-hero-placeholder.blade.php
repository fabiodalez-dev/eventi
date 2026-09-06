{{-- Tipografia e segnale urbano, senza inventare una locandina dell'organizzatore. --}}
<div data-event-hero-placeholder class="flex flex-1 flex-col justify-center gap-4 py-6">
    <svg viewBox="0 0 480 64" fill="none" class="h-16 w-full text-line" preserveAspectRatio="xMinYMid meet" aria-hidden="true">
        <path d="M0 60H480M8 60V20H56V60M20 60V40a12 12 0 0 1 24 0v20M72 60V4H128V60M84 60V32a16 16 0 0 1 32 0v28M144 60V20H200V60M156 60V40a16 16 0 0 1 32 0v20M216 60V4H272V60M228 60V32a16 16 0 0 1 32 0v28M288 60V20H344V60M300 60V40a16 16 0 0 1 32 0v20M360 60V4H416V60M372 60V32a16 16 0 0 1 32 0v28M432 60V20H480" stroke="currentColor" stroke-width="2" />
    </svg>
    <span aria-hidden="true" class="font-display text-[clamp(3.5rem,8vw,7rem)] leading-none font-extrabold tracking-[-0.065em] text-ink">{{ config('app.name') }}<span class="text-accent">.</span></span>
    <span class="font-display text-xs font-bold tracking-[0.12em] text-ink-subtle uppercase">{{ __('events.card.poster_missing') }}</span>
</div>
