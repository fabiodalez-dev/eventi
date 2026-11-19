{{--
    Un avviso dentro una pagina: un'informazione da sapere (`info`), un esito
    andato a buon fine (`success`) o un problema (`alert`). Il tono lo dice il
    filo a sinistra e l'icona, mai il solo colore. Un avviso `alert` è
    annunciato subito da `role="alert"`; gli altri come `status`.
--}}
@props(['tone' => 'info', 'title' => null])
@php
    $tone = in_array($tone, ['info', 'success', 'alert'], true) ? $tone : 'info';
    $icon = ['info' => 'info', 'success' => 'check', 'alert' => 'alert-circle'][$tone];
@endphp
<div role="{{ $tone === 'alert' ? 'alert' : 'status' }}" data-notice="{{ $tone }}" {{ $attributes->class(['svc-notice svc-notice--'.$tone, 'flex gap-3 p-4']) }}>
    <span @class(['mt-0.5 shrink-0', 'text-alert' => $tone === 'alert', 'text-accent' => $tone === 'success', 'text-ink-muted' => $tone === 'info'])><x-lucide :name="$icon" class="size-5" /></span>
    <div class="flex min-w-0 flex-col gap-1 text-sm text-ink-muted">
        @if (filled($title))
            <p class="text-base font-semibold text-ink">{{ $title }}</p>
        @endif
        @if ($slot->isNotEmpty())
            <div class="flex flex-col gap-2">{{ $slot }}</div>
        @endif
    </div>
</div>
