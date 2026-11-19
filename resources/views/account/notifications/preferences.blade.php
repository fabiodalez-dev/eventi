{{--
    Le preferenze di notifica raggiunte dal collegamento firmato in fondo a
    un'email (§15.9): nessun accesso richiesto.

    Il modulo elenca soltanto ciò che si può spegnere. Gli annullamenti e i
    cambi di orario non compaiono perché non hanno interruttore (§15.4), e la
    pagina lo dice: un interruttore che il server ignora è peggio di nessun
    interruttore.

    Una card per argomento — come arrivano, cosa arriva, la proposta della
    sera, le ore di silenzio, la newsletter — e un solo pulsante in fondo.
--}}
@php
    $preferences = $user->notificationPreferences();
    /* Le ore che valgono davvero: le proprie, oppure la finestra predefinita
       di chi non ha ancora scelto (D36). Mostrare i campi vuoti a chi non ha
       scelto racconterebbe un silenzio che non c'è. */
    $quiet = \App\DTOs\QuietHours::formFor($user);
    $signedIn = auth()->id() === $user->id;
    $checkbox = 'mt-0.5 size-5 shrink-0 accent-accent';
    $toggles = [
        'reminders' => [$preferences->reminders, __('account.profile.reminders')],
        'sold_out' => [$preferences->soldOut, __('account.profile.sold_out')],
        'comments' => [$preferences->comments, __('comments.preference')],
        'venue_digest' => [$preferences->venueDigest, __('account.profile.venue_digest')],
        'daily_digest' => [$preferences->dailyDigest, __('account.profile.daily_digest')],
    ];
@endphp

