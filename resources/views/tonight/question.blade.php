{{--
    Il modulo di una domanda della procedura «Stasera».

    Le risposte sono opzioni grandi da toccare: card per «quando» e per il
    budget, righe di un elenco con ricerca per comuni e quartieri, etichette
    per le categorie. Accanto a ogni opzione il numero di eventi che si
    troverebbero scegliendola, aggiornato da `tonight-counts.js`.

    Sotto le risposte il riepilogo delle scelte e i primi eventi che le
    rispettano, con la locandina: è il frammento `tonight-live`, che lo script
    ridisegna a ogni cambio chiedendolo al controller (`preview=1`).

    In fondo i comandi: «Indietro», «Salta» e il pulsante principale. Sul
    telefono stanno in una barra fissa sopra la barra di navigazione (la sua
    altezza visibile è `--visible-navigation-height`), mai sotto di lei né
    sopra la fascia del consenso: lo dice `pages/tonight.css`.
--}}
@php
    $index = array_search($question, $questions, true);
    $total = count($questions) - 1;
    $field = $question === 'district' ? 'zone' : $question;
    // Dal comune si va sempre al quartiere: se il comune scelto non è Padova il controller passa da solo al budget.
    $next = $question === 'municipality' ? 'district' : $questions[$index + 1];
    $last = $question === 'categories';
    $keep = \Illuminate\Support\Arr::except($input, ['step', 'preview', 'question']);
    $to = fn (string $target, array $override = []): string => route('tonight.wizard', [...$keep, ...$override, 'question' => $target]);
    $skipValue = ['when' => 'tonight', 'municipality' => '', 'zone' => '', 'budget' => '', 'categories' => []][$field];
    $skipUrl = $to($next, [$field => $skipValue]);
    $backUrl = $index > 0 ? $to($questions[$index - 1]) : route('home');
    $selectedCategories = array_map('intval', $input['categories'] ?? []);
