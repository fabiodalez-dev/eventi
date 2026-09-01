{{-- Accesso senza password (§15.2), la via raccomandata: per un uso saltuario
     come questo, una password da ricordare è attrito puro. --}}
<x-layouts.app :meta="$meta">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">
                {{ __('account.magic.lead', ['minutes' => config('account.magic_link_minutes')]) }}
            </p>
        </header>

        <form method="POST" action="{{ route('account.magic-link.store') }}" class="flex flex-col gap-4">
            @csrf

            <x-field name="email" type="email" :label="__('account.login.email')" :required="true" autocomplete="email" />

            <button type="submit" class="bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('account.magic.submit') }}
            </button>
        </form>

        <a class="text-sm font-semibold text-brand hover:underline" href="{{ route('login') }}">{{ __('account.magic.with_password') }}</a>
    </div>
</x-layouts.app>
