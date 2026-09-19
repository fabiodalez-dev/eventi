@php($author = $post->user->communityProfile)
<article class="min-w-0 border-t-2 border-line pt-5" data-community-post="{{ $post->id }}">
    <div class="mb-4 flex items-center gap-3">
        @if($author->avatarUrl())<img src="{{ $author->avatarUrl() }}" alt="" width="40" height="40" class="size-10 rounded-full object-cover" loading="lazy">@endif
        <div class="min-w-0"><a class="font-bold hover:underline" href="{{ route('community.profile', $author->handle) }}">{{ $author->display_name }}</a><span class="ml-2 text-xs text-brand" title="{{ __('community.whatsapp.badge_help') }}">✓ {{ __('community.verified') }}</span>
        <p class="mt-1 text-xs text-ink-muted"><a href="{{ route('community.post', $post) }}">{{ $post->published_at->timezone(app(\App\Support\CurrentCity::class)->timezone())->format('d/m/Y H:i') }}</a> · {{ __('community.intents.'.$post->intent->value) }}</p></div>
    </div>
    @if($post->body)<p class="mb-5 whitespace-pre-line break-words leading-relaxed">{{ $post->body }}</p>@endif
    <x-event-card :occurrence="$post->occurrence" />
    {{-- Stessi margini interni della card evento (pl 26px, pr 22px): altrimenti «Commenti» sporge a sinistra del testo della card. --}}
    <div class="flex flex-wrap items-center justify-between gap-3 py-4 pr-[22px] pl-[26px] text-sm">
        <a class="min-h-11 content-center font-semibold underline" href="{{ route('community.post', $post) }}">{{ __('community.comments') }}</a>
        @if(auth()->id() === $post->user_id)<a class="min-h-11 content-center underline" href="{{ route('community.compose', $post->occurrence_id) }}">{{ __('community.publish') }}</a>@endif
    </div>
</article>
