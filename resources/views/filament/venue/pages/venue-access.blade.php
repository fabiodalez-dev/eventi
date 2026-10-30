<x-filament-panels::page>
    @if ($this->isLastOwner())
        <p class="text-sm text-gray-600">{{ __('manage.access.last_owner') }}</p>
    @endif
</x-filament-panels::page>