@endphp
<form method="GET" action="{{ route('tonight.wizard') }}" class="tonight-form flex min-w-0 flex-col gap-6"
    data-tonight-counts
    data-question="{{ $question }}"
    data-count-loading="{{ __('tonight.count_loading') }}"
    data-count-error="{{ __('tonight.count_error') }}"
    data-status-zero="{{ trans_choice('tonight.events_count', 0) }}"
    data-status-one="{{ trans_choice('tonight.events_count', 1) }}"
    data-status-many="{{ trans_choice('tonight.events_count', 2, ['count' => ':count']) }}">
    <input type="hidden" name="question" value="{{ $next }}">
    @foreach (['municipality', 'zone', 'when', 'budget'] as $key)
        @if ($key !== $field)<input type="hidden" name="{{ $key }}" value="{{ $input[$key] ?? ($key === 'when' ? 'tonight' : '') }}">@endif
    @endforeach
    @if ($question !== 'categories')
        @foreach ($input['categories'] ?? [] as $id)<input type="hidden" name="categories[]" value="{{ $id }}">@endforeach
    @endif

    <fieldset class="m-0 min-w-0 border-0 p-0">
        <legend class="sr-only">{{ __('tonight.'.$question) }}</legend>
        @if (in_array($question, ['municipality', 'district'], true))
            @php
                $options = $question === 'municipality' ? $municipalities : $zones->all();
                $allLabel = $question === 'municipality' ? __('tonight.everywhere') : __('tonight.any_zone');
                $choices = [$allLabel => '', ...array_combine($options, $options)];
            @endphp
            <div class="flex flex-col gap-3" data-place-choices>
                <label class="flex flex-col gap-2 text-sm font-semibold text-ink">
                    {{ __('tonight.search_place') }}
                    <span class="tonight-search">
                        <x-lucide name="search" class="size-5 shrink-0 text-ink-muted" />
                        <input type="search" data-place-search autocomplete="off" class="min-h-12 w-full min-w-0 border-0 bg-transparent p-0 text-base text-ink outline-none">
                    </span>
                </label>
                <div class="tonight-places" data-tonight-places>
                    @foreach ($choices as $label => $value)
                        @php
                            $placeCount = $question === 'municipality'
                                ? ($value === '' ? $counts['everywhere'] : ($counts['municipalities'][$value] ?? 0))
                                : ($value === '' ? ($counts['municipalities']['Padova'] ?? 0) : ($counts['zones'][$value] ?? 0));
                        @endphp
                        <label data-place-option class="tonight-place">
                            <input type="radio" class="tonight-option__input" name="{{ $field }}" value="{{ $value }}" @checked(($input[$field] ?? '') === $value) @disabled($placeCount === 0 && ($input[$field] ?? '') !== $value)>
                            <span class="min-w-0 flex-1 font-semibold">{{ $label }}</span>
                            <span class="tonight-count"><span data-place-count="{{ $value }}" data-place-kind="{{ $question }}">{{ $placeCount }}</span><span class="sr-only"> {{ __('tonight.events_unit') }}</span></span>
                        </label>
                    @endforeach
                </div>
                @if (count($choices) > 8)
                    <div>
                        <x-button variant="secondary" size="sm" data-places-toggle hidden aria-expanded="false"
                            data-more="{{ __('tonight.places_more', ['count' => count($choices) - 1]) }}" data-less="{{ __('tonight.places_less') }}">{{ __('tonight.places_more', ['count' => count($choices) - 1]) }}</x-button>
                    </div>
                @endif
            </div>
            @if ($question === 'district')
                <p class="mt-3 text-sm text-ink-muted">{{ __('tonight.district_unknown') }}</p>
            @endif
        @elseif ($question === 'when' || $question === 'budget')
            @php
                $options = $question === 'when'
                    ? ['tonight' => __('tonight.tonight'), 'starting_soon' => __('tonight.soon')]
                    : ['' => __('tonight.any_budget'), '0' => __('tonight.free'), '10' => __('tonight.up_to', ['amount' => 10]), '20' => __('tonight.up_to', ['amount' => 20]), '30' => __('tonight.up_to', ['amount' => 30]), '50' => __('tonight.up_to', ['amount' => 50])];
                $current = (string) ($input[$field] ?? ($field === 'when' ? 'tonight' : ''));
            @endphp
            <div class="flex flex-col gap-3">
                @foreach ($options as $value => $label)
                    <label class="tonight-option">
                        <input type="radio" class="tonight-option__input" name="{{ $field }}" value="{{ $value }}" @disabled($question === 'budget' && ($counts['budgets'][$value] ?? 0) === 0 && $current !== (string) $value) @checked($current === (string) $value)>
                        <span class="flex min-w-0 flex-1 flex-col gap-1">
                            <span class="font-display text-lg leading-tight font-extrabold">{{ $label }}</span>
                            @if ($question === 'when')
                                <span class="text-sm text-ink-muted">{{ $value === 'tonight' ? __('tonight.tonight_help') : __('tonight.soon_help', ['minutes' => $city->starting_soon_minutes]) }}</span>
                            @endif
                        </span>
                        @if ($question === 'budget')
                            <span class="tonight-count"><span data-option-count="{{ $value }}" data-count-kind="budgets">{{ $counts['budgets'][$value] ?? 0 }}</span></span>
                        @endif
                    </label>
                @endforeach
            </div>
        @else
            <div class="tonight-chips">
                {{-- Prima le categorie che hanno eventi con le scelte fatte finora. --}}
                @foreach ($categories->sortBy(fn ($category) => ($counts['categories'][$category->id] ?? 0) > 0 ? 0 : 1) as $category)
                    @php
                        $categoryCount = $counts['categories'][$category->id] ?? 0;
                    @endphp
                    <label class="tonight-chip">
                        <input type="checkbox" class="tonight-option__input" name="categories[]" value="{{ $category->id }}" @disabled($categoryCount === 0) @checked($categoryCount > 0 && in_array($category->id, $selectedCategories, true))>
                        <span class="tonight-chip__mark" aria-hidden="true"><x-lucide name="check" class="size-4" /></span>
                        <span class="min-w-0">{{ $category->name }}</span>
                        <span class="tonight-count"><span data-option-count="{{ $category->id }}" data-count-kind="categories">{{ $categoryCount }}</span></span>
                    </label>
                @endforeach
            </div>
        @endif
    </fieldset>

    {{-- I comandi subito dopo la domanda: da desktop restano nel flusso e così
         si vedono senza scorrere oltre l'anteprima; da telefono la barra è fissa. --}}
    <div data-wizard-actions class="tonight-actions" role="group" aria-label="{{ __('tonight.actions_label') }}">
        <x-button variant="secondary" :href="$backUrl" class="tonight-actions__back" :aria-label="$index > 0 ? __('tonight.back') : __('tonight.back_home')">
            <x-lucide name="arrow-left" class="size-4" />
            {{ __('tonight.back') }}
        </x-button>
        <x-button variant="ghost" :href="$skipUrl" class="tonight-actions__skip" :aria-label="__('tonight.skip_label')">{{ __('tonight.skip') }}</x-button>
        <x-button type="submit" class="tonight-actions__next">
            @if ($last)
                <span data-count-text data-zero="{{ trans_choice('tonight.see_events', 0) }}" data-one="{{ trans_choice('tonight.see_events', 1) }}" data-many="{{ trans_choice('tonight.see_events', 2, ['count' => ':count']) }}">{{ trans_choice('tonight.see_events', $counts['total'], ['count' => $counts['total']]) }}</span>
            @else
                {{ __('tonight.next') }}
            @endif
            <x-lucide name="arrow-right" class="size-4" />
        </x-button>
    </div>

    <p class="sr-only" data-count-status aria-live="polite"></p>
    <div class="flex min-w-0 flex-col gap-6" data-tonight-live>
        @fragment('tonight-live')
            @php
                $budget = (string) ($input['budget'] ?? '');
                $summary = [
                    'when' => ($input['when'] ?? 'tonight') === 'starting_soon' ? __('tonight.soon') : __('tonight.tonight'),
                    'municipality' => filled($input['municipality'] ?? null) ? $input['municipality'] : __('tonight.everywhere'),
                    'district' => filled($input['zone'] ?? null) ? $input['zone'] : __('tonight.any_zone'),
                    'budget' => match ($budget) { '' => __('tonight.any_budget'), '0' => __('tonight.free'), default => __('tonight.up_to', ['amount' => $budget]) },
                    'categories' => $categories->whereIn('id', array_map('intval', $input['categories'] ?? []))->pluck('name')->implode(', ') ?: __('tonight.all_categories'),
                ];
            @endphp
            <x-card :title="__('tonight.preview.title')" :description="trans_choice('tonight.events_count', $counts['total'], ['count' => $counts['total']])" padding="none" data-tonight-preview>
                @forelse ($preview as $date)
                    <div class="svc-row px-5 py-4 sm:px-6">
                        <x-service-event-row :occurrence="$date" size="sm" />
                    </div>
                @empty
                    <p class="m-0 px-5 py-4 text-sm text-ink-muted sm:px-6">{{ __('tonight.preview.empty') }}</p>
                @endforelse
                @if ($counts['total'] > 0)
                    <x-slot:footer>
                        <x-button type="submit" variant="secondary" name="question" value="results" :block="true">
                            {{ trans_choice('tonight.preview.all', $counts['total'], ['count' => $counts['total']]) }}
                            <x-lucide name="arrow-right" class="size-4" />
                        </x-button>
                    </x-slot:footer>
                @endif
            </x-card>

            <x-card :title="__('tonight.summary.title')" padding="none" data-tonight-summary>
                @foreach (array_slice($questions, 0, $total) as $position => $step)
                    @if ($position < $index)
                        <x-card.row :href="$to($step)" :title="__('tonight.summary.'.$step)" :description="$summary[$step]">
                            <x-slot:trailing><span class="text-sm font-semibold text-brand">{{ __('tonight.summary.change') }}</span></x-slot:trailing>
                        </x-card.row>
                    @elseif ($position === $index)
                        <x-card.row :title="__('tonight.summary.'.$step)" :description="$summary[$step]" :badge="__('tonight.summary.current')" aria-current="step" />
                    @else
                        <x-card.row :title="__('tonight.summary.'.$step)" :description="__('tonight.summary.pending')" class="opacity-70" />
                    @endif
                @endforeach
            </x-card>
        @endfragment
    </div>

    @auth
        <div>
            <x-button variant="ghost" :href="route('account.content-preferences')">{{ __('tonight.preferences') }}</x-button>
        </div>
    @endauth
</form>
