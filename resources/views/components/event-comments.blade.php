{{--
    I commenti alla scheda di un evento, in forma di conversazione.

    ## Renderizzato dal server, non disegnato dal JavaScript

    I commenti sono testo che vale la pena indicizzare: una domanda pratica e
    la sua risposta («si parcheggia vicino?») sono esattamente ciò che
    qualcuno cercherà. Se li disegnasse il JavaScript dopo il caricamento,
    per i motori di ricerca non esisterebbero.

    Per lo stesso motivo tutto qui funziona **senza JavaScript**: form con
    `@csrf` e redirect col frammento, come le recensioni dei locali. Il
    contatore che si aggiorna senza ricaricare è uno strato sopra.

    ## Lo schema della conversazione

    Avatar, nome e testo in un fumetto; sotto il fumetto il tempo, le reazioni
    e «Rispondi». Il campo per scrivere sta in cima, con l'avatar di chi
    scrive: i commenti sono dal più recente, quindi chi pubblica ritrova il
    suo subito sotto. Il modulo resta figlio diretto della sezione
    (`#commenti > form`), che è il punto a cui si agganciano i test del
    browser.

    ## Un livello di risposte

    Le risposte stanno dentro il commento a cui rispondono, rientrate una
    volta sola. Rispondere a una risposta riporta allo stesso capostipite:
    vedi `App\Actions\Comments\PostComment`.
--}}

@props(['event', 'comments', 'page' => 1, 'lastPage' => 1, 'total' => 0, 'thread' => null, 'repliesPage' => 1, 'repliesLastPage' => 1, 'missing' => false])

@php
    $utente = auth()->user();
    $tipiReazione = \App\Enums\EventCommentReactionType::cases();
    $ritorno = request()->fullUrl().'#commenti';
@endphp

