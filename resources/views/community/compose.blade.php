<x-layouts.app :narrow="true" :meta="$meta">
    <div class="mx-auto max-w-2xl">@include('community.nav')<h1 class="text-hero">{{ $meta->heading }}</h1><h2 class="mt-5 font-display text-xl font-bold">{{ $saved->occurrence->event->title }}</h2><p class="mt-2 text-sm text-ink-muted">{{ $saved->occurrence->starts_at->timezone(app(\App\Support\CurrentCity::class)->timezone())->format('d/m/Y H:i') }}</p>
    <p class="my-6 text-ink-muted">{{ __('community.privacy_help') }}</p>
    <form method="post" action="{{ route('community.publish', $saved->occurrence_id) }}" class="space-y-6">@csrf @method('PUT')
        <fieldset class="space-y-3"><legend class="font-semibold">{{ __('community.save_privacy') }}</legend>
            <label class="flex min-h-11 items-center gap-3"><input type="radio" name="visibility" value="private" @checked(old('visibility', $saved->visibility->value) === 'private')>{{ __('community.private') }}</label>
            <label class="flex min-h-11 items-center gap-3"><input type="radio" name="visibility" value="public" @disabled(!auth()->user()->isWhatsappVerified()) @checked(old('visibility', $saved->visibility->value) === 'public')>{{ __('community.public') }}</label>
        </fieldset>
        @if(auth()->user()->isWhatsappVerified())
            <label class="block font-semibold">{{ __('community.intent') }}<select name="intent" class="mt-2 w-full border-2 border-line bg-canvas p-3">@foreach(\App\Enums\PostIntent::cases() as $intent)<option value="{{ $intent->value }}" @selected(old('intent', $post?->intent->value) === $intent->value)>{{ __('community.intents.'.$intent->value) }}</option>@endforeach</select></label>
            <label class="block font-semibold">{{ __('community.body') }}<textarea name="body" maxlength="500" rows="4" placeholder="{{ __('community.body_hint') }}" class="mt-2 w-full border-2 border-line bg-canvas p-3">{{ old('body', $post?->body) }}</textarea></label>
        @else<p class="text-sm text-ink-muted">{{ __('community.verification_required') }}</p><a href="{{ route('community.whatsapp') }}" class="underline">{{ __('community.whatsapp.title') }}</a>@endif
        <p class="text-sm text-ink-muted">{{ __('community.withdraw_help') }}</p><x-button type="submit">{{ __('community.save') }}</x-button>
    </form></div>
</x-layouts.app>
