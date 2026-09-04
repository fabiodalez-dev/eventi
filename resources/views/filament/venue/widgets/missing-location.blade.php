{{--
    L'avviso è un riquadro, non una notifica: le notifiche spariscono da sole
    e chi entra di fretta non le legge. Questo resta finché la cosa non è
    fatta, ed è l'unico modo perché una cosa che *sembra* a posto venga
    guardata.
--}}
<div class="fi-section p-4 sm:p-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-col gap-1">
            <p class="text-base font-bold text-gray-950 dark:text-white">
                {{ __('manage.location_missing.title') }}
            </p>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('manage.location_missing.body') }}
            </p>
        </div>

        <a
            href="{{ \App\Filament\Venue\Pages\VenueProfile::getUrl() }}"
            class="fi-btn fi-color fi-color-primary fi-bg-color-400 shrink-0 px-4 py-2 text-sm font-semibold"
        >
            {{ __('manage.location_missing.action') }}
        </a>
    </div>
</div>
