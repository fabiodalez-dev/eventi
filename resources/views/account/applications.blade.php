{{--
    «Le mie richieste»: una card per richiesta (locale nuovo, rivendicazione,
    organizzazione) con lo stato, cosa succede adesso e il pulsante giusto.
    Quello che ogni card deve dire lo decide `VenueApplicationsController`:
    qui nessuna query e nessuna regola.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('venue_applications.account.lead')" :back="route('account.profile')">
        @if ($cards !== [])
            <x-slot:actions>
                <x-button size="sm" :href="route('venue-applications.create')">
                    <x-lucide name="plus" class="size-4" />
                    {{ __('venue_applications.account.new_venue') }}
                </x-button>
                <x-button size="sm" variant="secondary" :href="route('organizer-applications.create')">{{ __('venue_applications.account.new_organizer') }}</x-button>
            </x-slot:actions>
        @endif

        @forelse ($cards as $card)
            @php
                $application = $card['application'];
                $dates = __('venue_applications.account.sent_on', ['date' => $application->created_at?->timezone($user->timezone ?: config('app.timezone'))->translatedFormat('j F Y')]);
                if ($application->reviewed_at !== null && in_array($card['state'], ['approved', 'rejected'], true)) {
                    $dates .= ' · '.__('venue_applications.account.decided_on', ['date' => $application->reviewed_at->timezone($user->timezone ?: config('app.timezone'))->translatedFormat('j F Y')]);
                }
                $retry = match ($application->kind()) {
                    \App\Enums\ApplicationKind::Organizer => route('organizer-applications.create'),
                    \App\Enums\ApplicationKind::Claim => $application->venue?->isClaimable() ? route('venues.claim.create', $application->venue) : route('venue-applications.create'),
                    default => route('venue-applications.create'),
                };
            @endphp
            <x-card
                as="article"
                :id="'richiesta-'.$application->getKey()"
                :title="$application->venue_name"
                :description="$card['kind']"
                data-application="{{ $application->getKey() }}"
                data-application-state="{{ $card['state'] }}"
            >
                <div class="flex flex-col gap-2">
                    <p class="m-0 text-sm text-ink-muted">{{ $dates }}</p>
                    @if ($card['summary'] !== null)
                        <p class="m-0 text-sm break-words text-ink-muted">{{ $card['summary'] }}</p>
                    @endif
                </div>
                <p class="m-0 text-base text-ink">{{ $card['next'] }}</p>

                @php($hasFooter = $card['state'] !== 'pending' || ($application->kind() === \App\Enums\ApplicationKind::Claim && $application->venue !== null))
                @if ($hasFooter)
                    <x-slot:footer>
                        @switch($card['state'])
                            @case('awaiting')
                                @if ($card['confirm'])
                                    <form method="POST" action="{{ route('venue-applications.confirm', $application->getKey()) }}">
                                        @csrf
                                        <x-button type="submit" size="sm">{{ __('venue_applications.account.confirm') }}</x-button>
                                    </form>
                                    <form method="POST" action="{{ route('venue-applications.discard', $application->getKey()) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-button type="submit" size="sm" variant="danger">{{ __('venue_applications.account.discard') }}</x-button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('account.verification.send') }}">
                                        @csrf
                                        <x-button type="submit" size="sm">{{ __('venue_applications.account.resend') }}</x-button>
                                    </form>
                                @endif
                                @break
                            @case('approved')
                                @if ($card['panel'] !== null)
                                    <x-button size="sm" :href="$card['panel']">
                                        {{ __('venue_applications.account.open_panel') }}
                                        <x-lucide name="arrow-right" class="size-4" />
                                    </x-button>
                                @endif
                                @if ($card['public'] !== null)
                                    <x-button size="sm" variant="secondary" :href="$card['public']">{{ __('venue_applications.account.open_public') }}</x-button>
                                @endif
                                @break
                            @case('rejected')
                                <x-button size="sm" variant="secondary" :href="$retry">{{ __('venue_applications.account.new_request') }}</x-button>
                                @break
                            @default
                                <x-button size="sm" variant="secondary" :href="route('venues.show', $application->venue)">{{ __('venue_applications.account.open_venue') }}</x-button>
                        @endswitch
                    </x-slot:footer>
                @endif

                <x-slot:actions>
                    <x-badge :tone="$card['tone']" data-application-badge>{{ __('venue_applications.account.state.'.$card['state']) }}</x-badge>
                </x-slot:actions>
            </x-card>
        @empty
            <x-empty-state :title="__('venue_applications.account.empty_title')" :description="__('venue_applications.account.empty')">
                <x-button :href="route('venue-applications.create')">{{ __('venue_applications.account.new_venue') }}</x-button>
                <x-button variant="secondary" :href="route('organizer-applications.create')">{{ __('venue_applications.account.new_organizer') }}</x-button>
            </x-empty-state>
        @endforelse

        <x-pagination :paginator="$applications" />
    </x-service-page>
</x-layouts.app>
