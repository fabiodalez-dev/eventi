<x-filament-panels::page>
    @php $campagne = $this->sponsorships(); @endphp

    @if ($campagne->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('sponsorships.venue.none') }}</p>
    @else
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="w-full text-sm">
                <thead class="border-b border-gray-200 text-left text-xs font-semibold uppercase text-gray-500 dark:border-white/10 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">{{ __('sponsorships.admin.fields.event') }}</th>
                        <th class="px-4 py-3">{{ __('sponsorships.admin.fields.advertiser_name') }}</th>
                        <th class="px-4 py-3">{{ __('sponsorships.admin.fields.placement') }}</th>
                        <th class="px-4 py-3">{{ __('sponsorships.admin.fields.ends_at') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($campagne as $campagna)
                        <tr>
                            <td class="px-4 py-3 font-medium">{{ $campagna->event?->title }}</td>
                            <td class="px-4 py-3">{{ $campagna->advertiser_name }}</td>
                            <td class="px-4 py-3">{{ $campagna->placement->label() }}</td>
                            <td class="px-4 py-3">{{ $campagna->ends_at?->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament-panels::page>
