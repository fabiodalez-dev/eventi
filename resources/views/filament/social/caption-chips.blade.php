<div class="flex flex-wrap gap-2 py-2">
    @foreach (\App\Services\Social\SocialPublisher::TOKENS as $token)
        <x-filament::button size="xs" color="gray" x-on:click="$wire.set('data.caption', ($wire.data.caption || '') + ' :{{ $token }}')">{{ __('social.tokens.'.$token) }} <span class="text-gray-500 dark:text-gray-400">:{{ $token }}</span></x-filament::button>
    @endforeach
</div>
