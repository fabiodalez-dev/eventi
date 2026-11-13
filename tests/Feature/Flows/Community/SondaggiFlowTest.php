<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoFlussiCommunity.php';

use App\Models\EventOccurrence;
use App\Models\EventPoll;
use App\Models\EventPollOption;
use App\Models\EventPollVote;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/*
 * I sondaggi fra amici, come li usa un gruppo vero: chi propone crea il link,
 * gli altri lo aprono, votano e cambiano idea; chi propone chiude, chi non
 * vuole più partecipare esce.
 */

beforeEach(function (): void {
    fcomPrepara();
    $this->city = testCity(['is_active' => true]);
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
    $this->date = fcomDateDelloStessoEvento();
    $this->organizzatrice = fcomPersona('olivia_organizza', 'public', 'Olivia Organizza');
    $this->amico = fcomPersona('andrea_amico', 'public', 'Andrea Amico');
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/**
 * Tre serate dello stesso spettacolo: il caso normale di un sondaggio.
 *
 * @return list<int>
 */
function fcomDateDelloStessoEvento(): array
{
    $prima = fcomData(test()->city, test()->category, '2026-09-15 21:00', ['title' => 'Amleto al Verdi']);
    $altre = collect(['2026-09-16 21:00', '2026-09-17 21:00'])->map(fn (string $inizio) => EventOccurrence::factory()->create([
        'event_id' => $prima->event_id,
        'starts_at' => localInstant(test()->city, $inizio)->utc(),
        'ends_at' => localInstant(test()->city, $inizio)->addHours(3)->utc(),
        'doors_at' => null,
    ]));

    return [$prima->id, ...$altre->pluck('id')->map(intval(...))->all()];
}

/** Crea il sondaggio dal sito, come farebbe chi propone, e lo restituisce. */
function fcomNuovoSondaggio(User $chi, array $date, ?string $nota = null): EventPoll
{
    test()->actingAs($chi, 'web')->post('/sondaggi', ['dates' => $date, 'note' => $nota, 'closes_at' => '2026-09-14'])
        ->assertRedirect()->assertSessionHasNoErrors();

    return EventPoll::query()->where('user_id', $chi->id)->latest('id')->firstOrFail();
}

it('crea un sondaggio, lo fa votare a un amico che poi cambia data, e mostra chi ha scelto cosa', function (): void {
    $this->actingAs($this->organizzatrice, 'web')
        ->post('/sondaggi', ['dates' => $this->date, 'note' => 'Chi viene a teatro?', 'closes_at' => '2026-09-14'])
        ->assertRedirect(route('polls.show', EventPoll::query()->sole()->token));

    $poll = EventPoll::query()->sole();
    expect(mb_strlen($poll->token))->toBe(32)
        ->and($poll->user_id)->toBe($this->organizzatrice->id)
        ->and($poll->options()->pluck('occurrence_id')->sort()->values()->all())->toBe($this->date)
        ->and($poll->closes_at->greaterThan(CarbonImmutable::parse('2026-09-14 00:00')))->toBeTrue();
    $this->assertDatabaseHas('activity_log', ['log_name' => 'community', 'event' => 'poll_created', 'causer_id' => $this->organizzatrice->id]);

    $pagina = '/sondaggi/'.$poll->token;
    $this->get($pagina)->assertOk()->assertSee('Amleto al Verdi')->assertSee('Chi viene a teatro?')->assertSee(__('polls.nobody'));

    [$prima, $seconda] = $poll->options()->orderBy('occurrence_id')->get()->all();
    $this->actingAs($this->amico, 'web')->from($pagina)->post($pagina.'/voti/'.$prima->id)->assertRedirect($pagina);
    expect(EventPollVote::query()->where('user_id', $this->amico->id)->pluck('event_poll_option_id')->all())->toBe([$prima->id]);

    // Cambiare idea: si toglie la prima serata e si sceglie la seconda.
    $this->from($pagina)->post($pagina.'/voti/'.$prima->id)->assertRedirect($pagina);
    $this->from($pagina)->post($pagina.'/voti/'.$seconda->id)->assertRedirect($pagina);
    expect(EventPollVote::query()->where('user_id', $this->amico->id)->pluck('event_poll_option_id')->all())->toBe([$seconda->id]);

    $this->actingAs($this->organizzatrice, 'web')->post($pagina.'/voti/'.$seconda->id)->assertRedirect();
    $this->get($pagina)->assertOk()->assertSee('Andrea Amico')->assertSee(__('polls.winner'));
    $this->actingAs($this->amico, 'web')->get($pagina)->assertOk()->assertSee('Olivia Organizza')->assertSee(__('polls.voted'));
    expect(EventPollVote::query()->where('event_poll_id', $poll->id)->distinct()->count('user_id'))->toBe(2);
});

it('lascia chiudere il sondaggio solo a chi lo ha creato, e dopo la chiusura non accetta voti', function (): void {
    $poll = fcomNuovoSondaggio($this->organizzatrice, $this->date);
    $pagina = '/sondaggi/'.$poll->token;
    $opzione = $poll->options()->firstOrFail();

    $this->actingAs($this->amico, 'web')->post($pagina.'/voti/'.$opzione->id)->assertRedirect();
    $this->post($pagina.'/chiusura')->assertForbidden();
    expect($poll->fresh()->closed_at)->toBeNull();

    $this->actingAs($this->organizzatrice, 'web')->from($pagina)->post($pagina.'/chiusura')
        ->assertRedirect($pagina)->assertSessionHas('status', __('polls.closed_status'));
    expect($poll->fresh()->closed_at)->not->toBeNull()->and($poll->fresh()->isOpen())->toBeFalse();

    // Il voto già dato resta nell'esito; uno nuovo viene rifiutato.
    $this->actingAs($this->amico, 'web')->post($pagina.'/voti/'.$poll->options()->orderByDesc('id')->firstOrFail()->id)->assertStatus(409);
    expect(EventPollVote::query()->count())->toBe(1);
    $this->get($pagina)->assertOk()->assertSee(__('polls.closed'))->assertDontSee('action="'.route('polls.vote', [$poll->token, $opzione]).'"', false);
});

it('rifiuta i sondaggi mal formati, fa uscire chi non vuole più partecipare e non accetta date di un altro sondaggio', function (): void {
    $altroEvento = fcomData($this->city, $this->category, '2026-09-18 21:00', ['title' => 'Un altro spettacolo']);
    $this->actingAs($this->organizzatrice, 'web')->from('/persone');

    $this->post('/sondaggi', ['dates' => [$this->date[0]], 'closes_at' => '2026-09-14'])->assertSessionHasErrors('dates');
    $this->post('/sondaggi', ['dates' => $this->date, 'closes_at' => '2026-10-30'])->assertSessionHasErrors('closes_at');
    $this->post('/sondaggi', ['dates' => [$this->date[0], $altroEvento->id], 'closes_at' => '2026-09-14'])
        ->assertSessionHasErrors(['dates' => __('polls.errors.same_event')]);
    expect(EventPoll::query()->count())->toBe(0);

    $poll = fcomNuovoSondaggio($this->organizzatrice, $this->date);
    $pagina = '/sondaggi/'.$poll->token;
    [$prima, $seconda] = $poll->options()->orderBy('id')->get()->all();

    $this->actingAs($this->amico, 'web')->post($pagina.'/voti/'.$prima->id);
    $this->post($pagina.'/voti/'.$seconda->id);
    $this->actingAs($this->organizzatrice, 'web')->post($pagina.'/voti/'.$prima->id);
    expect(EventPollVote::query()->count())->toBe(3);

    $this->actingAs($this->amico, 'web')->from($pagina)->post($pagina.'/uscita')
        ->assertRedirect($pagina)->assertSessionHas('status', __('polls.left_status'));
    expect(EventPollVote::query()->where('user_id', $this->amico->id)->count())->toBe(0)
        ->and(EventPollVote::query()->where('user_id', $this->organizzatrice->id)->count())->toBe(1);
    $this->actingAs($this->organizzatrice, 'web')->get($pagina)->assertOk()->assertDontSee('Andrea Amico');

    // Un'opzione presa da un altro sondaggio non vale qui.
    $altro = fcomNuovoSondaggio($this->organizzatrice, $this->date);
    $estranea = EventPollOption::query()->where('event_poll_id', $altro->id)->firstOrFail();
    $this->actingAs($this->amico, 'web')->post($pagina.'/voti/'.$estranea->id)->assertNotFound();
    expect(EventPollVote::query()->count())->toBe(1);
});

it('apre il sondaggio solo a chi è entrato e si ferma a dieci partecipanti', function (): void {
    $poll = fcomNuovoSondaggio($this->organizzatrice, $this->date);
    $pagina = '/sondaggi/'.$poll->token;
    $opzione = $poll->options()->firstOrFail();

    fcomEsci();
    $this->get($pagina)->assertRedirect(route('login'));
    $this->post($pagina.'/voti/'.$opzione->id)->assertRedirect(route('login'));
    $this->get('/sondaggi/codice-inventato')->assertRedirect(route('login'));

    // Per votare basta un account confermato: il numero WhatsApp non serve.
    foreach (User::factory()->count(10)->create() as $persona) {
        $this->actingAs($persona, 'web')->post($pagina.'/voti/'.$opzione->id)->assertRedirect()->assertSessionHasNoErrors();
    }
    $this->actingAs($this->amico, 'web')->from($pagina)->post($pagina.'/voti/'.$opzione->id)
        ->assertRedirect($pagina)->assertSessionHasErrors(['vote' => __('polls.errors.full', ['max' => 10])]);

    expect(EventPollVote::query()->where('event_poll_id', $poll->id)->distinct()->count('user_id'))->toBe(10);
    $this->actingAs($this->organizzatrice, 'web')->get($pagina)->assertOk()->assertSee(__('community.member'));
    $this->get('/sondaggi/codice-inventato')->assertNotFound();
});
