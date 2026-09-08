{{--
    Registrazione (§15.2).

    La pagina dice a che cosa serve un account — promemoria, salvataggi su ogni
    dispositivo, feed — e non finge che senza account il sito non funzioni: **il
    cuore funziona già** (§15.1), e chi arriva qui lo sa perché ha appena
    salvato tre date.
--}}
<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">{{ __('account.register.lead') }}</p>
        </header>

        <form method="POST" action="{{ route('account.register.store') }}" class="flex flex-col gap-4">
            @csrf
            <x-honeypot />

            <x-field name="first_name" :label="__('account.register.first_name')" autocomplete="given-name" />
            <x-field name="last_name" :label="__('account.register.last_name')" autocomplete="family-name" />
            <x-field name="email" type="email" :label="__('account.register.email')" :required="true" autocomplete="email" />
            <x-field name="password" type="password" :label="__('account.register.password')" :required="true" autocomplete="new-password" />
            <x-field name="password_confirmation" type="password" :label="__('account.register.password')" :required="true" autocomplete="new-password" />

            @newsletter
            <label class="flex items-start gap-2.5 text-sm text-ink-muted">
                <input type="checkbox" name="marketing_opt_in" value="1" class="mt-0.5 size-4 rounded border-line" @checked(old('marketing_opt_in'))>
                <span>
                    {{ __('account.register.marketing') }}
                    <span class="block text-xs text-ink-subtle">{{ __('account.register.marketing_hint') }}</span>
                </span>
            </label>
            @endnewsletter

            <x-turnstile action="registrazione-utente" />

            <button type="submit" class="bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('account.register.submit') }}
            </button>
        </form>

        <p class="text-sm text-ink-subtle">
            {{ __('account.register.have_account') }}
            <a class="font-semibold text-brand hover:underline" href="{{ route('login') }}">{{ __('account.nav.login') }}</a>
        </p>
    </div>
</x-layouts.app>