<x-layouts.app service :meta="$meta">
    @if ($pushAvailable)
        <x-slot:head>
            @vite('resources/js/push.js')
        </x-slot:head>
    @endif

    <x-service-page :title="$meta->heading" :subtitle="__('notifications.preferences.lead')" :back="$signedIn ? route('account.profile') : null">
        @if ($signedIn)
            <x-card padding="none">
                <x-card.row :href="route('account.notifications.interests')" :title="__('subscriptions.interests')" :description="__('notifications.preferences.interests_hint')" icon="filter" />
            </x-card>
        @else
            {{-- Senza sessione si può solo ridurre: il server ignora ciò che accende, e la pagina lo dice. --}}
            <x-notice>
                <p class="m-0">{{ __('notifications.preferences.signed_only') }}</p>
                <div><x-button size="sm" variant="secondary" :href="route('login')">{{ __('notifications.preferences.signed_only_login') }}</x-button></div>
            </x-notice>
        @endif

        <form method="POST" action="{{ $action }}" class="flex flex-col gap-6" data-notification-preferences>
            @csrf
            @method('PATCH')

            <x-card :title="__('notifications.preferences.delivery_title')">
                <x-field
                    name="delivery"
                    :label="__('subscriptions.delivery')"
                    :value="$preferences->delivery->value"
                    :options="collect(\App\Enums\NotificationDelivery::cases())->mapWithKeys(fn ($delivery) => [$delivery->value => __('subscriptions.delivery_options.'.$delivery->value)])->all()"
                />
            </x-card>

            <x-card :title="__('notifications.preferences.what_title')" :description="__('subscriptions.mandatory')">
                <div class="flex flex-col gap-1">
                    @foreach ($toggles as $name => [$checked, $label])
                        <label class="flex min-h-12 items-start gap-3 py-1.5 text-ink">
                            <input type="checkbox" name="{{ $name }}" value="1" class="{{ $checkbox }}" @checked($checked)>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                    @if ($managesVenues)
                        <label class="flex min-h-12 items-start gap-3 py-1.5 text-ink">
                            <input type="checkbox" name="venue_report" value="1" class="{{ $checkbox }}" @checked($preferences->venueReport)>
                            <span>{{ __('account.profile.venue_report') }}</span>
                        </label>
                    @endif
                </div>
                <x-field name="daily_digest_time" type="time" :label="__('notifications.preferences.daily_digest_time')" :value="$user->daily_digest_time" />
            </x-card>

            {{--
                La proposta della sera: l'interruttore da solo non basta,
                perché «quando» fa parte della proposta quanto «cosa». Ora e
                giorni stanno quindi nella stessa card, e restano leggibili
                anche da spenti: chi la accende vede subito che cosa ha acceso.
            --}}
            <x-card :title="__('notifications.preferences.tonight_title')" :description="__('account.profile.tonight_hint')">
                <label class="flex min-h-12 items-start gap-3 py-1.5 font-semibold text-ink">
                    <input type="checkbox" name="tonight" value="1" class="{{ $checkbox }}" @checked($preferences->tonight)>
                    <span>{{ __('account.profile.tonight') }}</span>
                </label>

                <x-field name="tonight_time" type="time" :label="__('account.profile.tonight_time')" :value="$preferences->tonightTime" />

                <fieldset class="m-0 flex min-w-0 flex-col gap-2 border-0 p-0">
                    <legend class="mb-2 text-sm font-semibold text-ink">{{ __('account.profile.tonight_days') }}</legend>
                    <div class="flex flex-wrap gap-2">
                        @foreach (__('account.profile.weekdays') as $number => $weekday)
                            <label class="flex min-h-11 items-center gap-2 border-2 border-line px-3 text-sm text-ink has-checked:border-accent">
                                <input type="checkbox" name="tonight_days[]" value="{{ $number }}" class="size-4 shrink-0 accent-accent" @checked(in_array((int) $number, $preferences->tonightDays, true))>
                                {{ $weekday }}
                            </label>
                        @endforeach
                    </div>
                    @error('tonight_days')
                        <p class="m-0 text-sm font-semibold text-alert">{{ $message }}</p>
                    @enderror
                </fieldset>
            </x-card>

            <x-card :title="__('account.profile.quiet_hours')" :description="__('account.profile.quiet_hours_hint')">
                <div class="flex flex-col gap-4 sm:flex-row [&>*]:flex-1">
                    <x-field name="quiet_from" type="time" :label="__('account.profile.quiet_from')" :value="$quiet['from']" />
                    <x-field name="quiet_to" type="time" :label="__('account.profile.quiet_to')" :value="$quiet['to']" />
                </div>
                <label class="flex min-h-12 items-start gap-3 py-1.5 text-ink">
                    <input type="checkbox" name="quiet_off" value="1" class="{{ $checkbox }}" @checked(old('quiet_off', $quiet['off']))>
                    <span>{{ __('account.profile.quiet_off') }}</span>
                </label>
            </x-card>

            @newsletter
                {{-- Il consenso si dà solo con una sessione; dal collegamento firmato si può solo revocare. --}}
                @if ($own || $user->marketing_opt_in_at !== null)
                    <x-card :title="__('notifications.preferences.newsletter_title')">
                        <label class="flex min-h-12 items-start gap-3 py-1.5 text-ink">
                            <input type="checkbox" name="marketing_opt_in" value="1" class="{{ $checkbox }}" @checked($user->marketing_opt_in_at !== null)>
                            <span>{{ __('account.profile.marketing') }}</span>
                        </label>
                    </x-card>
                @endif
            @endnewsletter

            <x-form-actions>
                <x-button type="submit">{{ __('notifications.preferences.submit') }}</x-button>
            </x-form-actions>
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
            <x-card
                :title="__('notifications.push.title')"
                :description="__('notifications.push.lead')"
                data-push="{{ $pushKey }}"
                data-push-on="{{ __('notifications.push.on') }}"
                data-push-off="{{ __('notifications.push.off') }}"
                data-push-denied="{{ __('notifications.push.denied') }}"
                data-push-unsupported="{{ __('notifications.push.unsupported') }}"
                data-push-failed="{{ __('notifications.push.failed') }}"
            >
                <label class="flex min-h-12 items-start gap-3 py-1.5 font-semibold text-ink">
                    <input type="checkbox" data-push-toggle class="{{ $checkbox }}" @checked($pushActive)>
                    <span>{{ __('notifications.push.toggle') }}</span>
                </label>

                <p class="m-0 text-sm text-ink-muted" data-push-status aria-live="polite">{{ $pushActive ? __('notifications.push.on') : '' }}</p>

                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <x-button variant="secondary" :block="true" data-push-reset>{{ __('notifications.push.reset') }}</x-button>
                    <x-button variant="secondary" :block="true" data-push-test disabled>{{ __('notifications.push.test') }}</x-button>
                </div>
                <p class="m-0 text-sm text-ink-muted">{{ __('notifications.push.test_help') }}</p>

                <details data-push-help class="text-sm text-ink">
                    <summary class="flex min-h-12 cursor-pointer items-center font-semibold">{{ __('notifications.push.help_title') }}</summary>
                    <ol class="m-0 flex list-decimal flex-col gap-2 pl-5">
                        @foreach (__('notifications.push.help_steps') as $step)
                            <li>{{ $step }}</li>
                        @endforeach
                    </ol>
                    <p class="mt-3 mb-0 text-ink-muted">{{ __('notifications.push.help_note') }}</p>
                </details>

                {{-- Su iPhone le push web arrivano solo a un sito installato
                     sulla schermata Home: senza questa riga il permesso viene
                     concesso e non arriva mai niente. --}}
                <p class="m-0 text-sm text-ink-muted">{{ __('notifications.push.ios') }}</p>
            </x-card>
        @else
            <x-notice><p class="m-0">{{ $signedIn ? __('notifications.push.unavailable') : __('notifications.push.signed_out') }}</p></x-notice>
        @endif
    </x-service-page>
</x-layouts.app>
