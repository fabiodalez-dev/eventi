<x-layouts.app :meta="$meta">
    @include('community.nav')<div class="max-w-3xl"><h1 class="sr-only">{{ $meta->heading }}</h1>@include('community.post-card')
        @include('community.report', ['subject' => $post])
        <section class="mt-8" aria-labelledby="comments-title"><h2 class="text-section" id="comments-title">{{ __('community.comments') }}</h2>
            @forelse($comments as $comment)
                @php($data = \App\Http\Resources\V1\CommunityResource::comment($comment, auth()->user(), $visibleProfileIds))
                <article class="border-b border-line py-5 {{ $comment->parent_id ? 'ml-6 border-l pl-4' : '' }}" id="comment-{{ $comment->id }}">
                    <div class="text-sm font-bold">@if($data['handle'])<a class="hover:underline" href="{{ route('community.profile', $data['handle']) }}">{{ $data['display_name'] }}</a>@else{{ $data['display_name'] }}@endif</div>
                    @if($comment->parent_id)<p class="mt-1 text-xs text-ink-muted">{{ __('community.reply_to', ['id' => $comment->parent_id]) }}</p>@endif
                    <p class="mt-3 whitespace-pre-line break-words">{{ $comment->body }}</p>
                    @if($data['can_delete'])<form method="post" action="{{ route('community.comment.delete', $comment) }}" class="mt-3">@csrf @method('DELETE')<button class="min-h-11 text-xs underline">{{ __('community.delete_comment') }}</button></form>@endif
                    @if($canComment && !$comment->parent_id)
                        <details class="mt-2"><summary class="cursor-pointer text-sm underline">{{ __('community.reply') }}</summary><form method="post" action="{{ route('community.comment', $post) }}" class="mt-3 space-y-3">@csrf<input type="hidden" name="parent_id" value="{{ $comment->id }}"><label class="block text-sm">{{ __('community.reply') }}<textarea name="body" maxlength="1000" required class="mt-2 w-full border-2 border-line bg-canvas p-3"></textarea></label><x-button type="submit" variant="secondary">{{ __('community.send_comment') }}</x-button></form></details>
                    @endif
                    @include('community.report', ['subject' => $comment])
                </article>
            @empty<p class="my-6 text-sm text-ink-muted">{{ __('community.no_comments') }}</p>@endforelse
            <x-pagination :paginator="$comments" />
            @if($canComment)
                <form method="post" action="{{ route('community.comment', $post) }}" class="mt-6 space-y-4">@csrf<label class="block font-semibold">{{ __('community.comment') }}<textarea name="body" maxlength="1000" rows="3" required class="mt-2 w-full border-2 border-line bg-canvas p-3">{{ old('body') }}</textarea></label>@error('body')<p role="alert" class="text-alert">{{ $message }}</p>@enderror<x-button type="submit">{{ __('community.send_comment') }}</x-button></form>
            @else<x-community-access-step />@endif
        </section>
    </div>
</x-layouts.app>
