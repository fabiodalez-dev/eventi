@props(['occurrence'])
@php($count = $occurrence->interestedCount())
<span data-interest-id="{{ $occurrence->getKey() }}" @if ($count === 0) hidden @endif
    class="ui-tag inline-flex w-fit items-center gap-1.5 bg-surface-sunken px-2 py-1 text-xs leading-snug text-ink-muted"
    aria-live="polite">
    <x-lucide name="heart" class="size-3.5 shrink-0" />
    <span data-interest-text>{{ trans_choice('events.interested', $count, ['count' => $count]) }}</span>
</span>
