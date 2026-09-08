<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
        <h1 class="text-hero text-ink">I miei interessi</h1>
        <p class="text-ink-muted">Scegli cosa trovare in home, ricerca, mappe e suggerimenti. Le scelte valgono anche nell’app e puoi cambiarle in qualsiasi momento.</p>
        @if($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif
        <form method="POST" action="{{ route('account.content-preferences.update') }}" class="flex flex-col gap-6">
            @csrf @method('PATCH')
            <fieldset class="flex flex-col gap-3">
                <legend class="mb-3 font-bold">Cosa vuoi vedere?</legend>
                <label class="flex min-h-12 items-center gap-3"><input type="radio" name="mode" value="all" @checked(old('mode', $selection['mode']) === 'all')>Tutto, tranne le categorie che nascondo</label>
                <label class="flex min-h-12 items-center gap-3"><input type="radio" name="mode" value="selected" @checked(old('mode', $selection['mode']) === 'selected')>Solo le categorie che mi interessano</label>
            </fieldset>
            <p class="text-sm text-ink-muted">Le categorie nuove saranno aggiunte qui automaticamente. Nella modalità “Solo” dovrai sceglierle per mostrarle. Nessuna selezione in questa modalità significa nessun evento nelle liste.</p>
            <fieldset class="divide-y divide-line">
                <legend class="mb-3 font-bold">Categorie</legend>
                @foreach($options as $category)
                    @php($choice = old('choices.'.$category->id, in_array($category->id, $selection['hidden_categories']) ? 'hidden' : (in_array($category->id, $selection['categories']) ? 'interested' : 'neutral')))
                    <label class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <span class="font-semibold">{{ $category->name }}</span>
                        <select name="choices[{{ $category->id }}]" class="min-h-12 max-w-full border-2 border-line bg-canvas px-3 text-ink">
                            <option value="neutral" @selected($choice === 'neutral')>Nessuna preferenza</option>
                            <option value="interested" @selected($choice === 'interested')>Mi interessa</option>
                            <option value="hidden" @selected($choice === 'hidden')>Nascondi</option>
                        </select>
                    </label>
                @endforeach
            </fieldset>
            <label class="flex items-start gap-3"><input type="checkbox" name="inferred_ads" value="1" class="mt-1" @checked(old('inferred_ads', $selection['inferred_ads']))><span>Usa anche gli eventi salvati di recente per suggerirmi AD pertinenti.<span class="mt-1 block text-sm text-ink-muted">Le scelte esplicite contano di più. Le categorie nascoste non compaiono neppure negli AD. Non usiamo i singoli click per dedurre interessi.</span></span></label>
            <p class="text-sm text-ink-muted">Notifiche, biglietti e salvataggi restano separati: non cancelliamo nulla. Puoi sempre aprire un evento tramite il suo link diretto.</p>
            <x-button type="submit">Salva i miei interessi</x-button>
            <x-button :href="route('home')" variant="secondary">Torna agli eventi</x-button>
        </form>
        <form method="POST" action="{{ route('account.content-preferences.update') }}">
            @csrf @method('PATCH')
            <input type="hidden" name="mode" value="all">
            <input type="hidden" name="inferred_ads" value="{{ $selection['inferred_ads'] ? 1 : 0 }}">
            <x-button type="submit" variant="secondary">Ripristina tutte le categorie</x-button>
        </form>
    </div>
</x-layouts.app>
