{{-- Il pulsante di un avviso di una volta: attiva, annulla, oppure accedi.
     `prefix` è il gruppo di testi (`subscribe`, `active`, `cancel`, `login`). --}}
@props(['alerts', 'id', 'store', 'destroy', 'prefix'])
@auth
    @if ($alerts->has(auth()->user(), (int) $id))
        <p class="m-0 text-sm sm:text-right">{{ __($prefix.'.active') }}</p>
        <form method="POST" action="{{ route($destroy, $id) }}">@csrf @method('DELETE')<button type="submit" class="inline-flex min-h-12 items-center text-sm underline">{{ __($prefix.'.cancel') }}</button></form>
    @else
        <form method="POST" action="{{ route($store, $id) }}">@csrf<x-button type="submit" variant="secondary" class="min-h-12">{{ __($prefix.'.subscribe') }}</x-button></form>
    @endif
@else
    <a href="{{ route('login', ['redirect' => url()->current()]) }}" class="inline-flex min-h-12 items-center text-sm underline">{{ __($prefix.'.login') }}</a>
@endauth
