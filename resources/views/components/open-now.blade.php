{{-- «Aperto adesso»: calcolato nel browser dagli orari del locale, così resta
     vero anche su una pagina servita dalla cache. Una chiusura straordinaria in
     corso vale sopra gli orari. Senza orari né chiusure non compare. --}}
@props(['venue'])
@php
    $hours = is_array($venue->opening_hours) ? $venue->opening_hours : [];
    $closures = is_array($venue->closures) ? $venue->closures : [];
@endphp
@if ($hours !== [] || $closures !== [])
    <span data-open-now data-hours='@json((object) $hours)' data-closures='@json($closures)' data-closure-label="{{ __('venues.open_now.closure') }}" data-timezone="{{ $venue->relationLoaded('city') ? $venue->city?->timezone : (app(\App\Support\CurrentCity::class)->get()?->timezone ?? config('app.timezone')) }}"
          data-open-label="{{ __('venues.open_now.open') }}" data-open-until="{{ __('venues.open_now.until') }}" data-closed-label="{{ __('venues.open_now.closed') }}"
          {{ $attributes->class(['inline-flex items-center gap-1 border-2 border-line px-2 py-0.5 text-xs font-bold uppercase tracking-wide']) }} hidden></span>
@endif
