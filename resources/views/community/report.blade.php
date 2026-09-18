@auth
<details class="my-4 text-sm"><summary class="cursor-pointer text-ink-muted">{{ __('community.report') }}</summary>
    <form method="post" action="{{ route('community.report') }}" class="mt-4 space-y-3">@csrf
        <input type="hidden" name="type" value="{{ $subject->getMorphClass() }}"><input type="hidden" name="id" value="{{ $subject->id }}">
        <label class="block">{{ __('community.report_reason') }}<select name="reason" class="mt-2 block w-full border-2 border-line bg-canvas p-3">@foreach(\App\Enums\ReportReason::options() as $reason => $label)<option value="{{ $reason }}">{{ $label }}</option>@endforeach</select></label>
        <label class="block">{{ __('community.report_note') }}<textarea name="note" maxlength="1000" class="mt-2 w-full border-2 border-line bg-canvas p-3"></textarea></label>
        <x-button type="submit" variant="secondary">{{ __('community.report') }}</x-button>
    </form>
</details>
@endauth
