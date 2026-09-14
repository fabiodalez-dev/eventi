@props(['occurrence', 'overlay' => false])
@php($count = $occurrence->interestedCount())
<span data-interest-id="{{ $occurrence->getKey() }}" data-interest-count="{{ $count }}" data-interest-base-count="{{ $count }}"
    data-interest-singular="{{ trans_choice('events.interested', 1, ['count' => '__COUNT__']) }}"
    data-interest-plural="{{ trans_choice('events.interested', 2, ['count' => '__COUNT__']) }}"
    @if ($count === 0) hidden @endif
    {{ $attributes->class(['inline-flex w-fit items-center gap-1.5 text-xs leading-snug', 'ui-tag border border-line bg-surface px-3 py-2 text-ink' => $overlay, 'text-ink-muted' => ! $overlay]) }}
    aria-live="polite">
    @if ($overlay)
        <x-lucide name="bookmark" class="size-3.5" />
    @endif
    <span data-interest-text>{{ trans_choice('events.interested', $count, ['count' => $count]) }}</span>
</span>
