{{-- `/registra-il-tuo-locale` (§11.1), e con lo stesso modulo la rivendicazione
     di un locale già a catalogo (`claim`) e la richiesta di un organizzatore
     senza sede (`organizer`): cambiano destinazione, card mostrate e testi.

     Pagina di servizio: colonna unica, una card per argomento, un solo
     pulsante d'invio a tutta larghezza sul telefono. Chi non è collegato
     sceglie una password (o accede, ritrovando quello che ha scritto); chi
     lo è manda la richiesta col suo account. --}}
@use('App\Http\Requests\Web\StoreVenueApplicationRequest')
@php
    $claim = $claim ?? null;
    $mode = $mode ?? ($claim ? 'claim' : (($organizer ?? false) ? 'organizer' : 'venue'));
    $awaiting = $awaiting ?? collect();
    $inReview = $inReview ?? collect();
    $user = auth()->user();
    $guest = $user === null;
    $needsAccount = $guest && $mode !== 'organizer';
    $sent = session('application_sent');

    $action = match ($mode) {
        'claim' => route('venues.claim.store', $claim),
        'organizer' => route('organizer-applications.store'),
        default => route('venue-applications.store'),
    };
    $draftAction = $mode === 'claim' ? route('venues.claim.draft', $claim) : route('venue-applications.draft');
    $formUrl = match ($mode) {
        'claim' => route('venues.claim.create', $claim),
        'organizer' => route('organizer-applications.create'),
        default => route('venue-applications.create'),
    };
    $turnstile = $mode === 'organizer' ? 'registrazione-organizzatore' : 'registrazione-locale';
    $submit = match ($mode) {
        'claim' => __('forms.claim.submit'),
        'organizer' => __('forms.organizer_application.submit'),
        default => __('forms.application.submit'),
    };
    $started = ! $errors->has(StoreVenueApplicationRequest::STARTED_FIELD) && is_string(old(StoreVenueApplicationRequest::STARTED_FIELD))
        ? old(StoreVenueApplicationRequest::STARTED_FIELD)
        : StoreVenueApplicationRequest::startedToken();
    $steps = match ($mode) {
        'claim' => [__('forms.application.sections.next.confirm'), __('forms.application.sections.claim_next.review'), __('forms.application.sections.claim_next.panel')],
        'organizer' => [__('forms.application.sections.organizer_next.review'), __('forms.application.sections.organizer_next.panel')],
        default => array_values((array) __('forms.application.sections.next')),
    };
    /* Chi è collegato con l'email già confermata salta il primo passo. */
    if ($mode !== 'organizer' && $user?->hasVerifiedEmail()) {
        $steps = array_values(array_filter($steps, static fn (string $step): bool => $step !== __('forms.application.sections.next.confirm')));
    }
    $control = 'min-h-12 w-full border border-line bg-surface px-3.5 py-2.5 text-sm text-ink focus:border-brand focus:outline-none';
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$meta->heading"
        :subtitle="$meta->description"
        :back="$claim ? route('venues.show', $claim) : null"
        :back-label="$claim ? __('forms.claim.back', ['venue' => $claim->name]) : null"
    >
        {{-- Le richieste di chi è collegato: da confermare o in valutazione. --}}
        @foreach ($awaiting as $pending)
            <x-notice tone="alert" :title="__('forms.application.account_state.awaiting_title')" data-awaiting-application>
                @if ($user?->hasVerifiedEmail())
                    <p>{{ __('forms.application.account_state.awaiting_body', ['venue' => $pending->venue_name]) }}</p>
                    <div class="flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('venue-applications.confirm', $pending->getKey()) }}">
                            @csrf
                            <x-button type="submit" size="sm">{{ __('forms.application.account_state.confirm') }}</x-button>
                        </form>
                        <form method="POST" action="{{ route('venue-applications.discard', $pending->getKey()) }}">
                            @csrf
                            @method('DELETE')
                            <x-button type="submit" size="sm" variant="secondary">{{ __('forms.application.account_state.discard') }}</x-button>
                        </form>
                    </div>
                @else
                    <p>{{ __('forms.application.account_state.awaiting_unverified', ['venue' => $pending->venue_name]) }}</p>
                    <form method="POST" action="{{ route('account.verification.send') }}">
                        @csrf
                        <x-button type="submit" size="sm" variant="secondary">{{ __('forms.application.account_state.resend') }}</x-button>
                    </form>
                @endif
            </x-notice>
        @endforeach

        @foreach (is_string($sent) ? [] : $inReview as $pending)
            <x-notice tone="info">{{ __('forms.application.account_state.in_review', ['venue' => $pending->venue_name, 'date' => $pending->email_confirmed_at?->timezone(config('app.timezone'))->translatedFormat('j F Y') ?? '']) }}</x-notice>
        @endforeach

        @if (is_string($sent))
            {{-- Dopo l'invio: cosa succede adesso, e niente modulo da rifare. --}}
            <x-card tone="accent" :title="__('forms.application.sent_page.'.$sent.'_title')" data-application-sent="{{ $sent }}">
                <ol class="flex flex-col gap-3">
                    @foreach ($steps as $step)
                        <li class="flex gap-3 text-sm text-ink-muted">
                            <span class="flex size-7 shrink-0 items-center justify-center border border-line font-display text-sm font-extrabold text-ink">{{ $loop->iteration }}</span>
                            <span class="pt-1">{{ $step }}</span>
                        </li>
                    @endforeach
                </ol>
                <x-slot:footer>
                    <x-form-actions>
                        @if ($sent === 'verify')
                            <form method="POST" action="{{ route('account.verification.send') }}" class="w-full sm:w-auto">
                                @csrf
                                <x-button type="submit" :block="true">{{ __('forms.application.sent_page.verify_action') }}</x-button>
                            </form>
                        @else
                            <x-button :href="route('home')" :block="true">{{ __('forms.application.sent_page.events') }}</x-button>
                        @endif
                        @if ($claim)
                            <x-button variant="secondary" :href="route('venues.show', $claim)" :block="true">{{ __('forms.claim.back', ['venue' => $claim->name]) }}</x-button>
                        @else
                            <x-button variant="secondary" :href="$formUrl" :block="true">{{ __('forms.application.sent_page.another') }}</x-button>
                        @endif
                    </x-form-actions>
                </x-slot:footer>
            </x-card>
        @else
            @if ($mode === 'venue')
                {{-- Prima di registrare: il locale c'è già? --}}
                <x-card :title="__('forms.application.catalogue.title')" :description="__('forms.application.catalogue.description')" data-catalogue-search>
                    <form method="GET" action="{{ route('venue-applications.create') }}" role="search" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                            <label for="cerca-locale" class="text-sm font-semibold text-ink">{{ __('forms.application.catalogue.label') }}</label>
                            <input id="cerca-locale" type="search" name="cerca" value="{{ $search ?? '' }}" minlength="3" maxlength="80" autocomplete="off" placeholder="{{ __('forms.application.catalogue.placeholder') }}" class="{{ $control }} placeholder:text-ink-subtle">
                        </div>
                        <x-button type="submit" variant="secondary" :block="true">
                            <x-lucide name="search" class="size-4" />
                            {{ __('forms.application.catalogue.submit') }}
                        </x-button>
                    </form>

                    @if (filled($search ?? null) && $results === null)
                        <p class="text-sm text-ink-muted">{{ __('forms.application.catalogue.short') }}</p>
                    @elseif ($results !== null && $results->isEmpty())
                        <p class="text-sm text-ink-muted" role="status">{{ __('forms.application.catalogue.none') }}</p>
                    @elseif ($results !== null)
                        <ul class="flex flex-col border-t border-line" aria-label="{{ __('forms.application.catalogue.results') }}" data-catalogue-results>
                            @foreach ($results as $found)
                                <li class="flex flex-col gap-2 border-b border-line py-3 sm:flex-row sm:items-center sm:justify-between">
                                    <span class="flex min-w-0 flex-col gap-0.5">
                                        <a href="{{ route('venues.show', $found) }}" class="font-semibold text-ink hover:underline">{{ $found->name }}</a>
                                        <span class="text-sm text-ink-muted">{{ collect([$found->address, $found->municipality])->filter()->implode(', ') }}</span>
                                    </span>
                                    @if ($found->isClaimable())
                                        <x-button size="sm" variant="secondary" class="self-start sm:self-auto" :href="route('venues.claim.create', $found)">{{ __('forms.application.catalogue.claim') }}</x-button>
                                    @else
                                        <span class="text-sm text-ink-subtle">{{ __('forms.application.catalogue.managed') }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-card>
            @endif

            <form method="POST" action="{{ $action }}" @if ($mode === 'venue') enctype="multipart/form-data" @endif class="flex min-w-0 flex-col gap-6" data-application-form="{{ $mode }}">
                @csrf
                <x-honeypot />
                <input type="hidden" name="{{ StoreVenueApplicationRequest::STARTED_FIELD }}" value="{{ $started }}">

                @if ($needsAccount)
                    <x-card tone="muted" :title="__('forms.application.sections.login_title')" :description="__('forms.application.sections.login_description')" data-login-card>
                        <x-slot:actions>
                            <x-button type="submit" variant="secondary" formaction="{{ $draftAction }}" formnovalidate :block="true">
                                {{ __('forms.application.sections.login_action') }}
                            </x-button>
                        </x-slot:actions>
                    </x-card>
                @endif

                @if ($mode === 'claim')
                    <x-card :title="__('forms.claim.venue_title')" :description="__('forms.claim.venue_description')">
                        <input type="hidden" name="venue_name" value="{{ $claim->name }}">
                        <div class="flex flex-col gap-1">
                            <p class="font-display text-xl leading-tight font-extrabold text-ink">{{ $claim->name }}</p>
                            <p class="text-sm text-ink-muted">{{ collect([$claim->type?->label(), $claim->address, $claim->municipality])->filter()->implode(' · ') }}</p>
                        </div>
                        <div>
                            <x-button variant="ghost" :href="route('venues.show', $claim)">{{ __('forms.claim.open_venue') }} <x-lucide name="arrow-right" class="size-4" /></x-button>
                        </div>
                    </x-card>
                @elseif ($mode === 'organizer')
                    <x-card :title="__('forms.organizer_application.organization_title')" :description="__('forms.organizer_application.organization_description')">
                        <x-field name="venue_name" :label="__('forms.organizer_application.fields.venue_name')" :required="true" />
                        <x-field type="url" name="website" :label="__('forms.organizer_application.fields.website')" placeholder="https://" />
                        <x-field type="textarea" name="message" rows="4" :label="__('forms.organizer_application.fields.message')" :hint="__('forms.organizer_application.hint_message')" />
                    </x-card>
                @else
                    <x-card :title="__('forms.application.sections.venue_title')" :description="__('forms.application.sections.venue_description')">
                        <x-field name="venue_name" :label="__('forms.application.fields.venue_name')" :required="true" />
                        <x-field name="type" :label="__('forms.application.fields.type')" :options="$types" :placeholder-option="__('forms.application.type_unknown')" />
                    </x-card>

                    <x-card :title="__('forms.application.sections.where_title')" :description="__('forms.application.sections.where_description')">
                        <x-field name="address" :label="__('forms.application.fields.address')" :hint="__('forms.application.hints.address')" :required="true" autocomplete="street-address" />
                        <x-field name="postal_code" :label="__('forms.application.fields.postal_code')" autocomplete="postal-code" />
                        <x-field name="municipality" :label="__('forms.application.fields.municipality')" :value="$city->name" autocomplete="address-level2" />
                    </x-card>

                    <x-card :title="__('forms.application.sections.public_title')" :description="__('forms.application.sections.public_description')">
                        <x-field type="url" name="website" :label="__('forms.application.fields.website')" placeholder="https://" />
                        <x-field type="email" name="public_email" :label="__('forms.application.fields.public_email')" />
                        <x-field type="tel" name="public_phone" :label="__('forms.application.fields.public_phone')" :hint="__('forms.application.hints.public_phone')" />
                        <x-field type="url" name="instagram" :label="__('forms.application.fields.instagram')" :hint="__('forms.application.hints.social')" placeholder="https://instagram.com/" />
                        <x-field type="url" name="facebook" :label="__('forms.application.fields.facebook')" :hint="__('forms.application.hints.social')" placeholder="https://facebook.com/" />
                    </x-card>

                    <x-card :title="__('forms.application.sections.space_title')" :description="__('forms.application.sections.space_description')">
                        <x-field type="number" name="capacity" :label="__('forms.application.fields.capacity')" />
                        <x-field type="textarea" name="description" rows="4" :label="__('forms.application.fields.description')" :hint="__('forms.application.hints.description')" />
                    </x-card>

                    <x-card :title="__('forms.application.sections.image_title')" :description="__('forms.application.sections.image_description')">
                        <div class="flex flex-col gap-1.5">
                            <label for="campo-image" class="text-sm font-semibold text-ink">{{ __('forms.application.fields.image') }}</label>
                            <p id="campo-image-aiuto" class="text-xs text-ink-subtle">{{ __('forms.application.sections.image_hint') }}</p>
                            <input
                                id="campo-image"
                                type="file"
                                name="image"
                                accept="image/jpeg,image/png,image/webp"
                                aria-describedby="campo-image-aiuto @error('image') campo-image-errore @enderror"
                                @error('image') aria-invalid="true" @enderror
                                class="{{ $control }} file:mr-3 file:border-0 file:bg-surface-raised file:px-3 file:py-1.5 file:font-semibold file:text-ink"
                            >
                            @error('image')
                                <p id="campo-image-errore" class="text-xs font-semibold text-live">{{ $message }}</p>
                            @enderror
                        </div>
                    </x-card>
                @endif

                <x-card :title="__('forms.application.sections.contact_title')" :description="__('forms.application.sections.contact_description')">
                    <x-field name="contact_name" :label="__('forms.application.fields.contact_name')" :value="$user?->name" :required="true" autocomplete="name" />
                    <x-field name="contact_role" :label="__('forms.application.fields.contact_role')" :hint="__('forms.application.hints.contact_role')" />
                    <x-field type="tel" name="contact_phone" :label="__('forms.application.fields.contact_phone')" autocomplete="tel" />
                    @if ($guest)
                        <x-field type="email" name="contact_email" :label="__('forms.application.fields.contact_email')" :required="true" autocomplete="email" />
                    @else
                        <div class="flex flex-col gap-1.5" data-account-email>
                            <p class="text-sm font-semibold text-ink">{{ __('forms.application.fields.contact_email') }}</p>
                            <p class="flex min-h-12 items-center gap-2 border border-line bg-surface-sunken px-3.5 py-2.5 text-sm text-ink-muted [overflow-wrap:anywhere]">
                                <x-lucide name="mail" class="size-4 shrink-0" />
                                {{ $user->email }}
                            </p>
                            <p class="text-xs text-ink-subtle">{{ __('forms.application.sections.signed_in_as', ['email' => $user->email]) }}</p>
                        </div>
                    @endif
                    @if ($mode === 'venue')
                        <x-field type="textarea" name="message" rows="4" :label="__('forms.application.fields.message')" :hint="__('forms.application.hints.message')" />
                    @endif
                </x-card>

                @if ($mode === 'claim')
                    <x-card :title="__('forms.claim.verify_title')" :description="__('forms.claim.verify_description')">
                        <x-field type="textarea" name="message" rows="5" :label="__('forms.claim.fields.message')" :required="true" />
                    </x-card>
                @endif

                @if ($needsAccount)
                    <x-card :title="__('forms.application.sections.account_title')" :description="__('forms.application.sections.account_description')" data-account-card>
                        <x-field type="password" name="password" :label="__('forms.application.fields.password')" :hint="__('forms.application.hints.password')" :required="true" autocomplete="new-password" />
                        <x-field type="password" name="password_confirmation" :label="__('forms.application.fields.password_confirmation')" :required="true" autocomplete="new-password" />
                        <x-slot:footer>
                            <span class="text-sm text-ink-muted">{{ __('forms.application.sections.login_title') }}</span>
                            <x-button type="submit" variant="ghost" formaction="{{ $draftAction }}" formnovalidate>{{ __('forms.application.sections.login_action') }}</x-button>
                        </x-slot:footer>
                    </x-card>
                @endif

                <x-card tone="muted" :title="__('forms.application.sections.next_title')">
                    <ol class="flex flex-col gap-3">
                        @foreach ($steps as $step)
                            <li class="flex gap-3 text-sm text-ink-muted">
                                <span class="flex size-7 shrink-0 items-center justify-center border border-line font-display text-sm font-extrabold text-ink">{{ $loop->iteration }}</span>
                                <span class="pt-1">{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                </x-card>

                <div class="flex flex-col gap-4">
                    <x-turnstile :action="$turnstile" />
                    <p class="text-xs text-ink-subtle">{{ $mode === 'claim' ? __('forms.claim.privacy_note') : __('forms.application.privacy_note') }}</p>
                    <x-form-actions>
                        <x-button type="submit" :block="true">{{ $submit }}</x-button>
                    </x-form-actions>
                </div>
            </form>
        @endif
    </x-service-page>
</x-layouts.app>
