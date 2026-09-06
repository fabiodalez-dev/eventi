{{-- Marchio neutro rispetto alla città, senza simulare una locandina. --}}
<div data-event-hero-placeholder class="flex flex-1 flex-col justify-center gap-4 py-6">
    <span aria-hidden="true" class="font-display text-[clamp(3.5rem,8vw,7rem)] leading-none font-extrabold tracking-[-0.065em] text-ink">{{ config('app.name') }}<span class="text-accent">.</span></span>
    <span class="font-display text-xs font-bold tracking-[0.12em] text-ink-subtle uppercase">{{ __('events.card.poster_missing') }}</span>
</div>
