{{--
    Il cuore (§15.1). **Funziona al primo click, senza registrazione.**

    È un modulo, non un pulsante decorato: senza JavaScript chi è collegato
    salva con un invio e un ricaricamento, e chi non lo è viene portato alla
    pagina di accesso dal middleware `auth`. Lo script che sta in
    `resources/js/app.js` gli toglie il ricaricamento e, per chi non è
    collegato, lo dirotta sul `localStorage` — dove il salvataggio avviene
    davvero, senza alcuna interruzione.

    Lo stato di partenza lo dichiara il server per chi è collegato
    (`:saved`), mentre per gli anonimi lo scopre lo script leggendo il
    `localStorage`: il server non sa e non deve sapere che cosa ha salvato
    chi non ha un account.
--}}
@props([
    'occurrence',
    'saved' => false,
    /* icon (solo cuore) · label (cuore e testo) */
    'variant' => 'icon',
])

@php
    $id = (int) $occurrence->getKey();
    $authenticated = auth()->check();
    $isSaved = $authenticated && $saved;

    $action = $isSaved
        ? route('account.saved.destroy', ['occurrence' => $id])
        : route('account.saved.store');
@endphp

<form
    method="POST"
    action="{{ $action }}"
    data-save
    data-save-id="{{ $id }}"
    data-save-authenticated="{{ $authenticated ? '1' : '0' }}"
    data-save-store="{{ route('account.saved.store') }}"
    data-save-destroy="{{ route('account.saved.destroy', ['occurrence' => $id]) }}"
    data-save-label="{{ __('account.save.action') }}"
    data-save-label-saved="{{ __('account.save.remove') }}"
    {{ $attributes->class(['relative z-10']) }}
>
    @csrf
    @if ($isSaved)
        @method('DELETE')
    @else
        <input type="hidden" name="occurrence_id" value="{{ $id }}">
    @endif

    <button
        type="submit"
        aria-pressed="{{ $isSaved ? 'true' : 'false' }}"
        data-save-button
        @class([
            'inline-flex items-center gap-1.5 rounded-pill px-3 py-1.5 text-xs font-semibold ring-1 transition',
            'bg-brand text-on-brand ring-brand' => $isSaved,
            'bg-surface text-ink-muted ring-line hover:text-ink hover:ring-line-strong' => ! $isSaved,
        ])
    >
        <svg aria-hidden="true" viewBox="0 0 24 24" class="size-4" fill="currentColor" data-save-icon @style(['fill: none; stroke: currentColor; stroke-width: 2' => ! $isSaved])>
            <path d="M12 20.7 4.6 13.3a4.8 4.8 0 0 1 0-6.8 4.8 4.8 0 0 1 6.8 0l.6.6.6-.6a4.8 4.8 0 0 1 6.8 0 4.8 4.8 0 0 1 0 6.8Z" />
        </svg>

        <span @class(['sr-only' => $variant === 'icon']) data-save-text>
            {{ $isSaved ? __('account.save.remove') : __('account.save.action') }}
        </span>
    </button>
</form>
