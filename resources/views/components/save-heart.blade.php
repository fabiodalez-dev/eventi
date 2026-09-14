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
    /* icon (solo segnalibro) · label (segnalibro e testo) · count (compatto sulla locandina) */
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
        data-save-variant="{{ $variant }}"
        @class([
            'inline-flex items-center justify-center font-semibold ring-1 transition [&>*]:pointer-events-none',
            'min-h-9 min-w-0 max-w-full gap-1.5 px-2.5 py-1 text-[0.6875rem] leading-none' => $variant === 'count',
            'min-h-12 min-w-12 gap-1.5 px-3 py-1.5 text-xs' => $variant !== 'count',
            'bg-brand text-on-brand ring-brand' => $isSaved,
            'bg-surface text-ink-muted ring-line hover:text-ink hover:border-accent' => ! $isSaved,
        ])
    >
        {{-- Il cuore di Lucide (D47). Lo script di `app.js` accende e spegne
             il riempimento via style inline: gli attributi del componente
             (stroke, fill) sono lo stato spento, lo style quello acceso. --}}
        @if ($slot->isNotEmpty())
            {{ $slot }}
        @else
        <x-lucide name="bookmark" class="size-4" data-save-icon @style(['fill: currentColor; stroke: none; stroke-width: 0' => $isSaved]) />

        <span @class(['sr-only' => $variant === 'icon']) data-save-text>
            {{ $isSaved ? __('account.save.remove') : __('account.save.action') }}
        </span>
        @endif
    </button>
</form>
