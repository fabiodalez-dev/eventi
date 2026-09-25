@php
    $locales = collect(config('account.locales'))->mapWithKeys(fn (string $code) => [$code => strtoupper($code)])->all();
    $soloUnaLingua = count($locales) < 2;
    $linguaScelta = array_key_exists($user->locale, $locales) ? $user->locale : (string) array_key_first($locales);
@endphp

<x-layouts.app :narrow="true" :meta="$meta">
    <div class="flex w-full flex-col gap-8">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">{{ __('account.profile.lead') }}</p>

            @unless ($user->hasVerifiedEmail())
                <p class="bg-surface px-4 py-3 text-sm text-ink-muted">
                    <span class="font-semibold text-ink">{{ __('account.verify.pending') }}</span>
                    <span aria-hidden="true">{{ __('common.separator') }}</span>
                    <a class="font-semibold text-brand hover:underline" href="{{ route('verification.notice') }}">{{ __('account.verify.resend') }}</a>
                </p>
            @endunless
        </header>

        @if(config('community.enabled'))
            @php($publicProfile = $user->communityProfile)
            <section class="space-y-3 border-y border-line py-5" aria-labelledby="public-profile">
                <h2 id="public-profile" class="text-section">{{ __('community.settings') }}</h2>
                <p class="text-sm text-ink-muted">{{ __('community.account_identity_help') }}</p>
                @if($publicProfile)<p>{{ $publicProfile->display_name }} · {{ '@'.$publicProfile->handle }} · {{ __('community.profile_visibility.'.$publicProfile->visibility->value) }}</p>@endif
                <div class="flex flex-wrap gap-3">
                    <x-button :href="route('community.settings')">{{ __($publicProfile ? 'community.edit_profile' : 'community.create_profile') }}</x-button>
                    @if($publicProfile)<x-button variant="secondary" :href="route('community.profile', $publicProfile->handle)">{{ __('community.visit_profile') }}</x-button>@endif
                </div>
            </section>
        @endif
        @foreach([
            'activities' => [['account.saved','saved'], ['tickets.index','tickets'], ['account.feed','feed']],
            'people_group' => [['community.feed','community'], ['community.people','people'], ['community.followers','relationships'], ['community.inbox','inbox']],
            'preferences' => [['account.content-preferences','interests'], ['account.notifications','notifications'], ['google-calendar.index','calendar'], ['community.whatsapp','verification']]
        ] as $group => $items)
            <section aria-labelledby="area-{{ $group }}">
                <h2 id="area-{{ $group }}" class="text-section">{{ __('community.area.'.$group) }}</h2>
                <nav class="mt-3 divide-y divide-line border-y border-line" aria-label="{{ __('community.area.'.$group) }}">
                    @foreach($items as [$destination, $label])
                        @continue(!config('community.enabled') && str_starts_with($destination, 'community.'))
                        <a href="{{ route($destination) }}" class="flex min-h-14 items-center justify-between gap-3 py-3 font-semibold hover:text-brand"><span>{{ __('community.area.'.$label) }}</span><span aria-hidden="true">→</span></a>
                    @endforeach
                </nav>
            </section>
        @endforeach
        @if(config('carpool.enabled'))
        <section class="space-y-4 border-y border-line py-6" aria-labelledby="profile-carpool"><h2 id="profile-carpool" class="text-section">{{ __('carpool.title') }}</h2>
            {{-- Chi non ha i requisiti lo scopre qui, prima di arrivare al modulo di un passaggio: il pulsante porta al passo che manca. --}}
            @php($carpoolAccess = app(\App\Services\Carpool\CarpoolAccess::class)->state(auth()->user()))
            @unless($carpoolAccess['eligible'])
                @php($carpoolStep = match ($carpoolAccess['reason']) { 'email' => route('verification.notice'), 'whatsapp' => config('community.enabled') ? route('community.whatsapp') : route('carpool.requirements'), 'suspended' => route('carpool.cases'), default => route('carpool.requirements') })
                <div role="status" class="flex flex-col gap-3 border border-line bg-surface p-4 sm:flex-row sm:items-center sm:justify-between" data-carpool-requirements-warning="{{ $carpoolAccess['reason'] }}">
                    <p class="text-sm"><strong class="block font-semibold">{{ __('carpool.profile_blocked.title') }}</strong><span class="mt-1 block text-ink-muted">{{ __('carpool.profile_blocked.'.$carpoolAccess['reason']) }}</span></p>
                    <x-button :href="$carpoolStep">{{ __($carpoolAccess['reason'] === 'suspended' ? 'carpool.support' : 'carpool.profile_blocked.action') }}</x-button>
                </div>
            @endunless<div class="flex flex-wrap gap-4">@foreach(['carpool.index' => 'mine', 'carpool.chats' => 'messages', 'carpool.requirements' => 'requirements'] as $destination => $label)<x-button variant="secondary" :href="route($destination)">{{ __('carpool.'.$label) }}</x-button>@endforeach</div></section>
        @endif
        <details class="border-y border-line py-4">
            <summary class="min-h-12 cursor-pointer content-center font-semibold">{{ __('community.appearance') }}</summary>
            <x-appearance-picker />
        </details>
        @if(auth()->user()->managedOrganizers()->exists())
            <x-button :href="url('/organizza')" variant="secondary">Gestisci i tuoi organizzatori</x-button>
        @endif
        @if ($user->isEditorialStaff())
            <x-button :href="url('/admin')" variant="secondary">{{ __('account.nav.admin') }}</x-button>
        @endif
        @if ($user->venues()->exists())
            <x-button :href="url('/gestione')" variant="secondary">{{ __('account.nav.venue') }}</x-button>
        @endif
        @if ($user->ownedVenues()->exists() || \App\Models\EventOccurrence::whereHas('checkinStaff', fn ($q) => $q->whereKey($user->id))->exists() || $user->hasAnyRole(['admin', 'super_admin']))
            <x-button :href="route('ticketing.manage.index')" variant="secondary">{{ __('ticketing.manage') }}</x-button>
        @endif
        @if ($errors->any())<p role="alert" class="text-sm">{{ $errors->first() }}</p>@endif

        <form method="POST" action="{{ route('account.profile.update') }}" class="flex flex-col gap-6">
            @csrf
            @method('PATCH')
            <input type="hidden" name="profile_only" value="1">
            <h2 class="text-section">{{ __('community.account_details') }}</h2>

            <section class="flex flex-col gap-4">
                <x-field name="name" :label="__('account.profile.name')" :value="$user->name" autocomplete="name" />

                <div class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold text-ink">{{ __('account.profile.email') }}</span>
                    <p class="text-sm text-ink-muted">{{ $user->email }}</p>
                </div>

                <x-field name="timezone" :label="__('account.profile.timezone')" :value="$user->timezone" :options="array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers())" :required="true" />
                @if ($soloUnaLingua)
                    <input type="hidden" name="locale" value="{{ $linguaScelta }}">
                @else
                    <x-field name="locale" :label="__('account.profile.locale')" :value="$linguaScelta" :options="$locales" :required="true" />
                @endif
            </section>

            <button type="submit" class="self-start bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('account.profile.submit') }}
            </button>
        </form>

        <details class="border-y border-line py-4" @if($errors->has('conferma')) open @endif>
        <summary class="min-h-12 cursor-pointer content-center font-semibold">{{ __('community.account_privacy') }}</summary>
        <div class="mt-5 space-y-6">
        <section aria-labelledby="dati" class="flex flex-col gap-2">
            <h2 id="dati" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('account.profile.export') }}</h2>
            <p class="text-sm text-ink-muted">{{ __('account.profile.export_hint') }}</p>

            <a
                href="{{ route('account.profile.export') }}"
                class="ui-action self-start bg-surface px-4 py-2.5 text-sm font-semibold text-ink border-2 border-line transition hover:border-accent"
            >
                {{ __('account.profile.export') }}
            </a>
        </section>

        <section aria-labelledby="cancella" class="flex flex-col gap-3 border border-live/40 p-card">
            <h2 id="cancella" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('account.profile.delete_title') }}</h2>
            <p class="text-sm text-ink-muted">{{ __('account.profile.delete_body') }}</p>

            <form method="POST" action="{{ route('account.profile.destroy') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                @method('DELETE')

                <x-field name="conferma" :label="__('account.profile.delete_confirm')" :required="true" class="min-w-56" />

                <button type="submit" class="bg-live px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:opacity-90">
                    {{ __('account.profile.delete_submit') }}
                </button>
            </form>
        </section>
        </div></details>
        <form method="POST" action="{{ route('account.logout') }}" data-profile-logout>
            @csrf
            <button type="submit" class="min-h-11 border-2 border-line px-4 py-2 font-semibold text-ink hover:border-brand">{{ __('account.nav.logout') }}</button>
        </form>
    </div>
</x-layouts.app>
