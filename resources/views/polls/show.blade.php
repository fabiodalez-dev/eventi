{{--
    La pagina di un sondaggio fra amici.

    Non è una pagina pubblica: chi arriva qui ha ricevuto il link. Per questo
    mostra i nomi di chi ha risposto — sono le persone che quel link se lo sono
    passate — ma resta fuori dagli indici e dalla bacheca.

    L'esito non sceglie: mostra le date in ordine di preferenza e si ferma lì.
    La decisione di un gruppo di amici non la prende un calcolo.

    In alto l'evento con la sua locandina, poi una card con le date (una riga
    per data, con chi ha risposto e il pulsante «Mi va bene»), poi il link da
    passare e le azioni sul sondaggio.
--}}
@php($dates = app(\App\Support\DateFormatter::class))
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="__('polls.title', ['evento' => $poll->event->title])"
        :eyebrow="$outcome['open'] ? __('polls.open_until', ['data' => $poll->closes_at->timezone($poll->event->city->timezone)->format('d/m/Y')]) : __('polls.closed')"
        :subtitle="$outcome['open'] ? __('polls.lead') : null"
    >
        <x-card padding="sm">
            <x-service-event-row :event="$poll->event" :meta="$poll->event->locationVenue()?->name" />
            @if (filled($poll->note))
                <p class="m-0 border-l-4 border-accent pl-3 whitespace-pre-line text-ink">{{ $poll->note }}</p>
            @endif
        </x-card>

        <x-card :title="__('account.polls.dates_title')" :description="__('polls.participants', ['count' => $outcome['participants']])" padding="none" data-poll-options>
            @foreach ($outcome['options'] as $index => $row)
                @php($occurrence = $row['option']->occurrence)
                <div class="svc-row flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div class="flex min-w-0 flex-col gap-1">
                        <p class="m-0 flex flex-wrap items-center gap-2 font-display text-lg leading-tight font-extrabold text-ink">
                            {{ $dates->dayAndTime($occurrence->business_date, $occurrence->starts_at) }}
                            @if ($index === 0 && $row['count'] > 0)
                                <x-badge tone="accent" size="sm">{{ __('polls.winner') }}</x-badge>
                            @endif
                        </p>
                        <p class="m-0 text-sm text-ink-muted">{{ $row['count'] === 0 ? __('polls.nobody') : implode(', ', $row['voters']) }}</p>
                    </div>

                    @auth
                        @if ($outcome['open'])
                            @php($mineHere = in_array($row['option']->getKey(), $mine, true))
                            <form method="POST" action="{{ route('polls.vote', [$poll->token, $row['option']]) }}" class="shrink-0">
                                @csrf
                                <x-button type="submit" size="sm" :variant="$mineHere ? 'secondary' : 'primary'" :block="true" aria-pressed="{{ $mineHere ? 'true' : 'false' }}">
                                    @if ($mineHere)<x-lucide name="check" class="size-4" />@endif
                                    {{ $mineHere ? __('polls.voted') : __('polls.vote') }}
                                </x-button>
                            </form>
                        @endif
                    @endauth
                </div>
            @endforeach
        </x-card>

        @auth
            <x-card :title="__('polls.share')">
                <label class="flex flex-col gap-2">
                    <span class="sr-only">{{ __('polls.share') }}</span>
                    <input type="text" readonly value="{{ route('polls.show', $poll->token) }}" class="min-h-12 w-full border-2 border-line bg-canvas px-3 text-sm text-ink" data-poll-link>
                </label>
                @if (($outcome['open'] && auth()->id() === $poll->user_id) || $mine !== [])
                    <x-slot:footer>
                        @if ($outcome['open'] && auth()->id() === $poll->user_id)
                            <form method="POST" action="{{ route('polls.close', $poll->token) }}">@csrf
                                <x-button type="submit" variant="secondary" size="sm">{{ __('polls.close') }}</x-button>
                            </form>
                        @endif
                        @if ($mine !== [])
                            <form method="POST" action="{{ route('polls.leave', $poll->token) }}">@csrf
                                <x-button type="submit" variant="ghost" size="sm">{{ __('polls.leave') }}</x-button>
                            </form>
                        @endif
                    </x-slot:footer>
                @endif
            </x-card>
        @else
            <x-notice>
                <p class="m-0">{{ __('account.polls.login_hint') }}</p>
                <div><x-button size="sm" :href="route('login')">{{ __('account.nav.login') }}</x-button></div>
            </x-notice>
        @endauth
    </x-service-page>
</x-layouts.app>
