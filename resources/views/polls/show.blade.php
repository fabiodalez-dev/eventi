{{--
    La pagina di un sondaggio fra amici.

    Non è una pagina pubblica: chi arriva qui ha ricevuto il link. Per questo
    mostra i nomi di chi ha risposto — sono le persone che quel link se lo sono
    passate — ma resta fuori dagli indici e dalla bacheca.

    L'esito non sceglie: mostra le date in ordine di preferenza e si ferma lì.
    La decisione di un gruppo di amici non la prende un calcolo.
--}}
<x-layouts.app :narrow="true" :meta="$meta">
    @php($dates = app(\App\Support\DateFormatter::class))

    <div class="flex w-full flex-col gap-8">
        <header class="flex flex-col gap-2">
            <p class="font-display text-xs font-extrabold tracking-widest text-brand uppercase">{{ $poll->event->title }}</p>
            <h1 class="text-hero text-ink">{{ __('polls.title', ['evento' => $poll->event->title]) }}</h1>
            <p class="text-sm text-ink-muted">{{ $outcome['open'] ? __('polls.open_until', ['data' => $poll->closes_at->timezone($poll->event->city->timezone)->format('d/m/Y')]) : __('polls.closed') }}</p>
            @if (filled($poll->note))
                <p class="mt-2 whitespace-pre-line">{{ $poll->note }}</p>
            @endif
        </header>

        @if ($errors->any())
            <p role="alert" class="border-l-4 border-brand bg-surface p-4 text-sm">{{ $errors->first() }}</p>
        @endif

        <ul class="flex flex-col divide-y divide-line border-y border-line">
            @foreach ($outcome['options'] as $index => $row)
                @php($occurrence = $row['option']->occurrence)
                <li class="flex flex-col gap-3 py-5 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex min-w-0 flex-col gap-1">
                        <p class="font-display text-lg font-extrabold">{{ $dates->dayAndTime($occurrence->business_date, $occurrence->starts_at) }}</p>
                        <p class="text-sm text-ink-muted">
                            @if ($row['count'] === 0)
                                {{ __('polls.nobody') }}
                            @else
                                {{ implode(', ', $row['voters']) }}
                            @endif
                        </p>
                        @if ($index === 0 && $row['count'] > 0)
                            <p class="text-xs font-semibold text-accent">{{ __('polls.winner') }}</p>
                        @endif
                    </div>

                    @auth
                        @if ($outcome['open'])
                            @php($mineHere = in_array($row['option']->getKey(), $mine, true))
                            <form method="POST" action="{{ route('polls.vote', [$poll->token, $row['option']]) }}">
                                @csrf
                                <button type="submit" @class([
                                    'ui-action inline-flex min-h-12 items-center px-4 font-display text-sm font-extrabold transition',
                                    'bg-accent text-on-accent hover:bg-brand-strong' => ! $mineHere,
                                    'border-2 border-line bg-surface text-ink hover:border-accent' => $mineHere,
                                ])>{{ $mineHere ? __('polls.voted') : __('polls.vote') }}</button>
                            </form>
                        @endif
                    @endauth
                </li>
            @endforeach
        </ul>

        <p class="text-sm text-ink-muted">{{ __('polls.participants', ['count' => $outcome['participants']]) }}</p>

        @auth
            <div class="flex flex-col gap-4 border-t border-line pt-6">
                <div class="flex flex-col gap-2">
                    <span class="text-sm font-semibold">{{ __('polls.share') }}</span>
                    <input type="text" readonly value="{{ route('polls.show', $poll->token) }}" class="w-full border border-line bg-surface p-3 text-sm" onfocus="this.select()">
                </div>

                <div class="flex flex-wrap gap-4">
                    @if ($outcome['open'] && auth()->id() === $poll->user_id)
                        <form method="POST" action="{{ route('polls.close', $poll->token) }}">@csrf
                            <button type="submit" class="ui-action inline-flex min-h-12 items-center border-2 border-line bg-surface px-4 text-sm font-semibold hover:border-accent">{{ __('polls.close') }}</button>
                        </form>
                    @endif
                    @if ($mine !== [])
                        <form method="POST" action="{{ route('polls.leave', $poll->token) }}">@csrf
                            <button type="submit" class="ui-action inline-flex min-h-12 items-center px-4 text-sm text-ink-muted underline underline-offset-4 hover:text-ink">{{ __('polls.leave') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        @endauth
    </div>
</x-layouts.app>
