{{--
    La testata e il corpo di una pagina di servizio: area personale, moduli,
    biglietti, community, passaggi. Va dentro `<x-layouts.app service>`, che
    dà la colonna unica da 48rem, i margini e lo spazio sopra il titolo:
    qui dentro non si scrive nessuna larghezza.

    L'ordine è fisso, così passando da una pagina all'altra ogni cosa resta al
    suo posto: ritorno, schede, occhiello, titolo (l'unico h1 della pagina),
    sottotitolo, azioni, riepilogo degli errori, corpo.

    Props:
    - `title` (obbligatorio), `subtitle`, `eyebrow`;
    - `back`: l'indirizzo del ritorno, o `null` per nessun ritorno;
      `backLabel` (per difetto «Il mio profilo»);
    - `tabs`: le schede della sezione (`href`, `label`, `active`, `count`?),
      con `tabsLabel` come nome accessibile; sul telefono scorrono;
    - `showErrors`: il riepilogo degli errori del modulo sotto il titolo
      (acceso per difetto: le schede non lo stampano più).

    Slot: `actions` (riga di pulsanti sotto il sottotitolo) e quello
    predefinito, impilato con 24px fra un blocco e l'altro.
--}}
@props([
    'title',
    'subtitle' => null,
    'eyebrow' => null,
    'back' => null,
    'backLabel' => null,
    'tabs' => [],
    'tabsLabel' => null,
    'showErrors' => true,
])
<div data-service-page {{ $attributes->class('flex min-w-0 flex-col gap-6') }}>
    @if (filled($back) || $tabs !== [])
        <div class="flex min-w-0 flex-col gap-2">
            @if (filled($back))
                <a href="{{ $back }}" data-profile-back class="-ml-1 inline-flex min-h-12 items-center gap-2 self-start px-1 font-semibold text-brand no-underline hover:underline">
                    <x-lucide name="arrow-left" class="size-5" />
                    {{ $backLabel ?? __('ui.service.back_profile') }}
                </a>
            @endif
            @if ($tabs !== [])
                <x-section-tabs :label="$tabsLabel ?? $title" :items="$tabs" :scroll="true" :flush="true" />
            @endif
        </div>
    @endif

    <header class="flex min-w-0 flex-col gap-3">
        @if (filled($eyebrow))
            <p class="m-0 text-eyebrow text-accent uppercase">{{ $eyebrow }}</p>
        @endif
        <h1 class="m-0 text-hero break-words text-ink">{{ $title }}</h1>
        @if (filled($subtitle))
            <p class="m-0 text-base text-ink-muted sm:text-lg">{{ $subtitle }}</p>
        @endif
        @isset($actions)
            <div class="mt-2 flex flex-wrap gap-3">{{ $actions }}</div>
        @endisset
    </header>

    {{-- La conferma dell'ultima azione arriva dal reindirizzamento: sta sotto
         la testata, dove l'occhio arriva dopo il titolo. --}}
    @if (session()->has('status'))
        <x-notice tone="success">{{ session('status') }}</x-notice>
    @endif

    @if ($showErrors)
        <x-form-errors />
    @endif

    @auth
        @php($contentSelection = app(\App\Services\Account\ContentPreferences::class)->selection(auth()->user()))
        @if ($contentSelection['mode'] === 'selected' || $contentSelection['hidden_categories'] !== [])
            <x-notice><p class="m-0">{{ __('ui.content_filter.notice') }} <a class="font-semibold text-brand underline" href="{{ route('account.content-preferences') }}">{{ __('ui.content_filter.change') }}</a></p></x-notice>
        @endif
    @endauth

    @if ($slot->isNotEmpty())
        <div class="flex min-w-0 flex-col gap-6">
            {{ $slot }}
        </div>
    @endif
</div>
