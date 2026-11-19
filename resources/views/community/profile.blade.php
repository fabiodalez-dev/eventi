{{--
    Il profilo pubblico di una persona. Il nome è il titolo della pagina; sotto,
    una card con la foto (o le iniziali), il nome utente, i numeri, la
    presentazione e i gesti: Segui, o Modifica se il profilo è il mio, e le
    azioni rare (segnalare, bloccare) chiuse in «Altre azioni».

    Poi i locali che consiglia, le date a cui va e i consigli che ha
    pubblicato, in una sola colonna e sempre con la locandina. «Prossimi» e
    «Archivio» valgono per entrambe le liste.
--}}
@php
    $past = request()->boolean('past');
    $rideReviews = app(\App\Services\Carpool\RideReviews::class);
    // Sul proprio profilo la persona è la stessa: un'istanza sola, così i ruoli si leggono una volta.
    $owner = $summary['is_own'] ? auth()->user() : $profile->user;
    $showRideReviews = auth()->check() && $rideReviews->reader(auth()->user(), $owner) && $rideReviews->isDriver($owner);
    $handleLine = collect(['@'.$profile->handle, $profile->city?->name])->filter()->implode(' '.__('common.separator').' ');
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$profile->display_name"
        :back="auth()->check() ? route('community.people') : null"
        :back-label="__('community.back_people')"
        :tabs="\App\Services\Community\CommunityNavigation::tabs($summary['is_own'] ? 'settings' : 'people')"
        :tabs-label="__('community.nav')"
    >
        <x-card data-community-profile>
            <div class="flex min-w-0 items-center gap-4 sm:gap-5">
                <x-avatar :src="$summary['avatar_url']" :name="$profile->display_name" size="lg" />
                <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                    <p class="m-0 text-sm break-words text-ink-muted">{{ $handleLine }}</p>
                    @if ($summary['verified'])
                        <p class="m-0 inline-flex items-center gap-1.5 text-xs font-semibold text-brand" title="{{ __('community.whatsapp.badge_help') }}"><x-lucide name="check" class="size-4" />{{ __('community.verified') }}</p>
                    @endif
                </div>
            </div>
            <x-stat-list :label="__('community.relations_label')" :items="[
                ['label' => __('community.followers_label'), 'value' => $summary['followers_count']],
                ['label' => __('community.following_label_count'), 'value' => $summary['following_count']],
                ['label' => __('community.recommendations_label'), 'value' => $posts->total()],
            ]" />
            @if ($profile->bio)
                <p class="m-0 leading-relaxed break-words whitespace-pre-line text-ink">{{ $profile->bio }}</p>
            @endif
            <x-slot:footer>
                @if ($summary['is_own'])
                    <x-button size="sm" :href="route('community.settings')">{{ __('community.edit_profile') }}</x-button>
                    <x-button size="sm" variant="secondary" :href="route('community.followers')">{{ __('community.area.relationships') }}</x-button>
                @else
                    <x-person-follow :user-id="$profile->user_id" :following="$summary['is_following']" :name="$profile->display_name" />
                @endif
                @if ($showRideReviews)
                    <x-button size="sm" variant="secondary" :href="route('carpool.reviews', $profile->user_id)">{{ __('carpool.reviews.title') }}</x-button>
                @endif
                @if (auth()->check() && ! $summary['is_own'])
                    <details class="group w-full" data-profile-more>
                        <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 text-sm font-semibold text-ink-muted hover:text-ink [&::-webkit-details-marker]:hidden">
                            {{ __('community.more_actions') }}<x-lucide name="chevron-down" class="size-4 transition group-open:rotate-180" />
                        </summary>
                        <div class="mt-3 flex flex-col gap-4 border-t border-line pt-4">
                            @include('community.report', ['subject' => $profile, 'reportLabel' => __('community.report_profile')])
                            <div class="flex flex-col gap-2">
                                <p class="m-0 text-sm text-ink-muted">{{ __('community.block_help') }}</p>
                                <form data-community-form method="post" action="{{ route('community.block', $profile->user_id) }}">
                                    @csrf
                                    <x-button type="submit" variant="danger" size="sm">{{ __('community.block') }}</x-button>
                                </form>
                            </div>
                        </div>
                    </details>
                @endif
            </x-slot:footer>
        </x-card>

        @if ($venues->isNotEmpty())
            <x-card :title="__('community.venues')" padding="none">
                @foreach ($venues as $venue)
                    <x-card.row :href="route('venues.show', $venue)" icon="map-pin" :title="$venue->name" :description="$venue->city?->name" />
                @endforeach
            </x-card>
        @endif

        <x-segmented :label="__('community.profile_period')" :items="[
            ['label' => __('community.period_upcoming'), 'href' => route('community.profile', $profile->handle), 'active' => ! $past],
            ['label' => __('community.period_past'), 'href' => route('community.profile', ['handle' => $profile->handle, 'past' => 1]), 'active' => $past],
        ]" />

        @if ($participations->isNotEmpty())
            <x-card :title="__('community.my_attendance')" :description="__('community.attendance_'.($past ? 'past' : 'upcoming').($summary['is_own'] ? '_own' : '_help'))">
                <ul class="m-0 flex list-none flex-col divide-y divide-line p-0">
                    @foreach ($participations as $date)
                        <li class="py-4 first:pt-0 last:pb-0">
                            <x-service-event-row :occurrence="$date">
                                @if ($summary['is_own'])
                                    <x-slot:actions>
                                        <form data-community-form method="post" action="{{ route('community.attendance', $date) }}">
                                            @csrf
                                            <input type="hidden" name="going" value="0">
                                            <x-button variant="secondary" size="sm" type="submit">{{ __('community.attendance.not_going') }}</x-button>
                                        </form>
                                    </x-slot:actions>
                                @endif
                            </x-service-event-row>
                        </li>
                    @endforeach
                </ul>
                @if ($participations->hasPages())
                    <x-slot:footer><x-pagination :paginator="$participations" /></x-slot:footer>
                @endif
            </x-card>
        @endif

        <section class="flex min-w-0 flex-col gap-4" aria-labelledby="consigli-titolo">
            <h2 id="consigli-titolo" class="m-0 font-display text-xl leading-tight font-extrabold tracking-[-0.015em] text-ink">{{ __('community.my_recommendations') }}</h2>
            @forelse ($posts as $post)
                @include('community.post-card')
            @empty
                <x-empty-state :title="__('community.publication_empty_title')" :description="__('community.publication_empty')" />
            @endforelse
            <x-pagination :paginator="$posts" />
        </section>
    </x-service-page>
</x-layouts.app>
