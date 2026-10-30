{{--
    «Controlla la posta» dopo la registrazione (§15.2, D64).

    È la stessa pagina per un indirizzo nuovo e per uno già registrato: la
    differenza sta solo nel messaggio che arriva nella casella. Per questo non
    mostra l'indirizzo né dice «account creato».
--}}
<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">
                {{ __('account.register.sent_lead', ['minutes' => config('account.magic_link_minutes')]) }}
            </p>
        </header>

        <p class="text-sm text-ink-muted">{{ __('account.register.sent_same_browser') }}</p>
        <p class="text-sm text-ink-subtle">{{ __('account.register.sent_help') }}</p>

        {{-- Il reinvio è rifare la registrazione con la stessa password: il
             collegamento che riparte lascia valida la password scelta. Un
             collegamento di accesso qualunque non lo sa fare dall'app, e qui
             non lo si propone, perché sito e app dicano la stessa cosa. --}}
        <a class="text-sm font-semibold text-brand hover:underline" href="{{ route('account.register') }}">{{ __('account.register.sent_again') }}</a>
    </div>
</x-layouts.app>
