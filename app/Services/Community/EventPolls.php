<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityProfile;
use App\Models\EventOccurrence;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollVote;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Support\CurrentCity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * I sondaggi fra amici: poche date, un voto per ciascuna, una scadenza.
 *
 * **Perché le soglie esistono.** Da due a cinque date e fino a dieci persone:
 * sopra, non è più una decisione fra amici ma un sondaggio, e un sondaggio
 * vuole ben altro (inviti, ruoli, moderazione). Meglio dire di no adesso che
 * costruire metà di uno strumento che nessuno userà.
 *
 * **La scadenza è obbligatoria** e non superiore a due settimane: un sondaggio
 * senza fine non è una decisione, è un elenco di intenzioni che invecchia. Alla
 * scadenza il sondaggio mostra l'esito e smette di accettare voti; non sceglie
 * al posto delle persone, perché la data la decidono loro.
 *
 * **Niente bacheca.** Un sondaggio è privato al gruppo che ha il link: non
 * produce trafiletti pubblici e non compare da nessuna parte se non a chi ci
 * partecipa.
 */
final class EventPolls
{
    public const MAX_OPTIONS = 5;

    public const MIN_OPTIONS = 2;

    public const MAX_PARTICIPANTS = 10;

    public const MAX_DAYS = 14;

    /**
     * @param  list<int>  $occurrenceIds
     */
    public function create(User $owner, array $occurrenceIds, ?string $note, CarbonImmutable $closesAt): EventPoll
    {
        $dates = $this->selectable($owner, $occurrenceIds);

        if ($dates->count() < self::MIN_OPTIONS || $dates->count() > self::MAX_OPTIONS) {
            throw ValidationException::withMessages(['dates' => __('polls.errors.dates', ['min' => self::MIN_OPTIONS, 'max' => self::MAX_OPTIONS])]);
        }

        if ($closesAt->isPast() || $closesAt->greaterThan(CarbonImmutable::now()->addDays(self::MAX_DAYS))) {
            throw ValidationException::withMessages(['closes_at' => __('polls.errors.closes_at', ['giorni' => self::MAX_DAYS])]);
        }

        // Tutte le date appartengono allo stesso evento: un sondaggio serve a
        // scegliere quando andare a una cosa, non quale cosa fare.
        $eventIds = $dates->pluck('event_id')->unique();

        if ($eventIds->count() !== 1) {
            throw ValidationException::withMessages(['dates' => __('polls.errors.same_event')]);
        }

        return DB::transaction(function () use ($owner, $dates, $eventIds, $note, $closesAt): EventPoll {
            $poll = EventPoll::query()->create(['token' => Str::random(32), 'user_id' => $owner->getKey(),
                'event_id' => (int) $eventIds->first(), 'note' => $note, 'closes_at' => $closesAt]);

            foreach ($dates as $date) {
                EventPollOption::query()->create(['event_poll_id' => $poll->getKey(), 'occurrence_id' => $date->getKey()]);
            }

            activity('community')->causedBy($owner)->performedOn($poll->event)
                ->withProperties(['poll_id' => $poll->getKey(), 'dates' => $dates->count()])->event('poll_created')->log('poll_created');

            return $poll->fresh(['options.occurrence']);
        });
    }

    /**
     * Accende o spegne il voto di una persona su una data.
     *
     * Il tetto ai partecipanti si verifica dentro la transazione e con il
     * conteggio riletto: due persone che entrano nello stesso istante non
     * devono poter diventare l'undicesima entrambe.
     */
    public function toggle(User $user, EventPoll $poll, EventPollOption $option): bool
    {
        abort_unless($option->event_poll_id === $poll->getKey(), 404);

        return DB::transaction(function () use ($user, $poll, $option): bool {
            $locked = EventPoll::query()->whereKey($poll->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($locked->isOpen(), 409, __('polls.errors.closed'));

            $existing = EventPollVote::query()->where('event_poll_option_id', $option->getKey())
                ->where('user_id', $user->getKey())->lockForUpdate()->first();

            if ($existing !== null) {
                $existing->delete();

                return false;
            }

            $participants = EventPollVote::query()->where('event_poll_id', $locked->getKey())
                ->distinct()->count('user_id');
            $alreadyIn = EventPollVote::query()->where('event_poll_id', $locked->getKey())
                ->where('user_id', $user->getKey())->exists();

            if (! $alreadyIn && $participants >= self::MAX_PARTICIPANTS) {
                throw ValidationException::withMessages(['vote' => __('polls.errors.full', ['max' => self::MAX_PARTICIPANTS])]);
            }

            EventPollVote::query()->create(['event_poll_id' => $locked->getKey(),
                'event_poll_option_id' => $option->getKey(), 'user_id' => $user->getKey()]);

            return true;
        });
    }

    /** Uscire dal sondaggio: i propri voti spariscono, e con loro la propria presenza nell'esito. */
    public function leave(User $user, EventPoll $poll): void
    {
        EventPollVote::query()->where('event_poll_id', $poll->getKey())->where('user_id', $user->getKey())->delete();
    }

    /** Chi ha proposto può chiudere prima della scadenza; nessun altro può farlo. */
    public function close(User $user, EventPoll $poll): void
    {
        abort_unless($poll->user_id === $user->getKey(), 403);

        $poll->forceFill(['closed_at' => CarbonImmutable::now()])->save();
    }

    /**
     * L'esito: per ogni data chi ha detto di sì, in ordine di preferenza.
     *
     * @return array{options: list<array{option: EventPollOption, voters: list<string>, count: int}>, participants: int, open: bool}
     */
    public function outcome(EventPoll $poll, ?User $viewer): array
    {
        $poll->loadMissing(['options.occurrence.event', 'options.votes.user.communityProfile']);
        $options = $poll->options->map(function (EventPollOption $option) use ($viewer): array {
            $voters = $option->votes->map(fn (EventPollVote $vote): string => $this->name($vote->user, $viewer))->values()->all();

            return ['option' => $option, 'voters' => $voters, 'count' => count($voters)];
        })->sortByDesc('count')->values()->all();

        return ['options' => $options, 'open' => $poll->isOpen(),
            'participants' => $poll->votes->pluck('user_id')->unique()->count()];
    }

    /**
     * Il nome con cui una persona compare nell'esito: quello pubblico se ce
     * l'ha, altrimenti il nome generico. Il sondaggio non è il posto dove
     * scoprire l'identità di qualcuno che non l'ha resa pubblica.
     */
    private function name(?User $user, ?User $viewer): string
    {
        if ($user === null) {
            return __('community.member');
        }

        if ($viewer !== null && $user->is($viewer)) {
            return __('polls.you');
        }

        // Il profilo può non esserci: chi vota non deve per forza avere un profilo pubblico.
        $profile = $user->communityProfile;

        return $profile instanceof CommunityProfile ? $profile->display_name : __('community.member');
    }

    /**
     * Le date che si possono mettere in un sondaggio: future, pubbliche e
     * visibili a chi propone. Filtrarle qui evita di costruire un sondaggio
     * su una data annullata o su una bozza.
     *
     * @param  list<int>  $occurrenceIds
     * @return Collection<int, EventOccurrence>
     */
    private function selectable(User $owner, array $occurrenceIds): Collection
    {
        $city = $owner->city ?? app(CurrentCity::class)->get();

        if ($city === null || $occurrenceIds === []) {
            return collect();
        }

        return EventOccurrenceQuery::for($city)->forOccurrences(array_slice(array_unique($occurrenceIds), 0, self::MAX_OPTIONS))
            ->upcoming()->get();
    }
}
