{{--
    In cima al menu della persona, nei tre pannelli: chi è dentro. Prima il
    menu mostrava solo le iniziali, e chi entra con due account (il proprio e
    quello del locale) non sapeva con quale stava lavorando. Non si clicca:
    i dati si cambiano da «Area personale».
--}}
@php
    $user = filament()->auth()->user();
    $name = filled($user?->name) ? (string) $user->name : null;
@endphp
@if ($user)
    <div class="incitta-user-identity" data-user-identity>
        <span class="fi-sr-only">{{ __('pannelli-impianto.user_menu.signed_in_as') }}</span>
        @if ($name !== null)
            <span class="incitta-user-identity-name">{{ $name }}</span>
            <span class="incitta-user-identity-email">{{ $user->email }}</span>
        @else
            <span class="incitta-user-identity-name">{{ $user->email }}</span>
        @endif
    </div>
@endif
