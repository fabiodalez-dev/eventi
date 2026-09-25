{{--
    «Ci vado» su una singola data, e chi altro ci va.

    **Privato per difetto.** Salvare una data non dice niente a nessuno: solo
    questo pulsante la rende pubblica, e solo per quella data. Il passo
    indietro è lo stesso pulsante, non una pagina nascosta nelle impostazioni.

    **I nomi si vedono con il numero verificato**, il numero di persone no:
    chi passa di qui sa che qualcuno ci va, ma non chi, finché non è parte
    della community. È la stessa regola della bacheca, e vale anche al
    contrario — chi ha bloccato qualcuno non se lo ritrova in questo elenco.
--}}
@props(['occurrence'])

@php
    $viewer = auth()->user();
    $access = app(\App\Services\Community\CommunityAccess::class);
    $attendees = $access->attendees($occurrence, $viewer)->limit(12)->get();
    $total = $access->attendees($occurrence, $viewer)->count();
    /*
        La propria partecipazione si legge dal proprio salvataggio, non dai
        primi dodici nomi: dal tredicesimo in poi il pulsante direbbe «ci
        vado» a chi ci va già, e premendolo non si toglierebbe dall'elenco.
    */
    $going = $viewer !== null && \App\Models\SavedEvent::query()
        ->where('user_id', $viewer->getKey())
        ->where('occurrence_id', $occurrence->getKey())
        ->where('visibility', \App\Enums\SavedVisibility::Public)
        ->exists();
    $verified = $viewer?->isWhatsappVerified() ?? false;
@endphp

<section class="flex flex-col gap-3 border-t-2 border-line pt-5" aria-labelledby="chi-ci-va-{{ $occurrence->getKey() }}">
    <h3 id="chi-ci-va-{{ $occurrence->getKey() }}" class="font-display text-sm font-extrabold tracking-[0.08em] uppercase">{{ __('community.attendance.title') }}</h3>

    @if ($total > 0)
        <p class="text-sm text-ink-muted">{{ $total === 1 ? __('community.attendance.count_one') : __('community.attendance.count', ['count' => $total]) }}</p>
    @else
        <p class="text-sm text-ink-muted">{{ __('community.attendance.empty') }}</p>
    @endif

    @if ($verified && $attendees->isNotEmpty())
        <ul class="flex flex-wrap gap-x-4 gap-y-2">
            @foreach ($attendees as $attendee)
                @php($profile = $attendee->communityProfile)
                <li class="flex items-center gap-2 text-sm">
                    @if ($profile?->avatarUrl())
                        <img src="{{ $profile->avatarUrl() }}" alt="" width="28" height="28" class="size-7 rounded-full object-cover" loading="lazy">
                    @endif
                    @if ($profile?->handle)
                        <a class="min-h-11 content-center underline underline-offset-4" href="{{ route('community.profile', $profile->handle) }}">{{ $profile->display_name }}</a>
                    @else
                        <span>{{ __('community.member') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @elseif ($total > 0)
        <p class="text-xs text-ink-subtle">{{ __('community.attendance.only_verified') }}</p>
    @endif

    {{-- L'invito a partecipare, in tutti e tre gli stati.

         All'ospite non si mostrava niente: leggeva «sedici persone ci vanno» e
         non aveva un appiglio per unirsi — mentre i commenti, nella stessa
         pagina e a due centimetri di distanza, gli offrivano «Accedi per
         commentare». Due sezioni della stessa scheda trattavano la stessa
         persona in modo opposto.

         A chi è collegato senza numero verificato il collegamento c'era, ma
         diceva soltanto «Verifica il tuo numero WhatsApp»: una riga sottolineata
         che non nomina «Ci vado» e che nessuno collega a questa sezione. --}}
    @auth
        @if ($verified)
            <form method="POST" action="{{ route('community.attendance', $occurrence) }}" class="flex flex-wrap items-center gap-3">
                @csrf
                <input type="hidden" name="going" value="{{ $going ? 0 : 1 }}">
                <button type="submit" @class([
                    'ui-action inline-flex min-h-12 items-center px-4 font-display text-sm font-extrabold transition',
                    'bg-accent text-on-accent hover:bg-brand-strong' => ! $going,
                    'border-2 border-line bg-surface text-ink hover:border-accent' => $going,
                ])>{{ $going ? __('community.attendance.not_going') : __('community.attendance.going') }}</button>
                <span class="text-xs text-ink-subtle">{{ $going ? __('community.attendance.public') : __('community.attendance.hint') }}</span>
            </form>
        @else
            <a
                href="{{ route('community.whatsapp', ['intended' => url()->current()]) }}"
                class="ui-action inline-flex min-h-12 items-center bg-accent px-5 py-3 font-semibold text-on-accent"
            >{{ __('community.attendance.verify_to_go') }}</a>
        @endif
    @else
        {{-- Il pulsante apre il modale di iscrizione invece di portare via dalla
             pagina, come fanno i commenti: chi si iscrive non perde la data che
             stava guardando. --}}
        <a
            href="{{ route('login', ['intended' => request()->fullUrl().'#chi-ci-va-'.$occurrence->getKey()]) }}"
            data-apri-iscrizione
            class="ui-action inline-flex min-h-12 items-center bg-accent px-5 py-3 font-semibold text-on-accent"
        >{{ __('community.attendance.join') }}</a>
    @endauth
</section>
