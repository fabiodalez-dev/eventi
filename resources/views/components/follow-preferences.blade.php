@props(['venue'])
@auth
@php $followPreference = auth()->user()->follows()->where('followable_type', 'venue')->where('followable_id', $venue->id)->first(); @endphp
@if ($followPreference)
<form method="post" action="{{ route('account.follows.store') }}" class="flex flex-wrap items-end gap-3 py-3">
    @csrf
    <input type="hidden" name="type" value="venue"><input type="hidden" name="id" value="{{ $venue->id }}">
    <label class="flex min-w-0 flex-col gap-2 text-sm">{{ __('decision.follow_mode') }}
        <select name="notification_mode" class="min-h-12 max-w-full border border-line bg-surface p-2">
            @foreach (\App\Enums\FollowNotificationMode::cases() as $mode)
                <option value="{{ $mode->value }}" @selected(($followPreference->notify ? ($followPreference->notification_mode?->value ?? 'all') : 'none') === $mode->value)>{{ __('decision.'.$mode->value) }}</option>
            @endforeach
        </select>
    </label>
    <x-button type="submit" variant="secondary">{{ __('decision.save') }}</x-button>
</form>
@endif
@endauth
