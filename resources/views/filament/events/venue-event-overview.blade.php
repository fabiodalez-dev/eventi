@php
    $event = $getRecord();
    // Query the relation explicitly: the resource list preloads only the next date.
    $dates = $event->occurrences()->with(['venue', 'lineups'])->orderBy('starts_at')->get()->each(fn ($date) => $date->setRelation('event', $event));
    $timezone = $event->city->timezone;
@endphp
<div class="ad-event-overview" data-event-read-view>
    <p class="ad-muted">{{ __('analytics-dashboard.read_event_hint') }}</p>
    <x-filament::section :heading="$event->title">
        <dl class="ad-event-facts">
            <div><dt>{{ __('manage.fields.status') }}</dt><dd>{{ $event->status->label() }}</dd></div>
            <div><dt>{{ __('analytics-dashboard.columns.category') }}</dt><dd>{{ $event->category?->name }}</dd></div>
            <div><dt>{{ __('analytics-dashboard.venue') }}</dt><dd>{{ $event->venue?->name }}</dd></div>
            <div><dt>{{ __('analytics-dashboard.organizer') }}</dt><dd>{{ $event->organizer?->name ?? $event->organizer_name ?? __('analytics-dashboard.no_organizer') }}</dd></div>
            <div><dt>{{ __('manage.fields.price_type') }}</dt><dd>{{ $event->price_type->label() }}</dd></div>
            @foreach (['price_min', 'price_max'] as $field)
                @if ($event->getAttribute($field) !== null)<div><dt>{{ __('manage.fields.'.$field) }}</dt><dd>{{ number_format($event->getAttribute($field), 2, ',', '.') }}</dd></div>@endif
            @endforeach
        </dl>
        <div class="ad-read-description"><x-description-content :text="$event->description" /></div>
    </x-filament::section>
    <x-filament::section :heading="__('analytics-dashboard.all_occurrences')">
        <div class="ad-table-scroll" tabindex="0" role="region" aria-label="{{ __('analytics-dashboard.all_occurrences') }}">
            <table class="ad-table" data-event-occurrences-read>
                <thead><tr>
                    @foreach (['manage.fields.starts_at', 'manage.fields.ends_at', 'analytics-dashboard.doors', 'manage.fields.occurrence_status', 'analytics-dashboard.location', 'analytics-dashboard.lineup', 'analytics-dashboard.capacity', 'analytics-dashboard.occurrence_notes'] as $label)
                        <th scope="col">{{ __($label) }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse ($dates as $date)
                        <tr data-occurrence-read="{{ $date->id }}">
                            <td>{{ $date->starts_at->timezone($timezone)->format('d/m/Y H:i') }}</td>
                            <td>{{ $date->ends_at?->timezone($timezone)->format('d/m/Y H:i') ?? __('analytics-dashboard.unavailable') }}</td>
                            <td>{{ $date->doors_at?->timezone($timezone)->format('d/m/Y H:i') ?? __('analytics-dashboard.unavailable') }}</td>
                            <td>{{ $date->status->label() }}</td>
                            <td>{{ $date->locationLabel() }}</td>
                            <td>{{ $date->lineups->pluck('name')->join(', ') ?: __('analytics-dashboard.unavailable') }}</td>
                            <td>{{ $date->capacity_left ?? __('analytics-dashboard.unavailable') }} / {{ $date->capacity ?? __('analytics-dashboard.unavailable') }}</td>
                            <td>{{ collect([$date->highlight, $date->status_note])->filter()->join(' · ') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8">{{ __('analytics-dashboard.no_occurrences') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</div>
