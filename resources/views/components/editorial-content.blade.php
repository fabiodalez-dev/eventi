@props(['model', 'occurrence' => null])
@php($details = app(\App\Services\Seo\EditorialContent::class)->details($model))
@if (filled($details['organizer_venue_id'] ?? null))
    @php($organizer = \App\Models\Venue::query()->approved()->whereKey($details['organizer_venue_id'])->first())
    @if ($organizer)
        <p>{{ __('seo.organizer_name') }}: <a class="underline" href="{{ route('venues.show', $organizer) }}">{{ $organizer->name }}</a></p>
    @endif
@endif
@if (isset($details['minimum_age']))
    <p>{{ __('seo.minimum_age') }}: {{ $details['minimum_age'] }}</p>
@endif
@if (in_array($details['parking_type'] ?? null, ['free', 'paid', 'none'], true))
    <p>{{ __('seo.parking_type') }}: {{ __('seo.parking_'.$details['parking_type']) }}</p>
@endif
@if (in_array($details['attendance_mode'] ?? null, ['online', 'mixed'], true))
    <p class="font-bold">{{ __('seo.'.$details['attendance_mode']) }}</p>
    @if ($online = \App\Support\SafeUrl::href($details['online_url'] ?? null))
        <p><a class="underline text-accent" href="{{ $online }}" target="_blank" rel="noopener noreferrer">{{ __('seo.online_url') }}</a></p>
    @endif
@endif
@if (filled($details['introduction'] ?? null))
    <p class="max-w-prose whitespace-pre-line">{{ $details['introduction'] }}</p>
@endif
@foreach (['parking_notes', 'transit_notes', 'entrance_notes', 'accessibility_notes', 'minors_policy', 'cancellation_policy', 'refund_policy', 'public_contact', 'poster_caption', 'poster_credit'] as $field)
    @if (filled($details[$field] ?? null))
        <section class="py-4 border-b border-line">
            <h3 class="font-bold">{{ __('seo.fields.'.$field) }}</h3>
            <p class="max-w-prose whitespace-pre-line">{{ $details[$field] }}</p>
        </section>
    @endif
@endforeach
@if (filled($details['accessibility'] ?? null))
    <p>{{ __('seo.fields.accessibility') }}: {{ $details['accessibility'] === 'yes' ? __('seo.yes') : __('seo.no') }}</p>
@endif
@if (! empty($details['agenda']))
    <section class="py-6">
        <h2 class="font-display text-xl font-extrabold">{{ __('seo.agenda') }}</h2>
        <ol class="list-none p-0">
            @foreach ($details['agenda'] as $item)
                @continue($occurrence !== null && filled($item['occurrence_id'] ?? null) && (int) $item['occurrence_id'] !== (int) $occurrence->id)
                <li class="py-4 border-b border-line">
                    <p class="text-accent">{{ $item['when'] ?? '' }}</p>
                    <h3 class="font-bold">{{ $item['title'] ?? '' }}</h3>
                    <p>{{ $item['speaker'] ?? '' }}</p>
                    <p class="max-w-prose whitespace-pre-line">{{ $item['description'] ?? '' }}</p>
                </li>
            @endforeach
        </ol>
    </section>
@endif
@if (! empty($details['faqs']))
    @if (! request()->routeIs('events.preview'))
        <x-json-ld :data="[['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($faq) => ['@type' => 'Question', 'name' => $faq['question'] ?? '', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer'] ?? '']], array_values($details['faqs']))]]" />
    @endif
    <section class="py-6">
        <h2 class="font-display text-xl font-extrabold">{{ __('seo.faqs') }}</h2>
        @foreach ($details['faqs'] as $faq)
            <details class="py-4 border-b border-line">
                <summary class="cursor-pointer min-h-12 font-bold">{{ $faq['question'] ?? '' }}</summary>
                <p class="max-w-prose whitespace-pre-line">{{ $faq['answer'] ?? '' }}</p>
            </details>
        @endforeach
    </section>
@endif
