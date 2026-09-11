{{--
    Fascia di ritorno mostrata soltanto mentre qualcuno della redazione sta
    navigando con l'identità di un altro utente (§9.2).

    Senza questa striscia l'unica via d'uscita sarebbe scrivere a mano l'URL
    di ritorno: chi entra nei panni di un utente per capire un problema
    resterebbe lì senza accorgersene, e ogni azione compiuta risulterebbe
    dell'utente impersonato.
--}}
@php
    $impersonatorId = session(\App\Http\Controllers\Web\ImpersonationController::SESSION_KEY);
@endphp

@if ($impersonatorId !== null)
    <div class="border-b border-line bg-brand text-on-brand" role="status">
        <div class="mx-auto flex w-full max-w-content flex-wrap items-center justify-between gap-2 px-gutter py-2 text-sm">
            <p class="font-semibold">
                {{ __('admin.notifications.impersonating', ['name' => auth()->user()?->name ?? '']) }}
            </p>

            <a
                class="ui-action bg-on-brand/15 px-3 py-1 font-semibold underline hover:bg-on-brand/25"
                href="{{ route('impersonate.stop') }}"
            >
                {{ __('admin.actions.stop_impersonating') }}
            </a>
        </div>
    </div>
@endif
