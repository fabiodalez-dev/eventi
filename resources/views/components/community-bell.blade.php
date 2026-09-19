@auth
{{-- L'elenco avvisi vive sotto /avvisi, che risponde 404 quando la community è spenta.

     La campanella apre una tendina con gli ultimi avvisi invece di cambiare
     pagina. `<details>` come il menu dell'account: si apre da tastiera senza
     script, e senza JavaScript la tendina mostra comunque il collegamento a
     tutti gli avvisi. Lo script carica l'elenco solo all'apertura, così le
     pagine non pagano una query sugli avvisi a ogni visita. --}}
@if(config('carpool.enabled') && config('community.enabled'))
<details class="group relative shrink-0" data-inbox-menu data-inbox-url="{{ route('community.inbox.latest') }}" data-inbox-error="{{ __('community.inbox_error') }}">
    <summary class="relative inline-flex size-12 cursor-pointer list-none items-center justify-center text-ink transition-colors hover:text-accent group-open:text-accent [&::-webkit-details-marker]:hidden" aria-label="{{ __('community.inbox_open') }}" aria-haspopup="true">
        <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
        <span data-community-count="total" data-community-summary-url="{{ route('carpool.summary') }}" data-label="{{ __('community.inbox') }}" hidden class="absolute right-0 top-0 min-w-5 rounded-full bg-accent px-1 text-center text-xs font-bold text-on-accent"></span>
    </summary>
    <div class="fixed inset-x-3 top-[74px] z-50 flex max-h-[min(32rem,75dvh)] flex-col border-2 border-line bg-canvas shadow-xl sm:absolute sm:inset-x-auto sm:right-0 sm:top-full sm:mt-1 sm:w-96">
        <p class="flex min-h-12 items-center border-b-2 border-line px-4 font-display text-[0.625rem] leading-none font-extrabold tracking-[0.14em] uppercase">{{ __('community.inbox_latest') }}</p>
        <div class="min-h-0 flex-1 overflow-y-auto" data-inbox-menu-body aria-live="polite">
            <p class="px-4 py-6 text-sm text-ink-muted">{{ __('community.inbox_loading') }}</p>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 border-t-2 border-line px-4 py-2">
            <a href="{{ route('community.inbox') }}" class="inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-ink underline underline-offset-4 hover:text-accent">{{ __('community.inbox_all') }} <span aria-hidden="true">→</span></a>
            <form method="post" action="{{ route('community.inbox.read') }}" data-inbox-read-all>@csrf<input type="hidden" name="through" value="" data-inbox-through><button type="submit" class="inline-flex min-h-11 cursor-pointer items-center text-xs font-semibold text-ink-muted hover:text-ink">{{ __('community.read_all') }}</button></form>
        </div>
    </div>
</details>
@endif
@endauth
