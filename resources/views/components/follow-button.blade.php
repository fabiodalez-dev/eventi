{{--
    «Segui», che ora segue davvero.

    Era un pulsante spento con una nota — «funzionerà quando arriveranno gli
    account» — scritta quando gli account non c'erano. Nel frattempo sono
    arrivati, e con loro il feed che *usa già* i follow per scegliere cosa
    mostrare: mancava soltanto il gesto per crearne uno. Rotte, controller,
    azioni e modello erano al loro posto da tempo.

    **Un modulo vero, come il cuore dei salvataggi.** Senza JavaScript invia e
    ricarica la pagina; con JavaScript lo stesso modulo viene intercettato e
    non ricarica niente. È ciò che tiene il gesto a un click in entrambi i
    casi.

    **A chi non ha l'accesso non si mente.** Il pulsante c'è e porta al login,
    con il ritorno a questa pagina: dire «accedi per seguire» è un'informazione,
    un pulsante che non fa niente è un inganno.
--}}
@props([
    'type',
    'id',
    'label' => null,
    'labelFollowing' => null,
])

@php
    $utente = auth()->user();
    $tipo = $type instanceof \App\Enums\FollowableType ? $type : \App\Enums\FollowableType::from((string) $type);
    $segue = $utente !== null && in_array((int) $id, $utente->followedIds($tipo), strict: true);

    /* Le etichette del progetto, non due nuove: `account.follow.venue` dice
       «Segui questo locale» e `account.follow.following` dice «Lo segui già».
       Sono piu' precise di un «Segui» nudo — su una pagina che parla di un
       locale, di eventi e di date, «Segui» da solo non dice cosa. */
    $etichetta = $label ?? __('account.follow.'.$tipo->value);
    $etichettaAttiva = $labelFollowing ?? __('account.follow.following');

    $base = 'px-4 py-2.5 font-display text-[0.688rem] leading-none font-extrabold tracking-[0.14em] uppercase border-2 transition-colors';
@endphp

@guest
    {{-- Il ritorno a questa pagina è nell'indirizzo: chi accede per seguire un
         locale si aspetta di ritrovarsi sul locale, non sul proprio profilo. --}}
    <a
        href="{{ route('login', ['intended' => url()->current()]) }}"
        {{ $attributes->class([$base, 'border-line bg-surface text-ink hover:border-accent hover:text-accent']) }}
    >
        {{ $etichetta }}
    </a>
@endguest

@auth
    <form
        method="POST"
        action="{{ $segue ? route('account.follows.destroy', ['type' => $tipo->value, 'id' => $id]) : route('account.follows.store') }}"
        data-follow
        data-follow-store="{{ route('account.follows.store') }}"
        data-follow-destroy="{{ route('account.follows.destroy', ['type' => $tipo->value, 'id' => $id]) }}"
        data-follow-type="{{ $tipo->value }}"
        data-follow-reload="{{ $tipo === \App\Enums\FollowableType::Organizer ? 'true' : 'false' }}"
        data-follow-id="{{ $id }}"
        data-follow-label="{{ $etichetta }}"
        data-follow-label-following="{{ $etichettaAttiva }}"
        {{ $attributes->class(['contents']) }}
    >
        @csrf

        @if ($segue)
            @method('DELETE')
        @else
            <input type="hidden" name="type" value="{{ $tipo->value }}">
            <input type="hidden" name="id" value="{{ $id }}">
        @endif

        <button
            type="submit"
            aria-pressed="{{ $segue ? 'true' : 'false' }}"
            class="{{ $base }} {{ $segue ? 'border-accent bg-accent text-on-accent' : 'border-line bg-surface text-ink hover:border-accent hover:text-accent' }}"
        >
            {{ $segue ? $etichettaAttiva : $etichetta }}
        </button>
    </form>
@endauth
