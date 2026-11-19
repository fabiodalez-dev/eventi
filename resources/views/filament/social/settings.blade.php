<x-filament-panels::page>
    @php($connection = \App\Models\SocialConnection::first())
    <p class="max-w-prose text-sm text-gray-600 dark:text-gray-400">{{ __('social.setup_help') }}</p>
    <x-filament::section :heading="__('social.settings_page.meta_heading')">
        <div class="flex flex-col gap-3 text-sm text-gray-700 dark:text-gray-300">
            <p>{{ __('social.settings_page.meta_intro') }}</p>
            <p>{{ __('social.settings_page.redirect_hint') }}</p>
            <code class="block [overflow-wrap:anywhere] text-gray-950 dark:text-white">{{ route('social.meta.callback') }}</code>
            <p>{{ __('social.settings_page.permissions') }}</p>
            @if (session('meta_message'))
                <p role="status" class="font-semibold text-gray-950 dark:text-white">{{ session('meta_message') }}</p>
            @endif
            <p>{{ __('social.settings_page.meta_status', [
                'secret' => __($connection?->app_secret ? 'social.settings_page.saved' : 'social.settings_page.missing'),
                'token' => __($connection?->access_token ? 'social.settings_page.saved' : 'social.settings_page.missing'),
                'verified' => __($connection?->verified_at ? 'social.settings_page.verified' : 'social.settings_page.to_verify'),
            ]) }}</p>
        </div>
    </x-filament::section>
    <x-filament::section :heading="__('social.settings_page.telegram_heading')">
        <div class="flex flex-col gap-3 text-sm text-gray-700 dark:text-gray-300">
            <p>{{ __('social.settings_page.telegram_status', [
                'token' => __($connection?->telegram_bot_token ? 'social.settings_page.saved' : 'social.settings_page.missing'),
                'chat' => $connection?->telegram_chat_id ?: __('social.settings_page.telegram_no_chat'),
                'verified' => __($connection?->telegram_verified_at ? 'social.settings_page.telegram_done' : 'social.settings_page.telegram_todo'),
            ]) }}</p>
            <p>{{ __('social.settings_page.telegram_help') }}</p>
        </div>
    </x-filament::section>
    <form wire:submit="save" class="flex flex-col gap-6">
        {{ $this->getSchema('form') }}
        <div class="flex flex-wrap gap-4">
            <x-filament::button type="submit">{{ __('social.save') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="verify" wire:loading.attr="disabled">{{ __('social.verify') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="verifyTelegram" wire:loading.attr="disabled">{{ __('social.settings_page.verify_telegram') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="connectMeta" wire:loading.attr="disabled">{{ __('social.settings_page.connect_meta') }}</x-filament::button>
            <x-filament::button color="gray" wire:click="disconnectMeta" wire:confirm="{{ __('social.settings_page.disconnect_confirm') }}">{{ __('social.settings_page.disconnect_meta') }}</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
