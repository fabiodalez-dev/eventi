<?php

declare(strict_types=1);

use App\Models\EventOccurrence;
use App\Models\EventPoll;
use App\Models\EventPollVote;
use App\Models\User;
use App\Services\Community\EventPolls;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * I sondaggi fra amici.
 *
 * Le regole che contano non sono il voto in sé: sono le soglie (quante date,
 * quante persone), la scadenza, e il fatto che uscire tolga davvero la propria
 * traccia dall'esito.
 */
beforeEach(function (): void {
    config(['community.enabled' => true]);
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-20 12:00');
    $this->owner = User::factory()->create(['city_id' => $this->city->getKey()]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Tre date dello stesso evento: è il caso normale di un sondaggio. Le date
 * successive nascono sull'evento della prima, non su eventi nuovi.
 *
 * @return list<int>
 */
function pollDates(): array
{
    $first = occurrenceAtLocal(test()->city, test()->category, '2026-09-25 21:00');
    $others = collect(['2026-09-26 21:00', '2026-09-27 21:00'])->map(fn (string $start) => EventOccurrence::factory()->create([
        'event_id' => $first->event_id,
        'starts_at' => localInstant(test()->city, $start)->utc(),
        'ends_at' => localInstant(test()->city, $start)->addHours(2)->utc(),
        'doors_at' => null,
    ]));

    return [$first->getKey(), ...$others->pluck('id')->map(intval(...))->all()];
}

it('crea un sondaggio con le date di uno stesso evento e un link non indovinabile', function (): void {
    $poll = app(EventPolls::class)->create($this->owner, pollDates(), 'Ci vediamo lì?', CarbonImmutable::now()->addDays(5));

    expect($poll->options)->toHaveCount(3)
        ->and(mb_strlen($poll->token))->toBe(32)
        ->and($poll->isOpen())->toBeTrue();
});

it('rifiuta meno di due date, più di cinque e date di eventi diversi', function (): void {
    $dates = pollDates();

    expect(fn () => app(EventPolls::class)->create($this->owner, [$dates[0]], null, CarbonImmutable::now()->addDay()))
        ->toThrow(ValidationException::class);

    $otherEvent = occurrenceAtLocal($this->city, $this->category, '2026-09-28 21:00');
    expect(fn () => app(EventPolls::class)->create($this->owner, [$dates[0], $otherEvent->getKey()], null, CarbonImmutable::now()->addDay()))
        ->toThrow(ValidationException::class);
});

it('pretende una scadenza futura e non oltre due settimane', function (): void {
    $dates = pollDates();

    expect(fn () => app(EventPolls::class)->create($this->owner, $dates, null, CarbonImmutable::now()->subDay()))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(EventPolls::class)->create($this->owner, $dates, null, CarbonImmutable::now()->addDays(20)))
        ->toThrow(ValidationException::class);
});

it('accende e spegne il voto della stessa persona senza contarlo due volte', function (): void {
    $poll = app(EventPolls::class)->create($this->owner, pollDates(), null, CarbonImmutable::now()->addDays(3));
    $option = $poll->options->first();
    $voter = User::factory()->create();

    expect(app(EventPolls::class)->toggle($voter, $poll, $option))->toBeTrue()
        ->and(app(EventPolls::class)->toggle($voter, $poll, $option))->toBeFalse()
        ->and(EventPollVote::query()->count())->toBe(0);
});

it('non accetta voti dopo la scadenza né dopo la chiusura anticipata', function (): void {
    $poll = app(EventPolls::class)->create($this->owner, pollDates(), null, CarbonImmutable::now()->addDays(2));
    $option = $poll->options->first();

    app(EventPolls::class)->close($this->owner, $poll);
    expect(fn () => app(EventPolls::class)->toggle(User::factory()->create(), $poll->fresh(), $option))
        ->toThrow(HttpException::class);

    $open = app(EventPolls::class)->create($this->owner, pollDates(), null, CarbonImmutable::now()->addDays(2));
    Carbon::setTestNow(CarbonImmutable::now()->addDays(3));
    expect(fn () => app(EventPolls::class)->toggle(User::factory()->create(), $open->fresh(), $open->options->first()))
        ->toThrow(HttpException::class);
});

it('lascia chiudere solo a chi ha proposto', function (): void {
    $poll = app(EventPolls::class)->create($this->owner, pollDates(), null, CarbonImmutable::now()->addDays(2));

    expect(fn () => app(EventPolls::class)->close(User::factory()->create(), $poll))
        ->toThrow(HttpException::class);
});

it('non fa entrare più di dieci persone', function (): void {
    $poll = app(EventPolls::class)->create($this->owner, pollDates(), null, CarbonImmutable::now()->addDays(2));
    $option = $poll->options->first();

    foreach (range(1, EventPolls::MAX_PARTICIPANTS) as $ignored) {
        app(EventPolls::class)->toggle(User::factory()->create(), $poll, $option);
    }

    expect(fn () => app(EventPolls::class)->toggle(User::factory()->create(), $poll, $option))
        ->toThrow(ValidationException::class);
});

it('toglie ogni traccia di chi esce', function (): void {
    $poll = app(EventPolls::class)->create($this->owner, pollDates(), null, CarbonImmutable::now()->addDays(2));
    $voter = User::factory()->create();
    foreach ($poll->options as $option) {
        app(EventPolls::class)->toggle($voter, $poll, $option);
    }

    app(EventPolls::class)->leave($voter, $poll);

    $outcome = app(EventPolls::class)->outcome($poll->fresh(), null);
    expect($outcome['participants'])->toBe(0)
        ->and(collect($outcome['options'])->sum('count'))->toBe(0);
});

it('apre la pagina a chi ha il link e la tiene fuori dagli indici', function (): void {
    $poll = app(EventPolls::class)->create($this->owner, pollDates(), null, CarbonImmutable::now()->addDays(2));

    $this->actingAs(User::factory()->create())->get(route('polls.show', $poll->token))
        ->assertOk()->assertSee(__('polls.vote'))->assertSee('noindex', false);
});

it('vota dal sito e mostra il voto come proprio', function (): void {
    $poll = app(EventPolls::class)->create($this->owner, pollDates(), null, CarbonImmutable::now()->addDays(2));
    $voter = User::factory()->create();
    $option = $poll->options->first();

    $this->actingAs($voter)->post(route('polls.vote', [$poll->token, $option]))->assertRedirect();

    $this->actingAs($voter)->get(route('polls.show', $poll->token))->assertOk()->assertSee(__('polls.voted'));
    expect(EventPoll::query()->count())->toBe(1);
});

/**
 * Una data ritirata dopo la creazione del sondaggio.
 *
 * Le date si cancellano in modo reversibile e la chiave esterna a cascata
 * scatta solo su quella vera: l'opzione resta, senza data sotto. Due
 * conseguenze, e nessuna delle due era coperta — la pagina dell'esito si
 * rompeva, e un voto arrivato da una pagina vecchia finiva su un'opzione che
 * nessuno vede più.
 */
it('non si rompe e non accetta voti quando una data viene ritirata', function (): void {
    $poll = app(EventPolls::class)->create($this->owner, pollDates(), 'Ci vediamo lì?', CarbonImmutable::now()->addDays(3));
    $poll->load('options.occurrence');
    $ritirata = $poll->options->first();
    $rimasta = $poll->options->last();

    $ritirata->occurrence->delete();
    $poll->refresh()->load('options.occurrence');

    $esito = app(EventPolls::class)->outcome($poll, $this->owner);
    $mostrate = collect($esito['options'])->map(fn (array $riga): int => (int) $riga['option']->getKey());

    expect($mostrate)->not->toContain($ritirata->getKey())
        ->and($mostrate)->toContain($rimasta->getKey());

    expect(fn () => app(EventPolls::class)->toggle($this->owner, $poll, $ritirata->fresh()))
        ->toThrow(HttpException::class);
});
