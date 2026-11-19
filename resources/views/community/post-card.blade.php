{{--
    Un consiglio nella bacheca, nel profilo di una persona e nella sua pagina:
    chi lo dà, quando, cosa dice, e l'evento con la sua locandina. Una card a
    tutta larghezza della colonna, una sotto l'altra: niente griglia di card
    evento grandi che sul telefono diventano una pila di locandine senza
    autore.
--}}
@php
    $author = $post->user->communityProfile;
    $timezone = app(\App\Support\CurrentCity::class)->timezone();
@endphp
<x-card as="article" data-community-post="{{ $post->id }}">
    <div class="flex min-w-0 items-center gap-3">
        <x-avatar :src="$author->avatarUrl()" :name="$author->display_name" />
        <div class="flex min-w-0 flex-col gap-0.5">
            <p class="m-0 flex flex-wrap items-center gap-x-2 gap-y-1">
                <a class="font-semibold break-words text-ink no-underline hover:text-brand hover:underline" href="{{ route('community.profile', $author->handle) }}">{{ $author->display_name }}</a>
                @if ($post->user->isWhatsappVerified())
                    <span class="inline-flex items-center gap-1 text-xs font-semibold text-brand" title="{{ __('community.whatsapp.badge_help') }}"><x-lucide name="check" class="size-4" />{{ __('community.verified') }}</span>
                @endif
            </p>
            <p class="m-0 text-xs text-ink-muted">
                <a class="text-ink-muted no-underline hover:underline" href="{{ route('community.post', $post) }}"><time datetime="{{ $post->published_at->toIso8601String() }}" title="{{ $post->published_at->timezone($timezone)->format('d/m/Y H:i') }}">{{ $post->published_at->locale('it')->diffForHumans() }}</time></a>
                {{ __('common.separator') }} {{ __('community.intents.'.$post->intent->value) }}
            </p>
        </div>
    </div>
    @if ($post->body)
        <p class="m-0 leading-relaxed break-words whitespace-pre-line text-ink">{{ $post->body }}</p>
    @endif
    <x-service-event-row :occurrence="$post->occurrence" />
    {{-- Nella pagina del consiglio i commenti sono già sotto: il pulsante porterebbe alla pagina stessa. --}}
    @php($onPostPage = request()->routeIs('community.post'))
    @php($isAuthor = auth()->id() === $post->user_id)
    @if (! $onPostPage || $isAuthor)
        <x-slot:footer>
            @unless ($onPostPage)
                <x-button size="sm" variant="secondary" :href="route('community.post', $post)">{{ __('community.comments') }}</x-button>
            @endunless
            @if ($isAuthor)
                <x-button size="sm" variant="ghost" :href="route('community.compose', $post->occurrence_id)">{{ __('community.edit_recommendation') }}</x-button>
            @endif
        </x-slot:footer>
    @endif
</x-card>
