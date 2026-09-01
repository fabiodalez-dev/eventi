{{--
    Lo scheletro mostrato mentre il frammento arriva. Non finge card che
    potrebbero non esserci: è una riga sola, e sparisce.

    Chi ha JavaScript disattivato non riceverà mai il frammento: per lui resta
    il rimando alla lista di oggi, che dice la stessa cosa in una pagina intera.
--}}
<div class="mt-6">
    <p role="status" class="flex items-center gap-2 text-sm text-ink-subtle">
        <span aria-hidden="true" class="size-1.5 bg-live pulse-dot"></span>
        {{ __('events.sections.live_loading') }}
    </p>

    <noscript>
        <a class="text-sm font-semibold text-brand underline" href="{{ route('events.today') }}">
            {{ __('events.redirects.to_today') }}
        </a>
    </noscript>
</div>
