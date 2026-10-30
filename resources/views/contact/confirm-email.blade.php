{{-- La conferma del recapito del modulo contatti. La GET mostra solo il
     pulsante: gli scanner di posta aprono i collegamenti da soli, e la
     conferma deve venire da una persona. --}}
<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">{{ __('contact.confirmation.page_'.$state, ['name' => $name]) }}</p>
        </header>

        @if ($state === 'pending' && $action !== null)
            <form method="POST" action="{{ $action }}" class="flex flex-col gap-4">
                @csrf
                <button type="submit" class="bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                    {{ __('contact.confirmation.page_submit') }}
                </button>
            </form>
            <p class="text-sm text-ink-subtle">{{ __('contact.confirmation.page_not_you') }}</p>
        @endif
    </div>
</x-layouts.app>
