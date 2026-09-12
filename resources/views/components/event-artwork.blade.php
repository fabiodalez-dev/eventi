@props(['event', 'eager' => false])
@php($artwork = \App\Support\Poster::imageSet($event))
<div {{ $attributes->class('event-poster-frame') }}>
    @if ($artwork !== null)
        <x-media-image :set="$artwork" :alt="__('events.card.poster_alt', ['title' => $event->title])" width="600" height="800" sizes="240px" :eager="$eager" :color="true" data-poster-reveal class="poster-reveal" :show-placeholder="false" />
    @else
        <span data-event-hero-placeholder class="flex size-full items-center justify-center p-4 text-center text-sm text-ink-muted">{{ __('events.card.poster_missing') }}</span>
    @endif
</div>
