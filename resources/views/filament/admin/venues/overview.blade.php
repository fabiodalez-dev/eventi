@php
    use App\Enums\VenueRole;
    use App\Enums\VenueStatus;
    use App\Filament\Admin\Resources\Events\EventResource;
    use App\Filament\Admin\Resources\Venues\VenueResource;
    use App\Filament\Admin\Support\VenueModeration;
    use App\Filament\Support\EventStatusPresentation;
    use App\Filament\Support\PosterColumn;
    use App\Queries\VenueDashboardQuery;
    use Carbon\CarbonImmutable;
    use Illuminate\Support\Facades\Route;

    /*
     * La scheda di un locale in sola lettura (redazione): testata con la
     * copertina e i pulsanti, informazioni, descrizione, prossimi eventi con
     * la locandina, chi lo gestisce. Card impilate a tutta larghezza; ogni
     * colore ha la sua variante scura.
     */
    /** @var \App\Models\Venue $venue */
    $venue->loadMissing(['city', 'media']);
    $timezone = $venue->city->timezone;
    $coverUrl = VenueResource::coverUrl($venue);
    $hasCover = $coverUrl !== asset(PosterColumn::PLACEHOLDER);
    $siteUrl = $venue->status === VenueStatus::Approved && Route::has('venues.show') ? route('venues.show', $venue->slug) : null;
    $editUrl ??= null;

    $eventsQuery = \App\Models\Event::query()->where('venue_id', $venue->getKey())->with(['media', 'category']);
    VenueDashboardQuery::applyNextOccurrence($eventsQuery, $venue->city);
    $upcoming = $eventsQuery->havingNotNull('next_starts_at')->orderBy('next_starts_at')->limit(8)->get();
    $eventsTotal = $venue->events()->count();
    $members = $venue->membersIncludingInvited()->orderBy('name')->get();

    $facts = array_filter([
        __('admin.fields.status') => $venue->status->label(),
        __('admin.fields.type') => $venue->type?->label(),
        __('admin.fields.city') => $venue->city?->name,
        __('admin.fields.address') => collect([$venue->address, $venue->address_extra, $venue->postal_code, $venue->municipality])->filter()->join(', '),
        __('admin.fields.phone') => $venue->phone,
        __('admin.fields.email') => $venue->email,
        __('admin.fields.website') => $venue->website,
        __('admin.fields.capacity') => $venue->capacity,
        __('admin.fields.plan') => $venue->plan?->label(),
        __('admin.fields.auto_publish') => $venue->auto_publish ? __('admin.view_pages.yes') : __('admin.view_pages.no'),
        __('admin.fields.rejection_reason') => $venue->rejection_reason,
    ], fn ($value) => filled($value));
