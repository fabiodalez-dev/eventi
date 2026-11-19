{{--
    Un consiglio e la sua conversazione. In cima il consiglio con l'evento,
    poi i commenti in una card: ognuno con la foto o le iniziali, il nome, da
    quanto tempo, il testo; le risposte rientrano sotto il commento a cui
    rispondono. In fondo il modulo per scrivere.
--}}
@php
    $control = 'min-h-12 w-full border border-line bg-surface px-3.5 py-2.5 text-base text-ink placeholder:text-ink-subtle focus:border-brand focus:outline-none';
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$post->occurrence->event->title"
        :eyebrow="__('community.post')"
        :back="auth()->check() ? route('community.feed') : route('community.people')"
        :back-label="auth()->check() ? __('community.back_feed') : __('community.back_people')"
        :tabs="\App\Services\Community\CommunityNavigation::tabs('feed')"
        :tabs-label="__('community.nav')"
    >
        @include('community.post-card')
        @include('community.report', ['subject' => $post, 'reportLabel' => __('community.report_post')])

        <x-card id="commenti" :title="__('community.comments')" padding="none">
            @forelse ($comments as $comment)
                @php($data = \App\Http\Resources\V1\CommunityResource::comment($comment, auth()->user(), $visibleProfileIds))
                <article id="comment-{{ $comment->id }}" @class(['svc-row flex gap-3 px-5 py-4 sm:px-6', 'pl-12 sm:pl-16' => $comment->parent_id])>
                    <x-avatar :src="$data['avatar_url']" :name="$data['display_name']" :size="$comment->parent_id ? 'sm' : 'md'" />
                    <div class="flex min-w-0 flex-1 flex-col gap-1.5">
                        <p class="m-0 flex flex-wrap items-baseline gap-x-2 text-sm">
                            @if ($data['handle'])
                                <a class="font-semibold text-ink no-underline hover:text-brand hover:underline" href="{{ route('community.profile', $data['handle']) }}">{{ $data['display_name'] }}</a>
                            @else
                                <span class="font-semibold text-ink">{{ $data['display_name'] }}</span>
                            @endif
                            <time class="text-xs text-ink-muted" datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->locale('it')->diffForHumans() }}</time>
                        </p>
                        @if ($comment->parent_id)
                            <p class="m-0 text-xs text-ink-muted">{{ __('community.reply_to', ['id' => $comment->parent_id]) }}</p>
                        @endif
                        <p class="m-0 break-words whitespace-pre-line text-ink">{{ $comment->body }}</p>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                            @if ($canComment && ! $comment->parent_id)
                                <details class="group w-full">
                                    <summary class="inline-flex min-h-11 cursor-pointer list-none items-center text-sm font-semibold text-brand hover:underline [&::-webkit-details-marker]:hidden">{{ __('community.reply') }}</summary>
                                    <form method="post" action="{{ route('community.comment', $post) }}" class="mt-2 flex flex-col gap-3">
                                        @csrf
                                        <input type="hidden" name="parent_id" value="{{ $comment->id }}">
                                        <label for="risposta-{{ $comment->id }}" class="sr-only">{{ __('community.reply') }}</label>
                                        <textarea id="risposta-{{ $comment->id }}" name="body" maxlength="1000" rows="2" required class="{{ $control }}"></textarea>
                                        <div><x-button type="submit" variant="secondary" size="sm">{{ __('community.send_reply') }}</x-button></div>
                                    </form>
                                </details>
                            @endif
                            @if ($data['can_delete'])
                                <form method="post" action="{{ route('community.comment.delete', $comment) }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="inline-flex min-h-11 cursor-pointer items-center text-sm font-semibold text-ink-muted hover:text-ink hover:underline">{{ __('community.delete_comment') }}</button>
                                </form>
                            @endif
                            @include('community.report', ['subject' => $comment, 'reportLabel' => __('community.report')])
                        </div>
                    </div>
                </article>
            @empty
                <p class="m-0 px-5 py-6 text-sm text-ink-muted sm:px-6">{{ __('community.no_comments') }}</p>
            @endforelse
            <x-slot:footer>
                <div class="flex w-full min-w-0 flex-col gap-4">
                    <x-pagination :paginator="$comments" />
                    @if ($canComment)
                        <form method="post" action="{{ route('community.comment', $post) }}" class="flex flex-col gap-3">
                            @csrf
                            <label for="nuovo-commento" class="text-sm font-semibold text-ink">{{ __('community.comment') }}</label>
                            <textarea id="nuovo-commento" name="body" maxlength="1000" rows="3" required class="{{ $control }}" @error('body') aria-invalid="true" @enderror>{{ old('body') }}</textarea>
                            <div><x-button type="submit">{{ __('community.send_comment') }}</x-button></div>
                        </form>
                    @else
                        <x-community-access-step />
                    @endif
                </div>
            </x-slot:footer>
        </x-card>
    </x-service-page>
</x-layouts.app>
