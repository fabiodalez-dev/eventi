<x-filament-panels::page>
    <p style="max-width:75ch">{{ __('social.setup_help') }}</p>
    <x-filament::section heading="API e autenticazione Meta">
        <p>Instagram e Facebook condividono l’app Meta. Inserisci App ID e App Secret, salva, poi premi Collega con Meta. Autorizza le Pagine desiderate e scegli quale collegare. È richiesto un account Instagram professionale associato alla Pagina.</p>
        <p style="margin-top:12px">In Meta Developers, nelle impostazioni di Facebook Login, registra questo URL di reindirizzamento OAuth:</p>
        <code style="display:block;overflow-wrap:anywhere;margin-top:8px">{{ route('social.meta.callback') }}</code>
        <p style="margin-top:12px">Permessi: pages_show_list, pages_read_engagement, pages_manage_posts, instagram_basic, instagram_content_publish. Per account esterni ai ruoli dell’app possono servire revisione e accesso avanzato Meta.</p>
        @if(session('meta_message'))<p role="status" style="margin-top:12px;font-weight:700">{{ session('meta_message') }}</p>@endif
        @php($connection = \App\Models\SocialConnection::first())
        <p style="margin-top:12px">App Secret: {{ $connection?->app_secret ? 'salvato' : 'mancante' }}. Token: {{ $connection?->access_token ? 'salvato' : 'mancante' }}. Collegamento: {{ $connection?->verified_at ? 'verificato' : 'da verificare' }}.</p>
    </x-filament::section>
    <x-filament::section heading="Stato Telegram">
        <p>Token bot: {{ $connection?->telegram_bot_token ? 'salvato' : 'mancante' }}. Canale: {{ $connection?->telegram_chat_id ?: 'non configurato' }}. Verifica: {{ $connection?->telegram_verified_at ? 'completata' : 'da eseguire' }}.</p>
        <p>Le didascalie con immagini hanno un limite di 1024 caratteri. La verifica controlla bot, canale e permesso di pubblicare senza inviare messaggi.</p>
    </x-filament::section>
    <form wire:submit="save" style="display:grid;gap:24px">
        {{ $this->getSchema('form') }}
        <div style="display:flex;flex-wrap:wrap;gap:16px"><x-filament::button type="submit">{{ __('social.save') }}</x-filament::button><x-filament::button color="gray" wire:click="verify" wire:loading.attr="disabled">{{ __('social.verify') }}</x-filament::button><x-filament::button color="gray" wire:click="verifyTelegram" wire:loading.attr="disabled">Verifica Telegram</x-filament::button><x-filament::button color="gray" wire:click="connectMeta" wire:loading.attr="disabled">Collega con Meta</x-filament::button><x-filament::button color="gray" wire:click="disconnectMeta" wire:confirm="Rimuovere il token salvato e disattivare l’autopost?">Scollega Meta</x-filament::button></div>
    </form>
</x-filament-panels::page>