@endphp
<div class="flex min-w-0 flex-col gap-6" data-venue-read-view>
    <x-filament::section data-venue-head>
        <div class="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-start sm:gap-6">
            @if ($hasCover)
                <img src="{{ $coverUrl }}" alt="" width="240" height="160" loading="lazy" decoding="async"
                    class="aspect-[3/2] w-full shrink-0 rounded-lg bg-gray-100 object-cover ring-1 ring-gray-950/5 sm:w-60 dark:bg-white/5 dark:ring-white/10" data-venue-cover>
            @else
                <div class="flex aspect-[3/2] w-full shrink-0 flex-col items-center justify-center gap-2 rounded-lg bg-gray-100 p-2 text-center text-xs text-gray-500 ring-1 ring-gray-950/5 sm:w-60 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10" data-poster-placeholder>
                    <x-filament::icon icon="heroicon-o-photo" class="size-8" />
                    <span>{{ __('admin.view_pages.no_cover') }}</span>
                </div>
            @endif

            <div class="flex min-w-0 flex-1 flex-col gap-3">
                <div class="flex flex-wrap gap-2">
                    <x-filament::badge :color="VenueModeration::statusColor($venue->status)">{{ $venue->status->label() }}</x-filament::badge>
                    <x-filament::badge :color="$venue->is_verified ? 'success' : 'gray'" :icon="$venue->is_verified ? 'heroicon-o-shield-check' : null">
                        {{ $venue->is_verified ? __('admin.venue_list.verified') : __('admin.venue_list.not_verified') }}
                    </x-filament::badge>
                </div>
                <p class="flex min-w-0 items-start gap-1.5 text-sm text-gray-600 dark:text-gray-300">
                    <x-filament::icon icon="heroicon-o-map-pin" class="mt-0.5 size-4 shrink-0" />
                    <span class="min-w-0 [overflow-wrap:anywhere]">{{ collect([$venue->address, $venue->municipality])->filter()->join(', ') }}</span>
                </p>
                <p class="text-sm text-gray-600 dark:text-gray-300">{{ trans_choice('admin.venue_list.events_count', $eventsTotal, ['count' => $eventsTotal]) }}</p>
            </div>
        </div>

        @if ($editUrl || $siteUrl)
            <div class="mt-6 flex flex-wrap gap-3" data-venue-actions>
                @if ($editUrl)
                    <x-filament::button tag="a" :href="$editUrl" icon="heroicon-o-pencil-square">{{ __('admin.view_pages.edit') }}</x-filament::button>
                @endif
                @if ($siteUrl)
                    <x-filament::button tag="a" :href="$siteUrl" target="_blank" rel="noopener" color="gray" outlined icon="heroicon-o-arrow-top-right-on-square">{{ __('admin.actions.view_on_site') }}</x-filament::button>
                @endif
            </div>
        @endif
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('admin.view_pages.venue_hint') }}</p>
    </x-filament::section>

    <x-filament::section :heading="__('admin.view_pages.info')">
        <dl class="divide-y divide-gray-100 dark:divide-white/10" data-venue-facts>
            @foreach ($facts as $label => $value)
                <div class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0 sm:flex-row sm:gap-6">
                    <dt class="shrink-0 text-sm text-gray-500 sm:w-44 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="min-w-0 whitespace-pre-line text-sm font-medium text-gray-950 [overflow-wrap:anywhere] dark:text-white">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>

    <x-filament::section :heading="__('admin.fields.description')">
        @if (filled(trim(strip_tags((string) $venue->description))))
            <div class="text-sm leading-relaxed text-gray-950 dark:text-gray-100 [&_a]:text-primary-600 dark:[&_a]:text-primary-400">
                <x-description-content :text="$venue->description" />
            </div>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.view_pages.no_description') }}</p>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('admin.view_pages.upcoming')" :description="__('admin.view_pages.upcoming_description')" data-venue-events>
        @if ($upcoming->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.view_pages.no_upcoming') }}</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($upcoming as $event)
                    @php
                        $eventUrl = EventResource::canView($event) ? EventResource::getUrl('view', ['record' => $event]) : null;
                        $start = CarbonImmutable::parse((string) $event->getAttribute('next_starts_at'), 'UTC')->timezone($timezone);
                    @endphp
                    <li class="flex min-w-0 items-center gap-3 py-3 first:pt-0 last:pb-0" data-event-row>
                        <img src="{{ PosterColumn::url($event) }}" alt="" width="48" height="64" loading="lazy" decoding="async"
                            class="h-16 w-12 shrink-0 rounded-md bg-gray-100 object-cover ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            @if ($eventUrl)
                                <a href="{{ $eventUrl }}" class="text-sm font-semibold text-gray-950 hover:underline [overflow-wrap:anywhere] dark:text-white">{{ $event->title }}</a>
                            @else
                                <span class="text-sm font-semibold text-gray-950 [overflow-wrap:anywhere] dark:text-white">{{ $event->title }}</span>
                            @endif
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ $start->translatedFormat('D d/m/Y, H:i') }}@if ($event->category) · {{ $event->category->name }}@endif</span>
                        </div>
                        <x-filament::badge :color="EventStatusPresentation::color($event->status)" class="shrink-0">{{ $event->status->label() }}</x-filament::badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('admin.view_pages.members')" data-venue-members>
        @if ($members->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.view_pages.no_members') }}</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($members as $member)
                    <li class="flex min-w-0 flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0">
                        <span class="min-w-0 text-sm font-medium text-gray-950 [overflow-wrap:anywhere] dark:text-white">{{ $member->name }}</span>
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            {{ VenueRole::tryFrom((string) $member->pivot->role)?->label() }}@if ($member->pivot->accepted_at === null) · {{ __('admin.placeholders.pending_invite') }}@endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</div>
