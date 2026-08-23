{{--
    «Segui» un locale, un tag o una categoria (§15.7).

    Alimenta il feed e i digest, **non** i promemoria: chi vuole essere avvisato
    di una serata la salva. La differenza è scritta nell'aiuto sotto al
    pulsante, perché è l'unica cosa che distingue i due gesti.

    Chi non è collegato vede un collegamento alla registrazione: qui, a
    differenza del cuore, non c'è nulla da salvare nel browser — un feed senza
    account non esiste.
--}}
@props([
    'type',
    'id',
    'following' => false,
    'label' => null,
    'hint' => false,
])

@php
    $type = $type instanceof \App\Enums\FollowableType ? $type : \App\Enums\FollowableType::from($type);
    $id = (int) $id;
    $label ??= __('account.follow.'.$type->value);
@endphp

<div {{ $attributes->class(['flex flex-col gap-1']) }}>
    @auth
        <form
            method="POST"
            action="{{ $following ? route('account.follows.destroy', ['type' => $type->value, 'id' => $id]) : route('account.follows.store') }}"
        >
            @csrf

            @if ($following)
                @method('DELETE')
            @else
                <input type="hidden" name="type" value="{{ $type->value }}">
                <input type="hidden" name="id" value="{{ $id }}">
            @endif

            <button
                type="submit"
                aria-pressed="{{ $following ? 'true' : 'false' }}"
                @class([
                    'inline-flex items-center gap-1.5 rounded-pill px-3.5 py-2 text-sm font-semibold ring-1 transition',
                    'bg-brand text-on-brand ring-brand' => $following,
                    'bg-surface text-ink ring-line hover:ring-line-strong' => ! $following,
                ])
            >
                {{ $following ? __('account.follow.following') : $label }}
            </button>
        </form>
    @else
        <a
            href="{{ route('account.register') }}"
            class="inline-flex items-center gap-1.5 rounded-pill bg-surface px-3.5 py-2 text-sm font-semibold text-ink ring-1 ring-line transition hover:ring-line-strong"
        >
            {{ $label }}
        </a>
    @endauth

    @if ($hint)
        <p class="max-w-prose text-xs text-ink-subtle">{{ __('account.follow.hint') }}</p>
    @endif
</div>
