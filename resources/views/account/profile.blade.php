{{--
    L'area personale: la pagina da cui si raggiunge tutto il resto. Prima le
    cose che si usano ogni giorno (salvati, biglietti, feed), poi le persone,
    le impostazioni e le aree di lavoro; in fondo i dati dell'account, che si
    cambiano di rado, e l'uscita.

    Ogni gruppo è una card fatta di righe: una riga è un collegamento intero,
    alto almeno 48px, con l'icona a sinistra e la freccia a destra. I dati che
    servono a disegnarla li prepara `ProfileController::edit`: qui nessuna
    query.
--}}
@php
    $locales = collect(config('account.locales'))->mapWithKeys(fn (string $code) => [$code => strtoupper($code)])->all();
    $soloUnaLingua = count($locales) < 2;
    $linguaScelta = array_key_exists($user->locale, $locales) ? $user->locale : (string) array_key_first($locales);

    $community = (bool) config('community.enabled');
    $groups = [
        'activities' => [
            ['account.saved', __('community.area.saved'), 'bookmark'],
            ['tickets.index', __('community.area.tickets'), 'ticket'],
            ['account.feed', __('community.area.feed'), 'rss'],
            ['account.collections.index', __('collections.title'), 'heart'],
            ['account.attended', __('account.attended.menu'), 'star'],
        ],
        'people_group' => $community ? [
            ['community.feed', __('community.area.community'), 'users'],
            ['community.people', __('community.area.people'), 'search'],
            ['community.followers', __('community.area.relationships'), 'users'],
            ['community.inbox', __('community.area.inbox'), 'mail'],
        ] : [],
        'preferences' => array_values(array_filter([
            ['account.content-preferences', __('community.area.interests'), 'filter'],
            ['account.notifications', __('community.area.notifications'), 'mail'],
            ['google-calendar.index', __('community.area.calendar'), 'calendar'],
            ['appearance', __('community.appearance'), 'info'],
            $community ? ['community.whatsapp', __('community.area.verification'), 'phone'] : null,
        ])),
    ];
    $appearanceLabel = __('account.appearance.current.'.($user->appearance ?? 'system'));
@endphp

