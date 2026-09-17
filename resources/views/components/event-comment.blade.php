{{--
    Un singolo commento, con le sue reazioni e le sue risposte.

    Il componente si richiama una volta sola per le risposte (`$reply = true`),
    che non hanno a loro volta figli: l'annidamento si ferma al primo livello.
--}}

@props(['comment', 'event', 'types', 'user' => null, 'reply' => false])

@php
    $mia = $user !== null ? $comment->reactions->firstWhere('user_id', $user->id) : null;
    $nascosto = $comment->status === \App\Enums\EventCommentStatus::Hidden;
    $puoEliminare = $user !== null && $user->can('delete', $comment);
@endphp

<article
    id="commento-{{ $comment->id }}"
    data-commento="{{ $comment->id }}"
    @class([
        'flex flex-col gap-3 border-2 border-line p-4' => ! $reply,
        'flex flex-col gap-3 border-l-2 border-line pl-4' => $reply,
        'opacity-60' => $nascosto,
    ])
>
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <strong>{{ \Illuminate\Support\Str::before($comment->user->name, ' ') }}</strong>
        <time class="text-xs text-ink-muted" datetime="{{ $comment->created_at?->toIso8601String() }}">
            {{ $comment->created_at?->diffForHumans() }}
        </time>
    </div>

    @if ($nascosto)
        {{-- L'autore deve sapere che il suo commento è stato tolto dalla vista,
             o continuerebbe a chiedersi perché nessuno risponde. --}}
        <p class="text-sm font-semibold">{{ __('comments.hidden_notice') }}</p>
    @else
        <p class="whitespace-pre-line break-words">{{ $comment->body }}</p>
    @endif

    <div class="flex flex-wrap items-center gap-2">
        @foreach ($types as $tipo)
            @php($attiva = $mia?->type === $tipo)
            @auth
                <form
                    action="{{ route('events.comments.react', ['slug' => $event->slug, 'comment' => $comment->id]) }}"
                    method="POST"
                    data-reazione
                >
                    @csrf
                    <input type="hidden" name="type" value="{{ $tipo->value }}">
                    <button
                        type="submit"
                        aria-pressed="{{ $attiva ? 'true' : 'false' }}"
                        title="{{ $tipo->label() }}"
                        @class([
                            'ui-action inline-flex min-h-12 items-center gap-1.5 border-2 px-3 py-1.5 text-sm transition',
                            'border-accent text-accent' => $attiva,
                            'border-line text-ink-muted hover:border-accent hover:text-ink' => ! $attiva,
                        ])
                    >
                        <span aria-hidden="true">{{ $tipo->emoji() }}</span>
                        <span class="sr-only">{{ $tipo->label() }}</span>
                    </button>
                </form>
            @else
                <button
                    type="button"
                    data-apri-iscrizione
                    title="{{ $tipo->label() }}"
                    class="ui-action inline-flex min-h-12 items-center gap-1.5 border-2 border-line px-3 py-1.5 text-sm text-ink-muted transition hover:border-accent hover:text-ink"
                >
                    <span aria-hidden="true">{{ $tipo->emoji() }}</span>
                    <span class="sr-only">{{ $tipo->label() }}</span>
                </button>
            @endauth
        @endforeach

        {{-- Il contatore che il JavaScript aggiorna in tempo reale.
             `aria-live` perché chi usa uno screen reader deve sentire il
             numero cambiare, non scoprirlo per caso al prossimo giro. --}}
        <span
            data-conteggio-reazioni
            aria-live="polite"
            class="text-sm text-ink-muted"
        >{{ $comment->reactions_count > 0 ? $comment->reactions_count : '' }}</span>

        @if (! $reply && ! $nascosto)
            @auth
                <button type="button" data-apri-risposta="{{ $comment->id }}" class="ui-action min-h-12 px-2 text-sm underline">
                    {{ __('comments.reply') }}
                </button>
            @else
                <button type="button" data-apri-iscrizione class="ui-action min-h-12 px-2 text-sm underline">
                    {{ __('comments.reply') }}
                </button>
            @endauth
        @endif

        @if ($puoEliminare)
            <form
                action="{{ route('events.comments.destroy', ['slug' => $event->slug, 'comment' => $comment->id]) }}"
                method="POST"
                onsubmit="return confirm('{{ __('comments.delete_confirm') }}')"
            >
                @csrf
                @method('DELETE')
                <button type="submit" class="ui-action min-h-12 px-2 text-sm text-ink-muted underline hover:text-alert">
                    {{ __('comments.delete') }}
                </button>
            </form>
        @endif
    </div>

    @auth
        @if (! $reply && ! $nascosto)
            {{-- Il form di risposta nasce chiuso e lo apre il JavaScript.
                 Senza JavaScript resta visibile: meglio un form in più che un
                 pulsante che non fa niente. --}}
            <form
                action="{{ route('events.comments.store', ['slug' => $event->slug]) }}"
                method="POST"
                data-risposta="{{ $comment->id }}"
                class="flex flex-col gap-2"
                hidden
            >
                @csrf
                <input type="hidden" name="parent_id" value="{{ $comment->id }}">
                <label class="flex flex-col gap-1.5">
                    <span class="text-sm font-semibold">{{ __('comments.reply_to', ['name' => \Illuminate\Support\Str::before($comment->user->name, ' ')]) }}</span>
                    <textarea name="body" rows="3" minlength="3" maxlength="2000" required class="w-full border-2 border-line bg-canvas p-3 focus:border-brand focus:outline-none"></textarea>
                </label>
                <button type="submit" class="ui-action min-h-12 self-start bg-accent px-4 py-2 text-sm font-semibold text-on-accent">
                    {{ __('comments.reply_submit') }}
                </button>
            </form>
        @endif
    @endauth

    @if (! $reply && $comment->replies->isNotEmpty())
        <ol class="mt-1 flex list-none flex-col gap-3 p-0">
            @foreach ($comment->replies as $risposta)
                <li>
                    <x-event-comment :comment="$risposta" :event="$event" :types="$types" :user="$user" :reply="true" />
                </li>
            @endforeach
        </ol>
    @endif
</article>
