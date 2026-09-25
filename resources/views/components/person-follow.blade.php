@props(['userId', 'following' => false, 'name' => ''])
@if(auth()->id() !== $userId)
    @if(auth()->check() && auth()->user()->hasVerifiedEmail())
        <form data-community-form method="post" action="{{ route('community.follow', $userId) }}">
            @csrf @if($following) @method('DELETE') @endif
            <x-button type="submit" :variant="$following ? 'secondary' : 'primary'" :aria-pressed="$following ? 'true' : 'false'" :aria-label="__($following ? 'community.unfollow_person' : 'community.follow_person', ['name' => $name])">{{ __($following ? 'community.following_label' : 'community.follow') }}</x-button>
        </form>
    @else
        <x-button :href="auth()->check() ? route('verification.notice') : route('login', ['intended' => request()->fullUrl()])">{{ __('community.follow') }}</x-button>
    @endif
@endif
