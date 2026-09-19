@auth
{{-- L'elenco avvisi vive sotto /avvisi, che risponde 404 quando la community è spenta. --}}
@if(config('carpool.enabled') && config('community.enabled'))
<a href="{{ route('community.inbox') }}" class="relative inline-flex size-12 shrink-0 items-center justify-center" aria-label="{{ __('community.inbox') }}">
    <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
    <span data-community-count="total" data-community-summary-url="{{ route('carpool.summary') }}" data-label="{{ __('community.inbox') }}" hidden class="absolute right-0 top-0 min-w-5 rounded-full bg-accent px-1 text-center text-xs font-bold text-on-accent"></span>
</a>
@endif
@endauth
