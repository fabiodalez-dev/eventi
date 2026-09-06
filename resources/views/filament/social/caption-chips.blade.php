<div style="display:flex;flex-wrap:wrap;gap:8px;padding:8px 0">
    @foreach(\App\Services\Social\SocialPublisher::TOKENS as $token)
        <x-filament::button size="xs" color="gray" x-on:click="$wire.set('data.caption', ($wire.data.caption || '') + ' :{{ $token }}')">{{ __('social.tokens.'.$token) }} <span style="opacity:.6">:{{ $token }}</span></x-filament::button>
    @endforeach
</div>
