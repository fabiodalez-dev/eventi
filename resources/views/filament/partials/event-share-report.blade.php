<x-filament::section :heading="__('event-shares.title')" :description="__('event-shares.description')">
    <x-filament::button tag="a" :href="\Filament\Facades\Filament::getCurrentPanel()->getId() === 'venue' ? \App\Filament\Venue\Pages\EventShareAnalytics::getUrl() : \App\Filament\Organizer\Pages\EventShareAnalytics::getUrl()">{{ __('analytics-dashboard.title') }} · {{ __('analytics-dashboard.excel') }}</x-filament::button>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm" data-event-share-report>
            <thead><tr>
                @foreach (['event', 'channel', 'link', 'shares', 'clicks'] as $column)
                    <th class="p-3" scope="col">{{ __('event-shares.'.$column) }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="p-3">{{ $row->title }}@if ($row->url_number !== null)<span class="block text-xs">{{ __('event-shares.occurrence', ['number' => $row->url_number, 'date' => \Carbon\CarbonImmutable::parse($row->starts_at, 'UTC')->timezone($row->timezone)->format('d/m/Y H:i')]) }}</span>@endif</td>
                        <td class="p-3">{{ __('event-shares.channels.'.$row->channel) }}</td>
                        <td class="p-3"><code>{{ route('event-shares.open', ['code' => $row->code]) }}</code></td>
                        <td class="p-3">{{ number_format($row->shares, 0, ',', '.') }}</td>
                        <td class="p-3">{{ number_format($row->clicks, 0, ',', '.') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-3">{{ __('event-shares.empty') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <x-filament::pagination :paginator="$rows->onEachSide(1)" />
    <p class="mt-3 text-sm text-gray-500">{{ __('event-shares.notes') }}</p>
</x-filament::section>
