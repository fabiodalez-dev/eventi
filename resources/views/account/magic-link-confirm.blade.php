{{-- Il collegamento di accesso aperto in un browser diverso da quello che lo
     ha chiesto: prima di entrare si vede su quale account, e si entra solo con
     il pulsante. La GET non consuma niente, così gli scanner di posta non
     bruciano il collegamento. --}}
<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">{{ __('account.magic.confirm_lead', ['email' => $email]) }}</p>
        </header>

        <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
            @csrf

            <label class="flex items-center gap-2.5 text-sm text-ink-muted">
                <input type="checkbox" name="remember" value="1" class="size-4 rounded border-line">
                {{ __('account.login.remember') }}
            </label>

            <button type="submit" class="bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('account.magic.confirm_submit') }}
            </button>
        </form>

        <p class="text-sm text-ink-subtle">{{ __('account.magic.confirm_not_you') }}</p>
    </div>
</x-layouts.app>
