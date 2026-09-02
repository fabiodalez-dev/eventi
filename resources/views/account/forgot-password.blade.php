{{-- «Password dimenticata» (§15.2).

     Il messaggio di conferma e' lo stesso che l'indirizzo esista o no: dire
     «questa email non risulta» significa regalare a chiunque l'elenco degli
     iscritti, una prova alla volta. --}}
<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">{{ __('account.forgot.lead') }}</p>
        </header>

        <form method="POST" action="{{ route('account.password.email') }}" class="flex flex-col gap-4">
            @csrf

            <x-field name="email" type="email" :label="__('account.login.email')" :required="true" autocomplete="email" />

            <button type="submit" class="bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('account.forgot.submit') }}
            </button>
        </form>

        {{-- L'accesso senza password resta la via che il piano raccomanda: chi
             e' arrivato qui perche' non ricorda la sua puo' evitare del tutto
             di sceglierne un'altra. --}}
        <a class="text-sm font-semibold text-brand hover:underline" href="{{ route('account.magic-link') }}">
            {{ __('account.forgot.prefer_magic') }}
        </a>
    </div>
</x-layouts.app>
