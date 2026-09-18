{{--
    I commenti alla scheda di un evento.

    ## Renderizzato dal server, non disegnato dal JavaScript

    I commenti sono testo che vale la pena indicizzare: una domanda pratica e
    la sua risposta («si parcheggia vicino?») sono esattamente ciò che
    qualcuno cercherà. Se li disegnasse il JavaScript dopo il caricamento,
    per i motori di ricerca non esisterebbero.

    Per lo stesso motivo tutto qui funziona **senza JavaScript**: form con
    `@csrf` e redirect col frammento, come le recensioni dei locali. Il
    contatore che si aggiorna senza ricaricare è uno strato sopra.

    ## Un livello di risposte

    Le risposte stanno dentro il commento a cui rispondono, rientrate una
    volta sola. Rispondere a una risposta riporta allo stesso capostipite:
    vedi `App\Actions\Comments\PostComment`.
--}}

@props(['event', 'comments', 'page' => 1, 'lastPage' => 1, 'total' => 0, 'thread' => null, 'repliesPage' => 1, 'repliesLastPage' => 1])

@php
    $utente = auth()->user();
    $tipiReazione = \App\Enums\EventCommentReactionType::cases();
@endphp

<section id="commenti" aria-labelledby="commenti-titolo" class="scroll-mt-28 flex flex-col gap-5">
    <h2 id="commenti-titolo" class="font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">
        {{ __('comments.title') }}
        <span class="text-ink-muted">{{ $total > 0 ? '('.$total.')' : '' }}</span>
    </h2>

    @if (session('status'))
        <p role="status" class="border-2 border-line bg-surface p-3 text-sm font-semibold">{{ session('status') }}</p>
    @endif

    @if ($comments->isEmpty())
        <p class="text-ink-muted">{{ __('comments.empty') }}</p>
    @else
        <ol class="flex list-none flex-col gap-4 p-0">
            @foreach ($comments as $commento)
                <li>
                    <x-event-comment
                        :comment="$commento"
                        :event="$event"
                        :types="$tipiReazione"
                        :user="$utente"
                        :thread="$thread"
                    />
                </li>
            @endforeach
        </ol>
    @endif

    @if ($thread !== null)
        <nav class="flex flex-wrap gap-4" aria-label="{{ __('comments.replies', ['count' => 2]) }}">
            <a class="min-h-12 inline-flex items-center underline" href="{{ route('events.show', ['slug' => $event->slug]) }}#commenti">{{ __('comments.all') }}</a>
            @if ($repliesPage > 1)
                <a class="min-h-12 inline-flex items-center underline" href="{{ route('events.show', ['slug' => $event->slug, 'commento' => $thread, 'risposte' => $repliesPage - 1]) }}#commenti">{{ __('comments.previous') }}</a>
            @endif
            @if ($repliesPage < $repliesLastPage)
                <a class="min-h-12 inline-flex items-center underline" href="{{ route('events.show', ['slug' => $event->slug, 'commento' => $thread, 'risposte' => $repliesPage + 1]) }}#commenti">{{ __('comments.more_replies') }}</a>
            @endif
        </nav>
    @endif

    @if ($lastPage > 1)
        <nav class="flex gap-4" aria-label="{{ __('comments.title') }}">
            @if ($page > 1)
                <a class="min-h-12 inline-flex items-center underline" href="{{ request()->fullUrlWithQuery(['commenti' => $page - 1]) }}#commenti">{{ __('comments.previous') }}</a>
            @endif
            @if ($page < $lastPage)
                <a class="min-h-12 inline-flex items-center underline" href="{{ request()->fullUrlWithQuery(['commenti' => $page + 1]) }}#commenti">{{ __('comments.next') }}</a>
            @endif
        </nav>
    @endif

    @auth
        @if ($utente->hasVerifiedEmail())
        <form
            action="{{ route('events.comments.store', ['slug' => $event->slug]) }}"
            method="POST"
            class="flex flex-col gap-3 border-t-2 border-line pt-5"
        >
            @csrf
            <label class="flex flex-col gap-2">
                <span class="font-semibold">{{ __('comments.write') }}</span>
                <textarea
                    name="body"
                    rows="4"
                    minlength="3"
                    maxlength="2000"
                    required
                    placeholder="{{ __('comments.placeholder') }}"
                    class="w-full border-2 border-line bg-canvas p-3 focus:border-brand focus:outline-none"
                >{{ old('parent_id') ? '' : old('body') }}</textarea>
            </label>
            @error('body')<p role="alert" class="text-alert">{{ $message }}</p>@enderror

            <p class="text-sm text-ink-muted">{{ __('comments.guidance') }}</p>

            <button type="submit" class="ui-action min-h-12 self-start bg-accent px-5 py-3 font-semibold text-on-accent">
                {{ __('comments.submit') }}
            </button>
        </form>
        @else
            <p class="border-t-2 border-line pt-5">{{ __('comments.verify_required') }}</p>
            <a class="min-h-12 inline-flex items-center underline" href="{{ route('verification.notice', ['intended' => request()->fullUrl().'#commenti']) }}">{{ __('account.verify.title') }}</a>
        @endif
    @else
        {{-- Niente ospiti: per commentare serve un profilo. Il pulsante apre
             il modale invece di portare via dalla pagina, così chi si iscrive
             non perde l'evento che stava leggendo. --}}
        <div class="border-t-2 border-line pt-5">
            <a href="{{ route('login', ['intended' => request()->fullUrl().'#commenti']) }}" data-apri-iscrizione
                class="ui-action min-h-12 inline-flex items-center bg-accent px-5 py-3 font-semibold text-on-accent"
            >
                {{ __('comments.join.prompt') }}
            </a>
        </div>
    @endauth
</section>

@guest
    <x-join-to-participate />
@endguest
