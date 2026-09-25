@props(['reason' => null])
@php
    $step = $reason ?? app(\App\Services\Community\CommunityAccess::class)->state(auth()->user())['required_step'];
    $destination = match($step) {
        'login' => route('login', ['intended' => request()->fullUrl()]),
        'email' => route('verification.notice'),
        'whatsapp' => route('community.whatsapp', ['intended' => request()->fullUrl()]),
        'profile', 'visibility' => route('community.settings', ['intended' => request()->fullUrl()]),
        default => null,
    };
@endphp
<div class="my-4 space-y-3">
    <p class="text-sm text-ink-muted">{{ $step === 'profile' ? __('community.profile_required') : __('community.access.'.($step ?? 'profile')) }}</p>
    @if($destination)<x-button :href="$destination">{{ __('community.access_action.'.($step ?? 'profile')) }}</x-button>@endif
</div>
