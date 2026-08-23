{{-- Accesso con password (§15.2). Il collegamento senza password sta accanto,
     non al posto: è la via raccomandata, non l'unica. --}}
<x-layouts.app :meta="$meta">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">{{ __('account.login.lead') }}</p>
        </header>

        <form method="POST" action="{{ route('account.login.store') }}" class="flex flex-col gap-4">
            @csrf

            <x-field name="email" type="email" :label="__('account.login.email')" :required="true" autocomplete="email" />
            <x-field name="password" type="password" :label="__('account.login.password')" :required="true" autocomplete="current-password" />

            <label class="flex items-center gap-2.5 text-sm text-ink-muted">
                <input type="checkbox" name="remember" value="1" class="size-4 rounded border-line" @checked(old('remember'))>
                {{ __('account.login.remember') }}
            </label>

            <button type="submit" class="rounded-pill bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('account.login.submit') }}
            </button>
        </form>

        <div class="flex flex-col gap-1 text-sm text-ink-subtle">
            <a class="font-semibold text-brand hover:underline" href="{{ route('account.magic-link') }}">{{ __('account.magic.title') }}</a>

            <p>
                {{ __('account.login.no_account') }}
                <a class="font-semibold text-brand hover:underline" href="{{ route('account.register') }}">{{ __('account.nav.register') }}</a>
            </p>
        </div>
    </div>
</x-layouts.app>
