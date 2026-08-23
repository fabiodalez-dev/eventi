{{--
    Le preferenze di notifica raggiunte dal collegamento firmato in fondo a
    un'email (§15.9): nessun accesso richiesto.

    Il modulo elenca soltanto ciò che si può spegnere. Gli annullamenti e i
    cambi di orario non compaiono perché non hanno interruttore (§15.4), e la
    pagina lo dice: un interruttore che il server ignora è peggio di nessun
    interruttore.
--}}
@php
    $preferences = $user->notificationPreferences();
    $quiet = is_array($user->quiet_hours) ? $user->quiet_hours : [];
@endphp

<x-layouts.app :meta="$meta">
    <div class="mx-auto flex w-full max-w-xl flex-col gap-8">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">{{ __('notifications.preferences.lead') }}</p>
        </header>

        @if (session('status'))
            <p class="rounded-card bg-surface-sunken px-4 py-3 text-sm font-semibold text-ink">{{ session('status') }}</p>
        @endif

        <form method="POST" action="{{ $action }}" class="flex flex-col gap-6">
            @csrf
            @method('PATCH')

            <section class="flex flex-col gap-4 rounded-card bg-surface-sunken p-card">
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

                <fieldset class="flex flex-wrap items-end gap-3">
                    <legend class="text-sm font-semibold text-ink">{{ __('account.profile.quiet_hours') }}</legend>

                    <x-field name="quiet_from" type="time" :label="__('account.profile.quiet_from')" :value="$quiet['from'] ?? null" />
                    <x-field name="quiet_to" type="time" :label="__('account.profile.quiet_to')" :value="$quiet['to'] ?? null" />
                </fieldset>

                <label class="flex items-center gap-2.5 text-sm text-ink">
                    <input type="checkbox" name="marketing_opt_in" value="1" class="size-4 rounded border-line" @checked($user->marketing_opt_in_at !== null)>
                    {{ __('account.profile.marketing') }}
                </label>
            </section>

            <p class="text-sm text-ink-muted">{{ __('notifications.preferences.mandatory') }}</p>

            <button type="submit" class="self-start rounded-pill bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('notifications.preferences.submit') }}
            </button>
        </form>
    </div>
</x-layouts.app>
