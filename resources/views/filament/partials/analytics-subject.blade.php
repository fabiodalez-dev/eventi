@php
    $subject = $this->subject;
    $isEvent = $this->subjectType === 'event';
    $record = $isEvent ? $data['events']->first() : $data[$this->subjectType === 'venue' ? 'venues' : 'organizers']->first();
@endphp
<div data-analytics-subject="{{ $this->subjectType }}">
    @if ($this::getSlug() !== 'dashboard')
    <x-filament::button tag="a" color="gray" icon="heroicon-o-arrow-left" :href="$this->overviewUrl()">{{ __('analytics-dashboard.back_overview') }}</x-filament::button>
    @endif
</div>
<x-filament::section :heading="__('analytics-dashboard.subject_information')">
    <dl class="ad-event-facts">
        <div><dt>{{ __('analytics-dashboard.columns.city') }}</dt><dd>{{ $subject->city->name }}</dd></div>
        @if ($isEvent && $record)
            @foreach (['category', 'status', 'occurrences'] as $key)
                <div><dt>{{ $metricLabel($key) }}</dt><dd>{{ $record[$key] ?? __('analytics-dashboard.unavailable') }}</dd></div>
            @endforeach
            @foreach (['venue', 'organizer'] as $type)
                <div><dt>{{ __('analytics-dashboard.'.$type) }}</dt><dd>
                    @if ($record[$type.'_id'])
                        <a class="ad-drilldown" href="{{ $this->detailUrl($type, $record[$type.'_id']) }}">{{ $record[$type] }}</a>
                    @else
                        {{ $record[$type] }}
                    @endif
                </dd></div>
            @endforeach
        @else
            @foreach (['address', 'website'] as $field)
                @if (filled($subject->getAttribute($field)))
                    <div><dt>{{ __('analytics-dashboard.'.$field) }}</dt><dd>{{ $subject->getAttribute($field) }}</dd></div>
                @endif
            @endforeach
        @endif
    </dl>
    @if (filled($subject->getAttribute('description')))
        <details class="ad-subject-description"><summary>{{ __('analytics-dashboard.description') }}</summary><div class="ad-read-description"><x-description-content :text="$subject->getAttribute('description')" /></div></details>
    @endif
</x-filament::section>
@if (! $isEvent && $record)
    <x-filament::section :heading="__('analytics-dashboard.profile_report')" :description="__('analytics-dashboard.profile_report_hint')">
        <dl class="ad-profile-metrics">
            @foreach ($record as $key => $value)
                @if (str_starts_with($key, 'profile_') || in_array($key, ['followers', 'new_followers', 'reviews', 'approved_reviews', 'rating']))
                    <div><dt>{{ $metricLabel($key) }}</dt><dd>{{ $value === null ? __('analytics-dashboard.unavailable') : number_format($value, is_float($value) ? 2 : 0, ',', '.') }}</dd></div>
                @endif
            @endforeach
        </dl>
    </x-filament::section>
@endif
