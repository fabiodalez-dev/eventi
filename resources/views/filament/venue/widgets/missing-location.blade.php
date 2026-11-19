{{--
    L'avviso è un riquadro, non una notifica: le notifiche spariscono da sole
    e chi entra di fretta non le legge. Questo resta finché la cosa non è
    fatta, ed è l'unico modo perché una cosa che *sembra* a posto venga
    guardata.
--}}
<x-filament-widgets::widget>
    <x-filament::section :heading="__('manage.location_missing.title')" :description="__('manage.location_missing.body')" icon="heroicon-o-map-pin" icon-color="warning" data-dashboard-missing-location>
        <x-filament::button tag="a" :href="\App\Filament\Venue\Pages\VenueProfile::getUrl()" icon="heroicon-o-map">{{ __('manage.location_missing.action') }}</x-filament::button>
    </x-filament::section>
</x-filament-widgets::widget>
