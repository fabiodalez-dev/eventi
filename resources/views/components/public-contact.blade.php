@props(['target'])
@php($contact = app(\App\Services\Contact\PublicContactService::class)->settings($target))
@if ($contact['enabled'])
<section id="contatta" class="my-8 scroll-mt-28 border-t border-line pt-6">
    <h2 class="mb-4 text-2xl font-bold">{{ __('contact.title') }} {{ $target->name }}</h2>
    @if(auth()->check() || $contact['guests'])
    <form method="POST" action="{{ route('public.contact', ['type' => $target instanceof \App\Models\Venue ? 'venues' : 'organizers', 'slug' => $target->slug]) }}" class="flex flex-col gap-4">
        @csrf
        @guest
        <label>{{ __('contact.name') }}<input class="mt-2 block min-h-12 w-full border border-line bg-canvas p-3" name="name" maxlength="100" required value="{{ old('name') }}" autocomplete="name"></label>
        <label>{{ __('contact.email') }}<input class="mt-2 block min-h-12 w-full border border-line bg-canvas p-3" name="email" type="email" maxlength="255" required value="{{ old('email') }}" autocomplete="email"></label>
        @endguest
        <label>{{ __('contact.message') }}<textarea class="mt-2 block w-full border border-line bg-canvas p-3" name="message" rows="5" minlength="10" maxlength="5000" required>{{ old('message') }}</textarea></label>
        @foreach(['name', 'email', 'message', 'g-recaptcha-response'] as $field) @error($field)<p role="alert">{{ $message }}</p>@enderror @endforeach
        @guest
            <div class="g-recaptcha" data-sitekey="{{ config('contact.recaptcha_site_key') }}"></div>
            <script @cspNonce src="https://www.google.com/recaptcha/api.js" async defer></script>
        @endguest
        <p class="text-sm text-ink-muted">{{ __('contact.privacy') }}</p>
        <button type="submit" class="ui-action min-h-12 self-start bg-accent px-5 py-3 text-on-accent">{{ __('contact.send') }}</button>
    </form>
    @else
        <a class="underline" href="{{ route('login') }}">{{ __('contact.login') }}</a>
    @endif
</section>
@endif