<section id="commenti" aria-labelledby="commenti-titolo" class="comments scroll-mt-28">
    <h2 id="commenti-titolo" class="comments__title font-display text-[clamp(1.25rem,1.8vw,1.75rem)] leading-none font-extrabold tracking-[-0.03em] uppercase">
        {{ __('comments.title') }}
        @if ($total > 0)
            <span class="comments__total" aria-hidden="true">{{ $total }}</span>
            <span class="sr-only">{{ trans_choice('comments.count', $total, ['count' => $total]) }}</span>
        @endif
    </h2>

    @if (session('status'))
        <x-notice tone="success">{{ session('status') }}</x-notice>
    @endif

    {{-- Il commento del link è stato cancellato o nascosto: l'elenco resta,
         e chi è arrivato dal link sa perché non lo trova. L'id è quello
         dell'ancora del permalink (`#commento-N`), così il browser scende
         fin qui invece di restare in cima alla scheda. --}}
    @if ($missing)
        <x-notice id="commento-{{ request()->integer('commento') }}" class="scroll-mt-28">{{ __('comments.unavailable') }}</x-notice>
    @endif

    @auth
        @if ($utente->hasVerifiedEmail())
            <form
                action="{{ route('events.comments.store', ['slug' => $event->slug]) }}"
                method="POST"
                class="comments__composer"
                data-composer
            >
                @csrf
                <x-avatar :name="\App\Support\PublicName::first($utente)" class="comment-avatar" data-tone="{{ $utente->id % 3 }}" />
                <div class="comments__field">
                    <label for="commento-nuovo" class="sr-only">{{ __('comments.write') }}</label>
                    <textarea
                        id="commento-nuovo"
                        name="body"
                        rows="2"
                        minlength="3"
                        maxlength="2000"
                        required
                        data-cresce
                        aria-describedby="commento-nuovo-guida"
                        placeholder="{{ __('comments.placeholder') }}"
                        class="comments__input"
                    >{{ old('parent_id') ? '' : old('body') }}</textarea>
                    @if (! old('parent_id'))
                        @error('body')<p role="alert" class="comments__error">{{ $message }}</p>@enderror
                    @endif
                    <div class="comments__composer-foot">
                        <p id="commento-nuovo-guida" class="comments__hint">{{ __('comments.guidance') }}</p>
                        <x-button type="submit" size="sm" class="shrink-0">
                            <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.54 21.69a.5.5 0 0 0 .94-.03l6.5-19a.5.5 0 0 0-.64-.64l-19 6.5a.5.5 0 0 0-.03.94l7.93 3.18a2 2 0 0 1 1.11 1.11z"/><path d="m21.85 2.15-10.94 10.94"/></svg>
                            {{ __('comments.submit') }}
                        </x-button>
                    </div>
                </div>
            </form>
        @else
            <x-notice :title="__('comments.verify_title')">
                <p>{{ __('comments.verify_required') }}</p>
                <p><x-button variant="secondary" size="sm" :href="route('verification.notice', ['intended' => $ritorno])">{{ __('account.verify.title') }}</x-button></p>
            </x-notice>
        @endif
    @else
        {{-- Niente ospiti: per commentare serve un profilo. Il pulsante apre
             il modale invece di portare via dalla pagina, così chi si iscrive
             non perde l'evento che stava leggendo. --}}
        <div class="comments__guest">
            <span class="svc-avatar size-10 comment-avatar" data-tone="1" aria-hidden="true">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/></svg>
            </span>
            <div class="comments__guest-text">
                <p class="comments__guest-title">{{ __('comments.join.lead') }}</p>
                <p class="comments__hint">{{ __('comments.join.hint') }}</p>
                <x-button :href="route('login', ['intended' => $ritorno])" size="sm" data-apri-iscrizione class="self-start">
                    {{ __('comments.join.prompt') }}
                </x-button>
            </div>
        </div>
    @endauth

    @if ($thread !== null)
        <nav class="comments__thread-nav" aria-label="{{ __('comments.thread_nav') }}">
            <x-button variant="ghost" size="sm" :href="route('events.show', ['slug' => $event->slug]).'#commenti'">
                <x-lucide name="arrow-left" class="size-4" />
                {{ __('comments.all') }}
            </x-button>
        </nav>
    @endif

    @if ($comments->isEmpty())
        <div class="comments__empty">
            <svg aria-hidden="true" class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg>
            <p>{{ __('comments.empty') }}</p>
        </div>
    @else
        <ol class="comments__list">
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

    @if ($thread !== null && ($repliesPage > 1 || $repliesPage < $repliesLastPage))
        <nav class="comments__pager" aria-label="{{ __('comments.replies_nav') }}">
            @if ($repliesPage > 1)
                <x-button variant="secondary" size="sm" :href="route('events.show', ['slug' => $event->slug, 'commento' => $thread, 'risposte' => $repliesPage - 1]).'#commenti'">
                    <x-lucide name="chevron-left" class="size-4" />
                    {{ __('comments.previous_replies') }}
                </x-button>
            @endif
            @if ($repliesPage < $repliesLastPage)
                <x-button variant="secondary" size="sm" :href="route('events.show', ['slug' => $event->slug, 'commento' => $thread, 'risposte' => $repliesPage + 1]).'#commenti'">
                    {{ __('comments.more_replies') }}
                    <x-lucide name="chevron-right" class="size-4" />
                </x-button>
            @endif
        </nav>
    @endif

    @if ($lastPage > 1)
        <nav class="comments__pager" aria-label="{{ __('comments.pages_nav') }}">
            @if ($page > 1)
                <x-button variant="secondary" size="sm" :href="request()->fullUrlWithQuery(['commenti' => $page - 1, 'commento' => null]).'#commenti'">
                    <x-lucide name="chevron-left" class="size-4" />
                    {{ __('comments.previous') }}
                </x-button>
            @endif
            @if ($page < $lastPage)
                <x-button variant="secondary" size="sm" :href="request()->fullUrlWithQuery(['commenti' => $page + 1, 'commento' => null]).'#commenti'">
                    {{ __('comments.next') }}
                    <x-lucide name="chevron-right" class="size-4" />
                </x-button>
            @endif
        </nav>
    @endif
</section>

@guest
    <x-join-to-participate />
@endguest
