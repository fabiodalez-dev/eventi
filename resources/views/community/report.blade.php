{{--
    Segnalare un contenuto: un gesto raro, quindi chiuso finché non serve,
    ma con un'etichetta che si legge come un comando e non come una nota.
--}}
@auth
<details class="group">
    <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 text-sm font-semibold text-ink-muted hover:text-ink [&::-webkit-details-marker]:hidden">
        <x-lucide name="info" class="size-4" />{{ $reportLabel ?? __('community.report') }}
        <x-lucide name="chevron-down" class="size-4 transition group-open:rotate-180" />
    </summary>
    <form method="post" action="{{ route('community.report') }}" class="mt-3 flex flex-col gap-4">
        @csrf
        <input type="hidden" name="type" value="{{ $subject->getMorphClass() }}"><input type="hidden" name="id" value="{{ $subject->id }}">
        @php($reportId = 'segnala-'.$subject->getMorphClass().'-'.$subject->id)
        <div class="flex flex-col gap-1.5">
            <label for="{{ $reportId }}-motivo" class="text-sm font-semibold text-ink">{{ __('community.report_reason') }}</label>
            <select id="{{ $reportId }}-motivo" name="reason" class="min-h-12 w-full border border-line bg-surface px-3.5 py-2.5 text-base text-ink focus:border-brand focus:outline-none">
                @foreach (\App\Enums\ReportReason::options() as $reason => $reasonLabel)
                    <option value="{{ $reason }}">{{ $reasonLabel }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1.5">
            <label for="{{ $reportId }}-nota" class="text-sm font-semibold text-ink">{{ __('community.report_note') }}</label>
            <textarea id="{{ $reportId }}-nota" name="note" maxlength="1000" rows="3" class="min-h-12 w-full border border-line bg-surface px-3.5 py-2.5 text-base text-ink focus:border-brand focus:outline-none"></textarea>
        </div>
        <div><x-button type="submit" variant="secondary" size="sm">{{ __('community.report_send') }}</x-button></div>
    </form>
</details>
@endauth
