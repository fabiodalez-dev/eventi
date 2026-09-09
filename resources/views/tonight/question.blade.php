@php
    $index = array_search($question, $questions, true);
    $field = $question === 'district' ? 'zone' : $question;
    $next = $question === 'municipality' ? 'district' : $questions[$index + 1];
@endphp
<p class="my-8 border-y-2 border-line py-4 text-sm text-accent">{{ __('tonight.progress', ['current' => $index + 1, 'total' => count($questions) - 1]) }}</p>
<form method="GET" action="{{ route('tonight.wizard') }}" class="space-y-8" data-tonight-counts data-count-loading="{{ __('tonight.count_loading') }}" data-count-error="{{ __('tonight.count_error') }}" data-count-template="{{ __('tonight.count_template') }}">
    <input type="hidden" name="question" value="{{ $next }}">
    @foreach(['municipality', 'zone', 'when', 'budget'] as $key)
        @if($key !== $field)<input type="hidden" name="{{ $key }}" value="{{ $input[$key] ?? ($key === 'when' ? 'tonight' : '') }}">@endif
    @endforeach
    @if($question !== 'categories')
        @foreach($input['categories'] ?? [] as $id)<input type="hidden" name="categories[]" value="{{ $id }}">@endforeach
    @endif
    <fieldset>
        <legend class="mb-6 font-display text-2xl font-bold sm:text-3xl">{{ __('tonight.'.$question) }}</legend>
        @if(in_array($question, ['municipality', 'district'], true))
            @php
                $options = $question === 'municipality' ? $municipalities : $zones->all();
                $allLabel = $question === 'municipality' ? __('tonight.everywhere') : __('tonight.any_zone');
                $choices = array_combine($options, $options);
                $choices = $question === 'municipality' ? [($options[0] ?? $city->name) => ($options[0] ?? $city->name), $allLabel => '', ...$choices] : [$allLabel => '', ...$choices];
            @endphp
            <div data-place-choices>
                <label class="block text-sm text-ink-muted">{{ __('tonight.search_place') }}
                    <input type="search" data-place-search class="mt-2 mb-5 min-h-14 w-full border-2 border-line bg-canvas px-4" autocomplete="off">
                </label>
                <div class="grid max-h-80 gap-2 overflow-y-auto pr-2 sm:grid-cols-2">
                    @foreach($choices as $label => $value)
                        @php($placeCount = $question === 'municipality' ? ($value === '' ? $counts['everywhere'] : ($counts['municipalities'][$value] ?? 0)) : ($value === '' ? ($counts['municipalities']['Padova'] ?? 0) : ($counts['zones'][$value] ?? 0)))
                        <label data-place-option class="flex min-h-14 cursor-pointer items-center gap-3 border-2 border-line p-4 has-[:checked]:border-accent has-[:checked]:text-accent has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-45">
                            <input type="radio" name="{{ $field }}" value="{{ $value }}" @checked(($input[$field] ?? '') === $value) @disabled($placeCount === 0 && ($input[$field] ?? '') !== $value)> {{ $label }}
                            <span class="ml-auto tabular-nums" data-place-count="{{ $value }}" data-place-kind="{{ $question }}">{{ $question === 'municipality' ? ($value === '' ? $counts['everywhere'] : ($counts['municipalities'][$value] ?? 0)) : ($value === '' ? ($counts['municipalities']['Padova'] ?? 0) : ($counts['zones'][$value] ?? 0)) }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
            @if($question === 'district')<p class="mt-4 text-sm text-ink-muted">{{ __('tonight.district_unknown') }}</p>@endif
        @elseif($question === 'when' || $question === 'budget')
            @php
                $options = $question === 'when'
                    ? ['tonight' => __('tonight.tonight'), 'starting_soon' => __('tonight.soon')]
                    : ['' => __('tonight.any_budget'), '0' => __('tonight.free'), '10' => __('tonight.up_to', ['amount' => 10]), '20' => __('tonight.up_to', ['amount' => 20]), '30' => __('tonight.up_to', ['amount' => 30]), '50' => __('tonight.up_to', ['amount' => 50])];
            @endphp
            <div class="grid gap-3 sm:grid-cols-2">
                @foreach($options as $value => $label)
                    <label class="flex min-h-14 cursor-pointer items-center gap-3 border-2 border-line p-4 has-[:checked]:border-accent has-[:checked]:text-accent">
                        <input type="radio" name="{{ $field }}" value="{{ $value }}" @checked((string)($input[$field] ?? ($field === 'when' ? 'tonight' : '')) === (string)$value)>
                        <span><span class="block font-bold">{{ $label }}</span>
                        @if($question === 'when')<span class="mt-2 block text-sm text-ink-muted">{{ $value === 'tonight' ? __('tonight.tonight_help') : __('tonight.soon_help', ['minutes' => $city->starting_soon_minutes]) }}</span>@endif</span>
                    </label>
                @endforeach
            </div>
            @if($question === 'budget')<p class="mt-4 max-w-prose text-sm text-ink-muted">{{ __('tonight.budget_help') }}</p>@endif
        @else
            <p class="mb-6 text-ink-muted">{{ __('tonight.categories_help') }}</p>
            <div class="grid grid-cols-2 gap-3">
                @foreach($categories as $category)
                    <label class="flex min-h-16 cursor-pointer items-center gap-2 border-2 border-line p-3 text-sm has-[:checked]:border-accent has-[:checked]:text-accent">
                        <input type="checkbox" name="categories[]" value="{{ $category->id }}" @checked(in_array($category->id, array_map('intval', $input['categories'] ?? []), true))>{{ $category->name }}
                    </label>
                @endforeach
            </div>
        @endif
    </fieldset>
    <div class="flex flex-wrap items-center gap-6 border-t-2 border-line pt-6">
        <button class="min-h-14 bg-brand px-8 py-4 font-bold text-on-brand" type="submit">{{ $question === 'categories' ? __('tonight.find') : __('tonight.next') }} <span data-count-total aria-live="polite">({{ __('tonight.count_template', ['count' => $counts['total']]) }})</span> →</button>
        @if($index > 0)<a class="inline-flex min-h-12 items-center underline" href="{{ route('tonight.wizard', [...\Illuminate\Support\Arr::except($input, ['step']), 'question' => $questions[$index - 1]]) }}">{{ __('tonight.back') }}</a>@endif
    </div>
</form>
