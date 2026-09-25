<x-layouts.app :meta="$meta">
    @include('community.nav')
    <div class="max-w-3xl">
        <h1 class="text-hero">{{ $meta->heading }}</h1>
        <p class="mt-5 leading-relaxed text-ink-muted">{{ __('community.whatsapp.lead') }}</p>
        <p class="mt-4 text-sm leading-relaxed text-ink-muted">{{ __('community.whatsapp.privacy') }}</p>
        <p class="mt-3 text-sm text-ink-muted">{{ __('community.whatsapp.badge_help') }}</p>
        @if($exempt)<p role="status" class="my-6 border border-line p-4">{{ __('community.whatsapp.exempt') }}</p>@endif
        @if($verified)
            <p class="my-8 font-semibold">{{ __('community.whatsapp.active') }}</p>
            <x-button :href="route('community.settings')">{{ __('community.settings') }}</x-button>
            <details class="mt-10 border-t border-line pt-5"><summary class="cursor-pointer">{{ __('community.whatsapp.revoke') }}</summary>
                <p class="my-4 text-sm text-ink-muted">{{ __('community.whatsapp.revoke_help') }}</p>
                <form data-community-form method="post" action="{{ route('community.whatsapp.revoke') }}">@csrf @method('DELETE')<x-button type="submit" variant="secondary">{{ __('community.whatsapp.revoke') }}</x-button></form>
            </details>
        @elseif(!$available)
            <p role="status" class="my-8 border border-line bg-surface p-5">{{ __('community.whatsapp.unavailable') }}</p>
        @else
            @if($challenge_id)
                <form data-community-form method="post" action="{{ route('community.whatsapp.confirm') }}" class="my-8 space-y-4">@csrf
                    <input type="hidden" name="challenge_id" value="{{ $challenge_id }}">
                    <label class="block font-semibold" for="code">{{ __('community.whatsapp.code') }}</label>
                    <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required class="w-full border-2 border-line bg-canvas p-4 text-xl tracking-widest">
                    <x-button type="submit">{{ __('community.whatsapp.confirm') }}</x-button>
                </form>
            @endif
            <form data-community-form method="post" action="{{ route('community.whatsapp.send') }}" class="my-8 space-y-4">@csrf
                <label class="block font-semibold" for="phone">{{ __('community.whatsapp.phone') }}</label>
                <input id="phone" name="phone" type="tel" autocomplete="tel" value="{{ old('phone') }}" placeholder="{{ __('community.whatsapp.phone_hint') }}" required maxlength="30" class="w-full border-2 border-line bg-canvas p-4">
                <x-button type="submit">{{ __('community.whatsapp.send') }}</x-button>
            </form>
        @endif
        @unless($verified)<form data-community-form method="post" action="{{ route('community.whatsapp.skip') }}" class="mt-6">@csrf<button class="min-h-11 underline">{{ __('community.whatsapp.later') }}</button></form>@endunless
    </div>
</x-layouts.app>
