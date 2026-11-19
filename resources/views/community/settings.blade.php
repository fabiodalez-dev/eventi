{{--
    Il profilo pubblico della community: come mi presento agli altri.

    La foto sta in cima, da sola nella sua card: è la cosa che si riconosce
    prima, e prima stava in fondo dentro un «facoltativo» chiuso. Poi chi sono
    (nome pubblico e nome utente), chi può vedermi, i dettagli facoltativi.

    Ogni errore compare una volta sola, accanto al campo che lo riguarda; in
    cima c'è soltanto l'avviso che qualcosa va corretto. Prima lo stesso
    messaggio usciva due volte (dalle schede e da un riquadro della pagina).
--}}
@php
    $inline = ['display_name', 'handle', 'avatar', 'remove_avatar', 'bio', 'city_id', 'visibility', 'indexable', 'venue_ids'];
    $otherErrors = collect($errors->getMessages())
        ->reject(fn ($messages, $key) => in_array(\Illuminate\Support\Str::before((string) $key, '.'), $inline, true))
        ->flatten()->unique()->values();
    $control = 'min-h-12 w-full border bg-surface px-3.5 py-2.5 text-base text-ink placeholder:text-ink-subtle focus:border-brand focus:outline-none';
    $displayName = old('display_name', $profile['display_name'] ?? ($suggested['display_name'] ?? ''));
    $avatarUrl = $profile['avatar_url'] ?? null;
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$meta->heading"
        :subtitle="__('community.settings_lead')"
        :back="route('account.profile')"
        :tabs="\App\Services\Community\CommunityNavigation::tabs('settings')"
        :tabs-label="__('community.nav')"
        :show-errors="false"
    >
        @unless ($verified)
            <x-card :title="__('community.settings_locked_title')">
                <div class="[&>div]:my-0"><x-community-access-step /></div>
            </x-card>
        @else
            @if ($errors->any())
                <x-notice tone="alert" :title="trans_choice('ui.service.form_errors_title', count($errors->all()))">
                    <p class="m-0">{{ __('community.form_errors_hint') }}</p>
                    @if ($otherErrors->isNotEmpty())
                        <ul class="m-0 flex list-disc flex-col gap-1 pl-5">
                            @foreach ($otherErrors as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                </x-notice>
            @endif

            <form data-community-form method="post" enctype="multipart/form-data" action="{{ route('community.settings.update') }}" class="flex flex-col gap-6">
                @csrf

                {{-- La foto. Il campo file è dentro l'etichetta vestita da
                     pulsante: «Scegli una foto» è un pulsante vero, che si
                     raggiunge da tastiera, invece del controllo grezzo del
                     browser. Lo script riduce la foto nel browser prima
                     dell'invio (JPEG, 1024 px): una foto da 8 MB del telefono
                     parte come poche centinaia di KB e non incontra il limite
                     del server. --}}
                <x-card
                    id="foto"
                    :title="__('community.avatar')"
                    :description="__('community.avatar_lead')"
                    data-avatar-picker
                    data-max-bytes="{{ 2 * 1024 * 1024 }}"
                    data-pronta="{{ __('community.avatar_ready') }}"
                    data-riduco="{{ __('community.avatar_processing') }}"
                    data-pesante="{{ __('community.avatar_errors.too_heavy', ['max' => 2]) }}"
                    data-illeggibile="{{ __('community.avatar_errors.browser_unreadable') }}"
                >
                    <div class="flex min-w-0 flex-wrap items-center gap-4">
                        <x-avatar :src="$avatarUrl" :name="$displayName" size="lg" data-avatar-preview />
                        <div class="flex min-w-0 flex-col gap-2">
                            <label @class([
                                'ui-action inline-flex min-h-12 cursor-pointer items-center gap-2 self-start border-2 bg-surface px-4 py-2 font-display text-sm leading-tight font-extrabold text-ink transition hover:border-accent hover:bg-canvas',
                                'has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-accent',
                                'border-alert' => $errors->has('avatar'),
                                'border-line' => ! $errors->has('avatar'),
                            ])>
                                <x-lucide name="plus" class="size-4" />
                                <span>{{ __($avatarUrl ? 'community.avatar_change' : 'community.avatar_choose') }}</span>
                                <input
                                    type="file" name="avatar" accept="image/jpeg,image/png,image/webp"
                                    class="sr-only" data-avatar-input
                                    aria-describedby="avatar-aiuto avatar-stato{{ $errors->has('avatar') ? ' avatar-errore' : '' }}"
                                    @if ($errors->has('avatar')) aria-invalid="true" @endif
                                >
                            </label>
                            @if ($avatarUrl)
                                <label class="inline-flex min-h-11 cursor-pointer items-center gap-3 text-sm text-ink">
                                    <input type="checkbox" name="remove_avatar" value="1" class="size-4 accent-[var(--brand)]" data-avatar-remove @checked(old('remove_avatar'))>
                                    {{ __('community.remove_avatar') }}
                                </label>
                            @endif
                        </div>
                    </div>
                    <p id="avatar-aiuto" class="m-0 text-sm text-ink-muted">{{ __('community.avatar_help') }}</p>
                    <p id="avatar-stato" data-avatar-stato role="status" class="m-0 text-sm text-ink-muted empty:hidden"></p>
                    @error('avatar')
                        <p id="avatar-errore" class="m-0 text-sm font-semibold text-alert">{{ $message }}</p>
                    @enderror
                </x-card>

                <x-card :title="__('community.identity_title')" :description="__('community.account_identity_help')">
                    <div class="flex flex-col gap-1.5">
                        <label for="display_name" class="text-sm font-semibold text-ink">{{ __('community.display_name') }}</label>
                        <input
                            id="display_name" name="display_name" value="{{ $displayName }}" maxlength="80" required autocomplete="nickname"
                            @if ($errors->has('display_name')) aria-invalid="true" aria-describedby="display_name-errore" @endif
                            @class([$control, 'border-alert' => $errors->has('display_name'), 'border-line' => ! $errors->has('display_name')])
                        >
                        @error('display_name')<p id="display_name-errore" class="m-0 text-sm font-semibold text-alert">{{ $message }}</p>@enderror
                    </div>

                    {{-- Il nome utente si controlla mentre lo si scrive, e si
                         scrive in minuscolo: la maiuscola che il telefono mette
                         da solo diventa minuscola sotto le dita, e il server fa
                         lo stesso se lo script non c'è. --}}
                    <div
                        class="flex flex-col gap-1.5"
                        data-nome-utente
                        data-url="{{ route('community.handle') }}"
                        data-libero="{{ __('community.handle_free') }}"
                        data-occupato="{{ __('community.handle_taken') }}"
                        data-attesa="{{ __('community.handle_checking') }}"
                        data-corto="{{ __('community.handle_short') }}"
                    >
                        <label for="handle" class="text-sm font-semibold text-ink">{{ __('community.handle') }}</label>
                        <p id="handle-help" class="m-0 text-xs text-ink-subtle">{{ __('community.handle_help') }}</p>
                        <div class="relative">
                            <span aria-hidden="true" class="pointer-events-none absolute inset-y-0 left-3.5 flex items-center text-base text-ink-subtle">@</span>
                            <input
                                id="handle" name="handle" value="{{ old('handle', $profile['handle'] ?? '') }}" maxlength="40" required
                                autocomplete="off" spellcheck="false" autocapitalize="none" autocorrect="off"
                                aria-describedby="handle-help handle-stato{{ $errors->has('handle') ? ' handle-errore' : '' }}"
                                @if ($errors->has('handle')) aria-invalid="true" @endif
                                @class([$control, 'pr-12 pl-8', 'border-alert' => $errors->has('handle'), 'border-line' => ! $errors->has('handle')])
                            >
                            {{-- Il segno non è mai l'unica informazione: sotto c'è
                                 sempre la frase, che è ciò che legge uno screen reader. --}}
                            <span data-nome-utente-segno aria-hidden="true" hidden class="absolute inset-y-0 right-3 flex items-center text-lg leading-none font-bold"></span>
                        </div>
                        <p id="handle-stato" data-nome-utente-stato role="status" class="m-0 text-sm empty:hidden"></p>
                        @error('handle')<p id="handle-errore" class="m-0 text-sm font-semibold text-alert">{{ $message }}</p>@enderror
                        <p class="m-0 text-xs text-ink-subtle">{{ __('community.handle_change_help', ['days' => (int) config('community.handle_change_cooldown_days')]) }}</p>
                        {{-- L'attesa fra due cambi si dice prima, non solo quando il salvataggio la rifiuta. --}}
                        @if ($attesaNome = auth()->user()?->communityProfile?->handleCooldownMessage())
                            <p data-nome-utente-attesa class="m-0 text-sm text-ink-muted">{{ $attesaNome }}</p>
                        @endif
                    </div>
                </x-card>

                <x-card :title="__('community.visibility')" :description="__('community.visibility_help')">
                    <fieldset class="m-0 flex min-w-0 flex-col gap-1 border-0 p-0">
                        <legend class="sr-only">{{ __('community.visibility') }}</legend>
                        @foreach (\App\Enums\ProfileVisibility::cases() as $visibility)
                            <label class="flex min-h-12 cursor-pointer items-center gap-3 text-ink">
                                <input type="radio" name="visibility" value="{{ $visibility->value }}" class="size-4 shrink-0 accent-[var(--brand)]" @checked(old('visibility', $profile['visibility'] ?? 'members') === $visibility->value)>
                                <span>{{ __('community.profile_visibility.'.$visibility->value) }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    @error('visibility')<p class="m-0 text-sm font-semibold text-alert">{{ $message }}</p>@enderror
                    <div class="flex flex-col gap-1 border-t border-line pt-4">
                        <input type="hidden" name="indexable" value="0">
                        <label class="flex cursor-pointer items-start gap-3 text-ink">
                            <input type="checkbox" name="indexable" value="1" class="mt-1 size-4 shrink-0 accent-[var(--brand)]" @checked(old('indexable', $profile['indexable'] ?? false))>
                            <span>{{ __('community.indexable') }}</span>
                        </label>
                        <p class="m-0 pl-7 text-sm text-ink-muted">{{ __('community.index_help') }}</p>
                    </div>
                </x-card>

                <x-card :title="__('community.optional_profile')" :description="__('community.optional_profile_help')">
                    <div class="flex flex-col gap-1.5">
                        <label for="bio" class="text-sm font-semibold text-ink">{{ __('community.bio') }}</label>
                        <textarea
                            id="bio" name="bio" maxlength="500" rows="4"
                            @if ($errors->has('bio')) aria-invalid="true" aria-describedby="bio-errore" @endif
                            @class([$control, 'border-alert' => $errors->has('bio'), 'border-line' => ! $errors->has('bio')])
                        >{{ old('bio', $profile['bio'] ?? '') }}</textarea>
                        @error('bio')<p id="bio-errore" class="m-0 text-sm font-semibold text-alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="city_id" class="text-sm font-semibold text-ink">{{ __('community.city') }}</label>
                        <select
                            id="city_id" name="city_id"
                            @if ($errors->has('city_id')) aria-invalid="true" aria-describedby="city_id-errore" @endif
                            @class([$control, 'border-alert' => $errors->has('city_id'), 'border-line' => ! $errors->has('city_id')])
                        >
                            <option value="">{{ __('community.no_city') }}</option>
                            @foreach ($cities as $city)
                                <option value="{{ $city->id }}" @selected(old('city_id', $profile['city_id'] ?? null) == $city->id)>{{ $city->name }}</option>
                            @endforeach
                        </select>
                        @error('city_id')<p id="city_id-errore" class="m-0 text-sm font-semibold text-alert">{{ $message }}</p>@enderror
                    </div>

                    {{-- I locali consigliati: ricerca e chip, come il campo del
                         locale nel wizard dei calendari. Le caselle restano la
                         fonte di verità e non vengono sostituite da campi
                         nascosti: le chip le spuntano e le despuntano. Senza
                         JavaScript si vede l'elenco, che funziona; con
                         JavaScript l'elenco si nasconde e resta attivo. --}}
                    <fieldset
                        class="flex min-w-0 flex-col gap-3 border-t border-line pt-4"
                        data-locali
                        data-limite="20"
                        data-nessuno="{{ __('community.venue_none_found') }}"
                        data-tutti="{{ __('community.venue_all_chosen') }}"
                        data-limite-testo="{{ __('community.venue_limit', ['count' => 20]) }}"
                        data-togli="{{ __('community.venue_remove', ['name' => ':name']) }}"
                    >
                        <legend class="float-left mb-1 w-full text-sm font-semibold text-ink">{{ __('community.venues') }}</legend>
                        <p class="m-0 text-sm text-ink-muted">{{ __('community.venues_help') }}</p>
                        <input type="hidden" name="venue_ids[]" value="">
                        <div data-locali-caselle class="flex flex-col gap-1">
                            @forelse ($venues as $venue)
                                <label class="flex min-h-11 cursor-pointer items-center gap-3 text-ink"><input type="checkbox" name="venue_ids[]" value="{{ $venue['id'] }}" class="size-4 shrink-0 accent-[var(--brand)]" @checked(in_array($venue['id'], old('venue_ids', $venue_ids)))>{{ $venue['name'] }}</label>
                            @empty
                                <p class="m-0 text-sm text-ink-muted">{{ __('community.no_venues') }}</p>
                            @endforelse
                        </div>
                        @if ($venues !== [])
                            <div data-locali-arricchito hidden>
                                <label for="locali-cerca" class="block text-sm font-semibold text-ink">{{ __('community.venue_search') }}</label>
                                <div class="relative mt-2">
                                    <input
                                        id="locali-cerca" data-locali-input type="text" role="combobox" aria-autocomplete="list"
                                        aria-expanded="false" aria-controls="locali-opzioni" aria-describedby="locali-aiuto"
                                        autocomplete="off" placeholder="{{ __('community.venue_search') }}"
                                        class="{{ $control }} border-line"
                                    >
                                    <ul id="locali-opzioni" data-locali-opzioni role="listbox" aria-label="{{ __('community.venues') }}" hidden class="absolute inset-x-0 top-full z-30 max-h-64 overflow-y-auto border-2 border-line bg-canvas"></ul>
                                </div>
                                <p id="locali-aiuto" class="mt-2 text-sm text-ink-muted">{{ __('community.venue_search_help') }}</p>
                                <p data-locali-stato role="status" class="mt-2 text-sm text-ink-muted empty:hidden"></p>
                                <ul data-locali-scelti aria-label="{{ __('community.venue_chosen') }}" class="mt-3 flex flex-wrap gap-2 empty:hidden"></ul>
                            </div>
                        @endif
                        @error('venue_ids')<p class="m-0 text-sm font-semibold text-alert">{{ $message }}</p>@enderror
                    </fieldset>
                </x-card>

                <x-form-actions>
                    <x-button type="submit">{{ __('community.save') }}</x-button>
                    @if ($profile)
                        <x-button variant="secondary" :href="route('community.profile', $profile['handle'])">{{ __('community.visit_profile') }}</x-button>
                    @endif
                </x-form-actions>
            </form>

            <x-card padding="none">
                <x-card.row :href="route('community.whatsapp')" icon="phone" :title="__('community.whatsapp.title')" :description="__('community.whatsapp.settings_hint')" />
            </x-card>
        @endunless
    </x-service-page>
</x-layouts.app>
