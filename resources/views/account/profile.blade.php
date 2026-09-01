{{--
    Il profilo (§15.2) con le preferenze di notifica (§15.4) e i due diritti
    che gli stanno accanto (§15.9): scaricare i propri dati e cancellare
    l'account.

    Gli avvisi di annullamento non hanno un interruttore, e la pagina dice
    perché: §15.4 li dichiara «attivi, non disattivabili». Un interruttore che
    il server ignora è peggio di nessun interruttore.
--}}
@php
    $preferences = $user->notificationPreferences();
    /* Le ore che valgono davvero: le proprie, oppure la finestra predefinita
       di chi non ha ancora scelto (D36). Mostrare i campi vuoti a chi non ha
       scelto racconterebbe un silenzio che non c'è. */
    $quiet = \App\DTOs\QuietHours::formFor($user);
    $locales = collect(config('account.locales'))->mapWithKeys(fn (string $code) => [$code => strtoupper($code)])->all();
@endphp

<x-layouts.app :meta="$meta">
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-8">
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

        <form method="POST" action="{{ route('account.profile.update') }}" class="flex flex-col gap-6">
            @csrf
            @method('PATCH')

            <section class="flex flex-col gap-4">
                <x-field name="name" :label="__('account.profile.name')" :value="$user->name" autocomplete="name" />

                <div class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold text-ink">{{ __('account.profile.email') }}</span>
                    <p class="text-sm text-ink-muted">{{ $user->email }}</p>
                </div>

                <x-field name="timezone" :label="__('account.profile.timezone')" :value="$user->timezone" :required="true" />
                <x-field name="locale" :label="__('account.profile.locale')" :value="$user->locale" :options="$locales" :required="true" />
            </section>

            <section aria-labelledby="preferenze" class="flex flex-col gap-4 bg-surface p-card">
                <h2 id="preferenze" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('account.profile.notifications_title') }}</h2>
                <p class="text-sm text-ink-muted">{{ __('account.profile.notifications_lead') }}</p>

                <label class="flex items-center gap-2.5 text-sm text-ink">
                    <input type="checkbox" name="reminders" value="1" class="size-4 rounded border-line" @checked($preferences->reminders)>
                    {{ __('account.profile.reminders') }}
                </label>

                <label class="flex items-center gap-2.5 text-sm text-ink">
                    <input type="checkbox" name="sold_out" value="1" class="size-4 rounded border-line" @checked($preferences->soldOut)>
                    {{ __('account.profile.sold_out') }}
                </label>

                <label class="flex items-center gap-2.5 text-sm text-ink">
                    <input type="checkbox" name="venue_digest" value="1" class="size-4 rounded border-line" @checked($preferences->venueDigest)>
                    {{ __('account.profile.venue_digest') }}
                </label>

                <label class="flex items-center gap-2.5 text-sm text-ink">
                    <input type="checkbox" name="daily_digest" value="1" class="size-4 rounded border-line" @checked($preferences->dailyDigest)>
                    {{ __('account.profile.daily_digest') }}
                </label>

                <x-field
                    name="daily_digest_time"
                    type="time"
                    :label="__('account.profile.daily_digest')"
                    :value="$user->daily_digest_time"
                />

                <fieldset class="flex flex-col gap-2">
                    <legend class="text-sm font-semibold text-ink">{{ __('account.profile.quiet_hours') }}</legend>
                    <p class="text-xs text-ink-subtle">{{ __('account.profile.quiet_hours_hint') }}</p>

                    <div class="flex flex-wrap items-end gap-3">
                        <x-field name="quiet_from" type="time" :label="__('account.profile.quiet_from')" :value="$quiet['from']" />
                        <x-field name="quiet_to" type="time" :label="__('account.profile.quiet_to')" :value="$quiet['to']" />
                    </div>

                    <label class="flex items-center gap-2.5 text-sm text-ink">
                        <input type="checkbox" name="quiet_off" value="1" class="size-4 rounded border-line" @checked(old('quiet_off', $quiet['off']))>
                        {{ __('account.profile.quiet_off') }}
                    </label>
                </fieldset>

                @newsletter
                <label class="flex items-center gap-2.5 text-sm text-ink">
                    <input type="checkbox" name="marketing_opt_in" value="1" class="size-4 rounded border-line" @checked($user->marketing_opt_in_at !== null)>
                    {{ __('account.profile.marketing') }}
                </label>
                @endnewsletter
            </section>

            <button type="submit" class="self-start bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('account.profile.submit') }}
            </button>
        </form>

        <section aria-labelledby="dati" class="flex flex-col gap-2">
            <h2 id="dati" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">{{ __('account.profile.export') }}</h2>
            <p class="text-sm text-ink-muted">{{ __('account.profile.export_hint') }}</p>

            <a
                href="{{ route('account.profile.export') }}"
                class="self-start bg-surface px-4 py-2.5 text-sm font-semibold text-ink border-2 border-line transition hover:border-accent"
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
    </div>
</x-layouts.app>
