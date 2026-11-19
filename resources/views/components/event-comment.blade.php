{{--
    Un singolo commento, in forma di messaggio di una conversazione.

    Avatar a sinistra; a destra il fumetto con nome, tempo e testo, e sotto il
    fumetto la riga delle azioni: le tre reazioni, «Rispondi» e, per chi può,
    «Elimina». Il tempo sta nel fumetto perché sul telefono, in una risposta
    rientrata, la riga delle azioni non avrebbe spazio per tutto. Il totale delle reazioni sta in una pastiglia
    appoggiata all'angolo del fumetto e sparisce quando è zero.

    Il componente si richiama una volta sola per le risposte (`$reply = true`),
    che non hanno a loro volta figli: l'annidamento si ferma al primo livello.
    Le risposte stanno nella colonna del fumetto, quindi rientrano di un
    avatar senza bisogno di altri margini.
--}}

@props(['comment', 'event', 'types', 'user' => null, 'reply' => false, 'thread' => null])

@php
    $mia = $user !== null ? $comment->reactions->firstWhere('user_id', $user->id) : null;
    $nascosto = $comment->status === \App\Enums\EventCommentStatus::Hidden;
    $puoEliminare = $user !== null && $user->can('delete', $comment);
    $verificato = $user?->hasVerifiedEmail() === true;
    $nome = \App\Support\PublicName::first($comment->user);
    $ritorno = request()->fullUrl().'#commenti';
    $altre = (! $reply && $thread === null) ? max(0, (int) $comment->replies_count - $comment->replies->count()) : 0;
@endphp

<article
    id="commento-{{ $comment->id }}"
    data-commento="{{ $comment->id }}"
    @class([
        'comment scroll-mt-28',
        'comment--reply' => $reply,
        'comment--hidden' => $nascosto,
        'comment--mine' => $user !== null && $comment->user_id === $user->id,
    ])
