<x-layouts.app :meta="$meta">
    @include('community.nav')<div class="max-w-3xl"><h1 class="text-hero">{{ $meta->heading }}</h1>
        @unless($verified)<x-community-access-step />
        @else
        @if($errors->any())<div role="alert" class="my-4 border border-line p-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form data-community-form method="post" enctype="multipart/form-data" action="{{ route('community.settings.update') }}" class="mt-8 space-y-6">@csrf
            <label class="block font-semibold">{{ __('community.display_name') }}<input name="display_name" value="{{ old('display_name', $profile['display_name'] ?? ($suggested['display_name'] ?? '')) }}" maxlength="80" required class="mt-2 w-full border-2 border-line bg-canvas p-3"></label>

            {{-- Il nome utente si controlla mentre lo si scrive.
                 Prima l'unico modo di sapere che era preso era inviare il modulo
                 e leggere l'errore, cioè dopo aver compilato tutto il resto. --}}
            <div
                data-nome-utente
                data-url="{{ route('community.handle') }}"
                data-libero="{{ __('community.handle_free') }}"
                data-occupato="{{ __('community.handle_taken') }}"
                data-attesa="{{ __('community.handle_checking') }}"
                data-corto="{{ __('community.handle_short') }}"
            >
                <label for="handle" class="block font-semibold">{{ __('community.handle') }}</label>
                <div class="relative mt-2">
                    <input
                        id="handle" name="handle" value="{{ old('handle', $profile['handle'] ?? '') }}" maxlength="40" required
                        autocomplete="off" spellcheck="false" autocapitalize="none"
                        aria-describedby="handle-help handle-stato"
                        class="w-full border-2 border-line bg-canvas p-3 pr-12"
                    >
                    {{-- Il segno non è mai l'unica informazione: sotto c'è sempre
                         la frase, che è ciò che legge uno screen reader. --}}
                    <span data-nome-utente-segno aria-hidden="true" hidden class="absolute inset-y-0 right-3 flex items-center text-lg leading-none font-bold"></span>
                </div>
                <p id="handle-stato" data-nome-utente-stato role="status" class="mt-2 text-sm empty:hidden"></p>
            </div>
            <p id="handle-help" class="text-sm text-ink-muted">{{ __('community.handle_help') }} {{ __('community.handle_change_help') }}</p>
            <fieldset class="space-y-3 border-y border-line py-6"><legend class="font-semibold">{{ __('community.visibility') }}</legend>
                @foreach(\App\Enums\ProfileVisibility::cases() as $visibility)<label class="flex min-h-11 items-center gap-3"><input type="radio" name="visibility" value="{{ $visibility->value }}" @checked(old('visibility', $profile['visibility'] ?? 'members') === $visibility->value)>{{ __('community.profile_visibility.'.$visibility->value) }}</label>@endforeach
                <input type="hidden" name="indexable" value="0"><label class="flex items-start gap-3"><input type="checkbox" name="indexable" value="1" class="mt-1" @checked(old('indexable', $profile['indexable'] ?? false))><span>{{ __('community.indexable') }}</span></label><p class="text-sm text-ink-muted">{{ __('community.index_help') }}</p>
            </fieldset>
            <details class="border-y border-line py-4" @if($errors->hasAny(['bio','city_id','avatar','venue_ids'])) open @endif>
                <summary class="min-h-12 cursor-pointer font-semibold">{{ __('community.optional_profile') }}</summary>
                <div class="mt-4 space-y-6">
            <label class="block font-semibold">{{ __('community.bio') }}<textarea name="bio" maxlength="500" rows="3" class="mt-2 w-full border-2 border-line bg-canvas p-3">{{ old('bio', $profile['bio'] ?? '') }}</textarea></label>
            <label class="block font-semibold">{{ __('community.city') }}<select name="city_id" class="mt-2 w-full border-2 border-line bg-canvas p-3"><option value="">{{ __('community.no_city') }}</option>@foreach($cities as $city)<option value="{{ $city->id }}" @selected(old('city_id', $profile['city_id'] ?? null) == $city->id)>{{ $city->name }}</option>@endforeach</select></label>
            {{-- Il pulsante interno del campo file lo disegna il browser, e nudo
                 non somigliava a niente del resto della pagina: bordo da 1px,
                 nessun fondo, il testo di sistema. I modificatori `file:`
                 vestono quella parte come gli altri comandi secondari. --}}
            <label class="block font-semibold">{{ __('community.avatar') }}<input
                type="file" name="avatar" accept="image/jpeg,image/png,image/webp"
                class="mt-2 block w-full cursor-pointer border-2 border-line bg-canvas p-3 text-sm text-ink-muted file:mr-4 file:cursor-pointer file:border-2 file:border-line file:bg-surface file:px-4 file:py-2 file:font-display file:text-sm file:font-extrabold file:text-ink file:transition hover:file:border-accent"
            ></label>
            @if($profile['avatar_url'] ?? null)<img src="{{ $profile['avatar_url'] }}" alt="" width="80" height="80" class="size-20 rounded-full object-cover"><label class="flex gap-2"><input type="checkbox" name="remove_avatar" value="1">{{ __('community.remove_avatar') }}</label>@endif
            {{-- I locali consigliati: ricerca e chip, come il campo del locale nel
                 wizard dei calendari.

                 Le caselle restano la **fonte di verità** e non vengono
                 sostituite da campi nascosti: le chip le spuntano e le
                 despuntano. Senza JavaScript si vede l'elenco di prima, che
                 funziona; con JavaScript l'elenco si nasconde e resta attivo,
                 quindi non esiste un momento in cui i due possano dire cose
                 diverse. --}}
            <fieldset
                class="space-y-3"
                data-locali
                data-limite="20"
                data-nessuno="{{ __('community.venue_none_found') }}"
                data-tutti="{{ __('community.venue_all_chosen') }}"
                data-limite-testo="{{ __('community.venue_limit', ['count' => 20]) }}"
                data-togli="{{ __('community.venue_remove', ['name' => ':name']) }}"
            >
                <legend class="font-semibold">{{ __('community.venues') }}</legend>
                <p class="text-sm text-ink-muted">{{ __('community.venues_help') }}</p>
                <input type="hidden" name="venue_ids[]" value="">
                <div data-locali-caselle class="space-y-3">
                    @forelse($venues as $venue)<label class="flex min-h-11 items-center gap-3"><input type="checkbox" name="venue_ids[]" value="{{ $venue['id'] }}" @checked(in_array($venue['id'], old('venue_ids', $venue_ids)))>{{ $venue['name'] }}</label>@empty<p class="text-sm text-ink-muted">{{ __('community.no_venues') }}</p>@endforelse
                </div>
                @if($venues !== [])
                    <div data-locali-arricchito hidden>
                        <label for="locali-cerca" class="block text-sm font-semibold">{{ __('community.venue_search') }}</label>
                        <div class="relative mt-2">
                            <input
                                id="locali-cerca" data-locali-input type="text" role="combobox" aria-autocomplete="list"
                                aria-expanded="false" aria-controls="locali-opzioni" aria-describedby="locali-aiuto"
                                autocomplete="off" placeholder="{{ __('community.venue_search') }}"
                                class="w-full border-2 border-line bg-canvas p-3"
                            >
                            <ul id="locali-opzioni" data-locali-opzioni role="listbox" aria-label="{{ __('community.venues') }}" hidden class="absolute inset-x-0 top-full z-30 max-h-64 overflow-y-auto border-2 border-line bg-canvas"></ul>
                        </div>
                        <p id="locali-aiuto" class="mt-2 text-sm text-ink-muted">{{ __('community.venue_search_help') }}</p>
                        <p data-locali-stato role="status" class="mt-2 text-sm text-ink-muted empty:hidden"></p>
                        <ul data-locali-scelti aria-label="{{ __('community.venue_chosen') }}" class="mt-3 flex flex-wrap gap-2 empty:hidden"></ul>
                    </div>
                @endif
            </fieldset>
                </div>
            </details>
            <div class="flex flex-wrap gap-3"><x-button type="submit">{{ __('community.save') }}</x-button>@if($profile)<x-button variant="secondary" :href="route('community.profile', $profile['handle'])">{{ __('community.visit_profile') }}</x-button>@endif</div>
        </form>
        <a href="{{ route('community.whatsapp') }}" class="mt-8 inline-flex min-h-11 items-center text-sm underline">{{ __('community.whatsapp.title') }}</a>
        @endunless
    </div>
</x-layouts.app>
