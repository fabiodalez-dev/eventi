<x-filament-panels::page>
    <div class="flex flex-col gap-6" data-venue-access>
        <x-filament::section :heading="__('panel.access.role_title')" icon="heroicon-o-identification" data-venue-access-role>
            <div class="flex flex-col gap-3 text-sm text-gray-950 dark:text-white">
                <p class="flex flex-wrap items-center gap-2">
                    <span>{{ __('panel.access.role_body', ['venue' => \App\Filament\Venue\Support\CurrentVenue::get()->name, 'role' => $this->roleLabel()]) }}</span>
                    <x-filament::badge :color="$this->isOwner() ? 'primary' : 'gray'">{{ ucfirst($this->roleLabel()) }}</x-filament::badge>
                </p>
                <p class="text-gray-600 dark:text-gray-400">{{ __($this->isOwner() ? 'panel.access.owner_can' : 'panel.access.editor_can') }}</p>
                @if ($since = $this->memberSince())
                    <p class="text-gray-600 dark:text-gray-400">{{ __('panel.access.since', ['date' => $since]) }}</p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('panel.access.danger_title')" :description="__('panel.access.danger_body')" icon="heroicon-o-exclamation-triangle" icon-color="danger" data-venue-access-danger>
            <div class="flex flex-col gap-4">
                @if ($this->isLastOwner())
                    <x-filament::callout color="warning" icon="heroicon-o-information-circle" :description="__('panel.access.last_owner')" data-venue-access-last-owner />
                @endif
                <div class="flex flex-wrap gap-3">
                    {{ $this->leaveAction }}
                    @if ($this->isLastOwner() && \App\Filament\Venue\Pages\Collaborators::canAccess())
                        <x-filament::button tag="a" color="gray" icon="heroicon-o-user-group" :href="\App\Filament\Venue\Pages\Collaborators::getUrl()">{{ __('panel.access.collaborators') }}</x-filament::button>
                    @endif
                </div>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