>
    <x-avatar :name="$nome" :size="$reply ? 'sm' : 'md'" class="comment-avatar" data-tone="{{ (int) $comment->user_id % 3 }}" />

    <div class="comment__main">
        <div class="comment__bubble">
            <p class="comment__meta">
                <span class="comment__author">{{ $nome }}</span>
                <span aria-hidden="true">·</span>
                <time class="comment__time" datetime="{{ $comment->created_at?->toIso8601String() }}" title="{{ $comment->created_at?->timezone($event->city?->timezone ?? config('app.timezone'))->isoFormat('LLL') }}">
                    {{ $comment->created_at?->diffForHumans() }}
                </time>
            </p>
            @if ($nascosto)
                {{-- L'autore deve sapere che il suo commento è stato tolto dalla
                     vista, o continuerebbe a chiedersi perché nessuno risponde. --}}
                <p class="comment__body comment__body--notice">{{ __('comments.hidden_notice') }}</p>
            @else
                <p class="comment__body">{{ $comment->body }}</p>
                {{-- Il contatore che il JavaScript aggiorna in tempo reale.
                     `aria-live` perché chi usa uno screen reader deve sentire il
                     numero cambiare, non scoprirlo per caso al prossimo giro. --}}
                <span class="comment__tally">
                    <svg aria-hidden="true" class="size-3.5" viewBox="0 0 24 24" fill="currentColor"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>
                    <span data-conteggio-reazioni aria-live="polite">{{ $comment->reactions_count > 0 ? $comment->reactions_count : '' }}</span>
                    <span class="sr-only">{{ __('comments.reactions.label') }}</span>
                </span>
            @endif
        </div>

        <div class="comment__actions">
            @unless ($nascosto)
                <span class="comment__reactions" role="group" aria-label="{{ __('comments.reactions.group', ['name' => $nome]) }}">
                    @foreach ($types as $tipo)
                        @if ($verificato)
                            <form
                                action="{{ route('events.comments.react', ['slug' => $event->slug, 'comment' => $comment->id]) }}"
                                method="POST"
                                data-reazione
                            >
                                @csrf
                                <input type="hidden" name="type" value="{{ $tipo->value }}">
                                <button type="submit" class="comment__reaction" aria-pressed="{{ $mia?->type === $tipo ? 'true' : 'false' }}" title="{{ $tipo->label() }}">
                                    <span aria-hidden="true">{{ $tipo->emoji() }}</span>
                                    <span class="sr-only">{{ $tipo->label() }}</span>
                                </button>
                            </form>
                        @else
                            {{-- Ospite: il modale dell'iscrizione. Account da
                                 confermare: la pagina che manda il link. --}}
                            <a
                                href="{{ $user === null ? route('login', ['intended' => $ritorno]) : route('verification.notice', ['intended' => $ritorno]) }}"
                                @if ($user === null) data-apri-iscrizione @endif
                                class="comment__reaction"
                                title="{{ $tipo->label() }}"
                            >
                                <span aria-hidden="true">{{ $tipo->emoji() }}</span>
                                <span class="sr-only">{{ $tipo->label() }}</span>
                            </a>
                        @endif
                    @endforeach
                </span>

                @if ($verificato)
                    <button type="button" class="comment__action" data-apri-risposta="{{ $comment->id }}" aria-expanded="false" aria-controls="risposta-{{ $comment->id }}">
                        {{ __('comments.reply') }}
                        <span class="sr-only">{{ __('comments.reply_to_sr', ['name' => $nome]) }}</span>
                    </button>
                @elseif ($user !== null)
                    <a class="comment__action" href="{{ route('verification.notice', ['intended' => $ritorno]) }}">{{ __('comments.reply') }}</a>
                @else
                    <a class="comment__action" href="{{ route('login', ['intended' => $ritorno]) }}" data-apri-iscrizione>{{ __('comments.reply') }}</a>
                @endif
            @endunless

            @if ($puoEliminare)
                <form
                    action="{{ route('events.comments.destroy', ['slug' => $event->slug, 'comment' => $comment->id]) }}"
                    method="POST"
                    data-conferma-eliminazione="{{ __('comments.delete_confirm') }}"
                >
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="comment__action comment__action--danger">{{ __('comments.delete') }}</button>
                </form>
            @endif
        </div>

        @if (! $nascosto && $verificato)
            {{-- Il form di risposta nasce chiuso e lo apre il JavaScript.
                 Senza JavaScript resta visibile: meglio un form in più che un
                 pulsante che non fa niente. --}}
            <form
                id="risposta-{{ $comment->id }}"
                action="{{ route('events.comments.store', ['slug' => $event->slug]) }}"
                method="POST"
                data-risposta="{{ $comment->id }}"
                data-risposta-error="{{ (int) old('parent_id') === $comment->id ? '1' : '0' }}"
                class="comment__reply-form"
            >
                @csrf
                <input type="hidden" name="parent_id" value="{{ $comment->id }}">
                <x-avatar :name="\App\Support\PublicName::first($user)" size="sm" class="comment-avatar" data-tone="{{ $user->id % 3 }}" />
                <div class="comments__field">
                    <label for="risposta-testo-{{ $comment->id }}" class="comment__reply-label">{{ __('comments.reply_to', ['name' => $nome]) }}</label>
                    <textarea
                        id="risposta-testo-{{ $comment->id }}"
                        name="body"
                        rows="2"
                        minlength="3"
                        maxlength="2000"
                        required
                        data-cresce
                        placeholder="{{ __('comments.reply_placeholder') }}"
                        class="comments__input"
                    >{{ (int) old('parent_id') === $comment->id ? old('body') : '' }}</textarea>
                    @if ((int) old('parent_id') === $comment->id)
                        @error('body')<p role="alert" class="comments__error">{{ $message }}</p>@enderror
                    @endif
                    <div class="comment__reply-buttons">
                        <x-button variant="ghost" size="sm" data-chiudi-risposta="{{ $comment->id }}" hidden>{{ __('comments.cancel') }}</x-button>
                        <x-button type="submit" size="sm">{{ __('comments.reply_submit') }}</x-button>
                    </div>
                </div>
            </form>
        @endif

        @if (! $reply && $comment->replies->isNotEmpty())
            <ol class="comment__replies" aria-label="{{ trans_choice('comments.replies', $comment->replies_count, ['count' => $comment->replies_count]) }}">
                @foreach ($comment->replies as $risposta)
                    <li>
                        <x-event-comment :comment="$risposta" :event="$event" :types="$types" :user="$user" :reply="true" />
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($altre > 0)
            <a class="comment__more" href="{{ $comment->setRelation('event', $event)->permalink() }}">
                <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 10 5 5-5 5"/><path d="M4 4v7a4 4 0 0 0 4 4h12"/></svg>
                {{ trans_choice('comments.more_replies_count', $altre, ['count' => $altre]) }}
            </a>
        @endif
    </div>
</article>
