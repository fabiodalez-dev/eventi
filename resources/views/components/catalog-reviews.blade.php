@props(['subject', 'reviews'])
@php($reviewRoute = $subject instanceof \App\Models\Venue ? 'venues' : 'organizers')
<section id="recensioni" class="mt-8 scroll-mt-28 border-t border-line pt-6" aria-labelledby="reviews-title">
    <h2 id="reviews-title" class="mb-4 text-2xl font-bold">{{ __('reviews.titles.'.$reviewRoute) }}</h2>
    @if ($reviews['count'] > 0)
        <p class="mb-6 flex flex-wrap items-baseline gap-3">@if($reviews['average'] !== null)<strong class="text-2xl">★ {{ __('reviews.average', ['rating' => $reviews['average']]) }}</strong>@endif<span class="text-sm text-ink-muted">{{ __('reviews.count', ['count' => $reviews['count']]) }}</span></p>
    @else
        <p class="mb-6 text-ink-muted">{{ __('reviews.empty') }}</p>
    @endif
    <div class="grid gap-4 md:gap-6">
        @foreach ($reviews['reviews'] as $review)
            <article class="border border-line p-5">
                <div class="flex flex-wrap justify-between gap-3"><strong>{{ $review['author'] }}</strong>@if($review['rating'] !== null)<span aria-label="{{ __('reviews.average', ['rating' => $review['rating']]) }}"><span aria-hidden="true" class="text-accent">{{ str_repeat('★', $review['rating']) }}{{ str_repeat('☆', 5 - $review['rating']) }}</span></span>@endif</div>
                <p class="mt-3 whitespace-pre-line break-words">{{ $review['body'] }}</p>
                <time class="mt-3 block text-xs text-ink-muted" datetime="{{ $review['date'] }}">{{ $review['date'] }}</time>
                @if($reviews['verified'])
                <details class="mt-3"><summary class="min-h-12 cursor-pointer py-3 text-sm underline">{{ __('reviews.report') }}</summary><form method="post" action="{{ route($reviewRoute.'.review.report', ['slug' => $subject->slug, 'review' => $review['id']]) }}" class="space-y-3">@csrf<label class="block">{{ __('reviews.report_reason') }}<textarea name="body" required minlength="10" maxlength="3000" class="mt-2 w-full rounded-xl border border-line bg-canvas p-3"></textarea></label><x-button type="submit">{{ __('reviews.report_send') }}</x-button></form></details>
                @endif
            </article>
        @endforeach
    </div>
    @if ($reviews['last_page'] > 1)
        <nav class="my-5 flex gap-4" aria-label="{{ __('reviews.titles.'.$reviewRoute) }}">
            @if ($reviews['page'] > 1)<a class="underline" href="{{ request()->fullUrlWithQuery(['recensioni' => $reviews['page'] - 1]) }}#recensioni">{{ __('reviews.previous') }}</a>@endif
            @if ($reviews['page'] < $reviews['last_page'])<a class="underline" href="{{ request()->fullUrlWithQuery(['recensioni' => $reviews['page'] + 1]) }}#recensioni">{{ __('reviews.next') }}</a>@endif
        </nav>
    @endif
    @auth
        @php($own = $reviews['my_review'])
        <div class="mt-6 border-t border-line pt-6">
            <h3 class="text-xl font-semibold">{{ __('reviews.write') }}</h3>
            @if ($own)<p class="my-3 font-semibold" role="status">{{ __('reviews.'.$own['status']) }}</p>@if ($own['moderation_note'])<p class="mb-3 whitespace-pre-line">{{ $own['moderation_note'] }}</p>@endif @endif
            @if ($reviews['can_review'])
                <form action="{{ route($reviewRoute.'.review.store', ['slug' => $subject->slug]) }}" method="POST" class="mt-4 flex flex-col gap-4" data-review-form>
                    @csrf
                    <input type="hidden" name="revision" value="{{ $own['revision'] ?? 0 }}">
                    @error('revision')<p role="alert" class="text-alert">{{ $message }}</p>@enderror
                    <fieldset><legend class="mb-2 font-semibold">{{ __('reviews.rating') }}</legend><div class="grid grid-cols-5 gap-2">
                        @for ($star = 1; $star <= 5; $star++)
                            <label class="flex min-h-12 cursor-pointer justify-center items-center gap-1 border border-line px-1"><input type="radio" name="rating" value="{{ $star }}" @checked((int) old('rating', $own['rating'] ?? 0) === $star)><span aria-hidden="true">{{ $star }} ★</span><span class="sr-only">{{ __('reviews.average', ['rating' => $star]) }}</span></label>
                        @endfor
                    </div><label class="flex min-h-12 items-center gap-2"><input type="radio" name="rating" value="" @checked(!old('rating', $own['rating'] ?? null))>{{ __('reviews.no_rating') }}</label>@error('rating')<p role="alert" class="mt-2 text-alert">{{ $message }}</p>@enderror</fieldset>
                    <label class="flex flex-col gap-2"><span class="font-semibold">{{ __('reviews.body') }}</span><textarea name="body" rows="5" minlength="10" maxlength="3000" class="w-full border border-line bg-canvas p-3">{{ old('body', $own['body'] ?? '') }}</textarea></label>
                    @error('body')<p role="alert" class="text-alert">{{ $message }}</p>@enderror
                    <p class="text-sm text-ink-muted">{{ __('reviews.guidance') }}</p>
                    <button type="submit" class="ui-action min-h-12 self-start bg-accent px-5 py-3 font-semibold text-on-accent">{{ __('reviews.submit') }}</button>
                </form>
            @else
                @if(!$reviews['verified'])<p class="my-3">{{ __('reviews.verification_required') }}</p><x-button :href="route(auth()->user()->hasVerifiedEmail() ? 'community.whatsapp' : 'verification.notice', ['intended' => url()->current().'#recensioni'])">{{ __('reviews.verify') }}</x-button>@else<p>{{ __('reviews.closed') }}</p>@endif
            @endif
            @if ($own)
                <form action="{{ route($reviewRoute.'.review.destroy', ['slug' => $subject->slug]) }}" method="POST" class="mt-4">@csrf @method('DELETE')<button type="submit" class="min-h-12 underline">{{ __('reviews.delete') }}</button></form>
            @endif
        </div>
    @else
        <div class="mt-6 flex flex-wrap items-center gap-4"><a class="ui-action bg-accent px-5 py-3 text-on-accent" href="{{ route('login', ['intended' => url()->current().'#recensioni']) }}">{{ __('reviews.login') }}</a><a class="underline" href="{{ route('account.register') }}">{{ __('reviews.register') }}</a></div>
    @endauth
</section>
