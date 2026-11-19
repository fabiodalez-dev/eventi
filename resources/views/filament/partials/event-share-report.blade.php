<x-filament::section :heading="__('panel.analytics.shares_title')" :description="__('panel.analytics.shares_hint')" icon="heroicon-o-link" data-share-report-section>
    <div class="flex flex-col gap-4">
        <div>
            <x-filament::button tag="a" color="gray" icon="heroicon-o-arrow-top-right-on-square" :href="\Filament\Facades\Filament::getCurrentPanel()->getId() === 'venue' ? \App\Filament\Venue\Pages\EventShareAnalytics::getUrl() : \App\Filament\Organizer\Pages\EventShareAnalytics::getUrl()">{{ __('panel.analytics.shares_open') }}</x-filament::button>
        </div>
        <div class="ad-table-scroll" tabindex="0" role="region" aria-label="{{ __('panel.analytics.shares_title') }}">
            <table class="ad-table" data-event-share-report>
                <thead><tr>
                    @foreach (['event', 'channel', 'link', 'shares', 'clicks'] as $column)
                        <th scope="col" @class(['ad-number' => in_array($column, ['shares', 'clicks'])])>{{ __('event-shares.'.$column) }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>{{ $row->title }}@if ($row->url_number !== null)<span class="block text-xs text-gray-600 dark:text-gray-400">{{ __('event-shares.occurrence', ['number' => $row->url_number, 'date' => \Carbon\CarbonImmutable::parse($row->starts_at, 'UTC')->timezone($row->timezone)->format('d/m/Y H:i')]) }}</span>@endif</td>
                            <td>{{ \App\Services\Analytics\EventShares::channelLabel($row->channel) }}</td>
                            <td><code class="text-xs [overflow-wrap:anywhere]">{{ route('event-shares.open', ['code' => $row->code]) }}</code></td>
                            <td class="ad-number">{{ number_format($row->shares, 0, ',', '.') }}</td>
                            <td class="ad-number">{{ number_format($row->clicks, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">{{ __('event-shares.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-filament::pagination :paginator="$rows->onEachSide(1)" />
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('panel.analytics.share_notes') }}</p>
    </div>
</x-filament::section>
