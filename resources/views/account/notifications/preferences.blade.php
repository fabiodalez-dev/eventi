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
    /* Le ore che valgono davvero: le proprie, oppure la finestra predefinita
       di chi non ha ancora scelto (D36). Mostrare i campi vuoti a chi non ha
       scelto racconterebbe un silenzio che non c'è. */
    $quiet = \App\DTOs\QuietHours::formFor($user);
@endphp

<x-layouts.app :narrow="true" :meta="$meta">
    @if ($pushAvailable)
        <x-slot:head>
            @vite('resources/js/push.js')
        </x-slot:head>
    @endif

    <div class="mx-auto flex w-full max-w-xl flex-col gap-8">
        <header class="flex flex-col gap-2">
            <h1 class="text-hero text-ink">{{ $meta->heading }}</h1>
            <p class="text-sm text-ink-muted">{{ __('notifications.preferences.lead') }}</p>
        </header>
        @if (auth()->id() === $user->id)
            <a href="{{ route('account.notifications.interests') }}" class="ui-action border-2 border-accent p-4 font-display font-bold text-accent">{{ __('subscriptions.interests') }} →</a>
        @endif

        @if ($errors->any())
            <p role="alert" class="text-sm">{{ $errors->first() }}</p>
        @endif

        <form method="POST" action="{{ $action }}" class="flex flex-col gap-6">
            @csrf
            @method('PATCH')

            <section class="flex flex-col gap-4 bg-surface p-card">
                <label class="block text-sm">{{ __('subscriptions.delivery') }}
                    <select name="delivery" class="mt-2 w-full border border-line bg-canvas p-3">
                        @foreach (\App\Enums\NotificationDelivery::cases() as $delivery)
                            <option value="{{ $delivery->value }}" @selected($preferences->delivery === $delivery)>{{ __('subscriptions.delivery_options.'.$delivery->value) }}</option>
                        @endforeach
                    </select>
                </label>
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

            <p class="text-sm text-ink-muted">{{ __('subscriptions.mandatory') }}</p>

            <button type="submit" class="self-start bg-brand px-4 py-2.5 text-sm font-semibold text-on-brand transition hover:bg-brand-strong">
                {{ __('notifications.preferences.submit') }}
            </button>
        </form>

        {{--
            Le notifiche sul dispositivo stanno FUORI dal modulo delle
            preferenze, e non e' una scelta di impaginazione: le preferenze si
            salvano con un invio, l'iscrizione push avviene subito nel momento
            in cui il browser concede il permesso. Dentro lo stesso modulo,
            un interruttore che ha gia' avuto effetto starebbe accanto a
            caselle che aspettano il pulsante — e chi lo tocca senza salvare
            non saprebbe piu' quale delle due cose e' successa.

            La sezione compare solo a chi ha una sessione su questo account e
            solo se le chiavi VAPID esistono davvero: un interruttore che il
            server ignora e' peggio di nessun interruttore, ed e' la stessa
            regola che tiene fuori da questa pagina gli avvisi obbligatori.
        --}}
        @if ($pushAvailable)
            <section
                class="flex flex-col gap-3 bg-surface p-card"
                data-push="{{ $pushKey }}"
                data-push-on="{{ __('notifications.push.on') }}"
                data-push-off="{{ __('notifications.push.off') }}"
                data-push-denied="{{ __('notifications.push.denied') }}"
                data-push-unsupported="{{ __('notifications.push.unsupported') }}"
                data-push-failed="{{ __('notifications.push.failed') }}"
            >
                <h2 class="text-sm font-semibold text-ink">{{ __('notifications.push.title') }}</h2>
                <p class="text-xs text-ink-subtle">{{ __('notifications.push.lead') }}</p>

                <label class="flex items-center gap-2.5 text-sm text-ink">
                    <input type="checkbox" data-push-toggle class="size-4 rounded border-line" @checked($pushActive)>
                    {{ __('notifications.push.toggle') }}
                </label>

                <div class="flex flex-wrap gap-3">
                    <x-button variant="secondary" data-push-reset>Riattiva / reimposta questo browser</x-button>
                    <x-button variant="secondary" data-push-test disabled>Mostra notifica di prova</x-button>
                </div>
                <p class="text-xs text-ink-subtle">La prova verifica la visualizzazione su questo dispositivo. Gli avvisi automatici rispettano i canali e gli orari salvati qui sopra.</p>
                <details data-push-help class="text-sm">
                    <summary class="cursor-pointer py-3 font-semibold">Come reimpostare i permessi</summary>
                    <ol class="list-decimal space-y-2 pl-5">
                        <li>Chrome, Edge o Firefox: apri l’icona accanto all’indirizzo del sito, poi Permessi o Impostazioni sito → Notifiche. Scegli Consenti oppure reimposta il permesso.</li>
                        <li>Safari su Mac: Safari → Impostazioni → Siti web → Notifiche, quindi consenti inCittà.</li>
                        <li>Su iPhone e iPad: aggiungi inCittà alla schermata Home e aprila da lì. Controlla anche Impostazioni → Notifiche → inCittà.</li>
                        <li>Torna qui e premi “Riattiva / reimposta questo browser”.</li>
                    </ol>
                    <p class="mt-3 text-ink-muted">Per proteggere la tua scelta, il sito non può cancellare un rifiuto imposto dal browser. Il comando rinnova soltanto l’iscrizione di questo browser.</p>
                </details>

                {{-- Su iPhone le push web arrivano solo a un sito installato
                     sulla schermata Home: senza questa riga il permesso viene
                     concesso e non arriva mai niente. --}}
                <p class="text-xs text-ink-subtle">{{ __('notifications.push.ios') }}</p>

                <p class="text-xs text-ink-subtle" data-push-status aria-live="polite">
                    {{ $pushActive ? __('notifications.push.on') : '' }}
                </p>
            </section>
        @else
            <p class="text-xs text-ink-subtle">{{ auth()->id() === $user->id ? __('notifications.push.unavailable') : __('notifications.push.signed_out') }}</p>
        @endif

    </div>
</x-layouts.app>
