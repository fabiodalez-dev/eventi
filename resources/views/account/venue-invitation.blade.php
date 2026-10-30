{{-- «Accetta l'invito» (D64): la GET mostra la domanda, il POST con CSRF
     decide. Gli scanner di posta aprono il collegamento ma non premono
     pulsanti, quindi non accettano niente al posto della persona. --}}
<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex w-full max-w-md flex-col gap-6">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            @if ($error !== null)
                <p class="text-sm text-ink-muted" role="alert">{{ $error }}</p>
            @else
                <p class="text-sm text-ink-muted">{{ __('manage.invitation.page_lead', ['venue' => $venue->name, 'role' => mb_strtolower($role->label())]) }}</p>
            @endif
        </header>

        @if ($error === null)
            <p class="text-sm text-ink-muted">{{ __('manage.invitation.page_consequences') }}</p>

            <form method="POST" action="{{ $action }}" class="flex flex-col gap-3">
                @csrf

                <button type="submit" name="decision" value="accept" class="bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                    {{ __('manage.invitation.accept_action') }}
                </button>

                <button type="submit" name="decision" value="decline" class="border-2 border-line bg-surface px-4 py-2.5 text-sm font-semibold text-ink transition hover:border-accent hover:bg-canvas">
                    {{ __('manage.invitation.decline_action') }}
                </button>
            </form>
        @endif
    </div>
</x-layouts.app>