<x-layouts.app service :meta="$meta">
    <x-service-page :title="$meta->heading" :subtitle="__('account.profile.lead')">
        @unless ($user->hasVerifiedEmail())
            <x-notice tone="alert" :title="__('account.verify.pending')">
                <p class="m-0">{{ __('account.profile.verify_hint', ['email' => $user->email]) }}</p>
                <div><x-button size="sm" variant="secondary" :href="route('verification.notice')">{{ __('account.verify.resend') }}</x-button></div>
            </x-notice>
        @endunless

        @if ($community)
            <x-card id="profilo-pubblico" :title="__('community.settings')" :description="__('community.account_identity_help')">
                @if ($publicProfile)
                    <div class="flex min-w-0 items-center gap-4">
                        <x-avatar :src="$publicProfile->avatarUrl()" :name="$publicProfile->display_name" size="lg" />
                        <div class="flex min-w-0 flex-col gap-0.5">
                            <p class="m-0 font-display text-lg leading-tight font-extrabold break-words text-ink">{{ $publicProfile->display_name }}</p>
                            <p class="m-0 text-sm break-words text-ink-muted">{{ '@'.$publicProfile->handle }}</p>
                            <p class="m-0 text-sm text-ink-muted">{{ __('community.profile_visibility.'.$publicProfile->visibility->value) }}</p>
                        </div>
                    </div>
                @endif
                <x-slot:footer>
                    <x-button size="sm" :href="route('community.settings')">{{ __($publicProfile ? 'community.edit_profile' : 'community.create_profile') }}</x-button>
                    @if ($publicProfile)
                        <x-button size="sm" variant="secondary" :href="route('community.profile', $publicProfile->handle)">{{ __('community.visit_profile') }}</x-button>
                    @endif
                </x-slot:footer>
            </x-card>
        @endif

        @foreach ($groups as $group => $items)
            @continue($items === [])
            <x-card :id="'area-'.$group" :title="__('community.area.'.$group)" padding="none">
                @foreach ($items as [$destination, $label, $icon])
                    <x-card.row :href="route($destination)" :title="$label" :icon="$icon" :description="$destination === 'appearance' ? $appearanceLabel : null" />
                @endforeach
            </x-card>
        @endforeach

        @if ($carpool !== null)
            <x-card id="profilo-passaggi" :title="__('carpool.title')" padding="none">
                @unless ($carpool['eligible'])
                    {{-- Chi non ha i requisiti lo scopre qui, prima di arrivare al modulo di un passaggio: il pulsante porta al passo che manca. --}}
                    <div class="flex flex-col gap-3 px-5 py-4 sm:px-6" data-carpool-requirements-warning="{{ $carpool['reason'] }}">
                        <x-notice tone="alert" :title="__('carpool.profile_blocked.title')">
                            <p class="m-0">{{ __('carpool.profile_blocked.'.$carpool['reason']) }}</p>
                            <div><x-button size="sm" :href="$carpoolStep">{{ __($carpool['reason'] === 'suspended' ? 'carpool.support' : 'carpool.profile_blocked.action') }}</x-button></div>
                        </x-notice>
                    </div>
                @endunless
                <x-card.row :href="route('carpool.index')" :title="__('carpool.mine')" icon="navigation" />
                <x-card.row :href="route('carpool.chats')" :title="__('carpool.messages')" icon="mail" />
                <x-card.row :href="route('carpool.requirements')" :title="__('carpool.requirements')" icon="check" />
            </x-card>
        @endif

        @if ($management !== [])
            <x-card id="profilo-gestione" :title="__('account.profile.manage_title')" padding="none">
                @foreach ($management as $link)
                    <x-card.row :href="$link['href']" :title="$link['label']" :description="$link['description']" :icon="$link['icon']" />
                @endforeach
                <x-card.row :href="route('account.applications')" :title="__('venue_applications.account.menu')" :description="__('venue_applications.account.menu_hint')" icon="mail" data-profile-applications />
            </x-card>
        @else
            <x-card id="profilo-richieste" :title="__('venue_applications.account.menu_title')" padding="none">
                <x-card.row :href="route('account.applications')" :title="__('venue_applications.account.menu')" :description="__('venue_applications.account.menu_hint')" icon="mail" data-profile-applications />
            </x-card>
        @endif

        <x-card as="form" id="dati-account" method="POST" :action="route('account.profile.update')" :title="__('community.account_details')" :description="__('account.profile.details_hint')">
            @csrf
            @method('PATCH')
            <input type="hidden" name="profile_only" value="1">

            <x-field name="name" :label="__('account.profile.name')" :value="$user->name" autocomplete="name" />

            <div class="flex flex-col gap-1.5">
                <span class="text-sm font-semibold text-ink">{{ __('account.profile.email') }}</span>
                <p class="m-0 text-sm break-words text-ink-muted">{{ $user->email }}</p>
            </div>

            <x-field name="timezone" :label="__('account.profile.timezone')" :value="$user->timezone" :options="array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers())" :required="true" />
            @if ($soloUnaLingua)
                <input type="hidden" name="locale" value="{{ $linguaScelta }}">
            @else
                <x-field name="locale" :label="__('account.profile.locale')" :value="$linguaScelta" :options="$locales" :required="true" />
            @endif

            <x-form-actions>
                <x-button type="submit">{{ __('account.profile.submit') }}</x-button>
            </x-form-actions>
        </x-card>

        <x-card id="dati" :title="__('account.profile.export')" :description="__('account.profile.export_hint')">
            <div>
                <x-button variant="secondary" :href="route('account.profile.export')">
                    <x-lucide name="download" class="size-4" />
                    {{ __('account.profile.export') }}
                </x-button>
            </div>
        </x-card>

        <x-card id="cancella" tone="alert" :title="__('account.profile.delete_title')" :description="__('account.profile.delete_body')">
            <details class="group" @if ($errors->hasAny(['conferma', 'password'])) open @endif>
            <summary class="ui-action inline-flex min-h-12 cursor-pointer list-none items-center gap-2 border-2 border-line px-4 font-display text-sm font-extrabold text-ink [&::-webkit-details-marker]:hidden group-open:hidden">
                {{ __('account.profile.delete_open') }}
                <x-lucide name="chevron-down" class="size-4" />
            </summary>
            <form method="POST" action="{{ route('account.profile.destroy') }}" class="flex flex-col gap-4">
                @csrf
                @method('DELETE')

                <x-field name="conferma" :label="__('account.profile.delete_confirm')" :required="true" />
                <x-field name="password" type="password" autocomplete="current-password" :label="__('account.profile.delete_password')" :hint="__('account.profile.delete_password_hint')" :required="true" />

                <x-form-actions>
                    <x-button type="submit" variant="danger">{{ __('account.profile.delete_submit') }}</x-button>
                    <x-button variant="ghost" :href="route('account.password.request')">{{ __('account.profile.delete_password_link') }}</x-button>
                </x-form-actions>
            </form>
            </details>
        </x-card>

        <form method="POST" action="{{ route('account.logout') }}" data-profile-logout data-logout>
            @csrf
            <x-button type="submit" variant="secondary" :block="true">{{ __('account.nav.logout') }}</x-button>
        </form>
    </x-service-page>
</x-layouts.app>
