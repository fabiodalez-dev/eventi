{{-- «Aperto adesso»: calcolato nel browser dagli orari del locale, così resta
     vero anche su una pagina servita dalla cache. Senza orari non compare. --}}
@props(['venue'])
@php $hours = is_array($venue->opening_hours) ? $venue->opening_hours : []; @endphp
@if ($hours !== [])
    <span data-open-now data-hours='@json($hours)' data-timezone="{{ $venue->relationLoaded('city') ? $venue->city?->timezone : (app(\App\Support\CurrentCity::class)->get()?->timezone ?? config('app.timezone')) }}"
          data-open-label="{{ __('venues.open_now.open') }}" data-open-until="{{ __('venues.open_now.until') }}" data-closed-label="{{ __('venues.open_now.closed') }}"
          {{ $attributes->class(['inline-flex items-center gap-1 border-2 border-line px-2 py-0.5 text-xs font-bold uppercase tracking-wide']) }} hidden></span>
@endif
