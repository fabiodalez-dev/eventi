<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\EventOccurrence;
use App\Models\SavedEvent;
use App\Models\User;
use Carbon\Carbon;

/*
 * Flussi dei salvataggi (§15.1, §15.3): il cuore sul sito e nell'app, la
 * pagina dei salvati, lo stato letto dallo script, il pannello in testata, il
 * calendario personale e la migrazione di ciò che si è salvato da anonimi.
 */

beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-11-12 12:00');
    $this->user = User::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

/** @return list<int> */
function flowsFollowingSavedIds(User $user): array
{
    return $user->savedEvents()->orderBy('occurrence_id')->pluck('occurrence_id')->map(fn ($id): int => (int) $id)->all();
}

it('salva una data dalla scheda e la ritrova in pagina, stato, pannello e app; poi la toglie e sparisce ovunque', function (): void {
    $concerto = occurrenceAtLocal($this->city, $this->category, '2026-11-14 21:00', event: ['title' => 'Concerto al Pedrocchi']);
    occurrenceAtLocal($this->city, $this->category, '2026-11-15 21:00', event: ['title' => 'Mostra non salvata']);

    $this->actingAs($this->user)->get(route('events.show', $concerto->event))->assertOk()->assertSee(__('account.save.action'));
    $this->from(route('events.show', $concerto->event))
        ->post('/salvataggi', ['occurrence_id' => $concerto->id])
        ->assertRedirect(route('events.show', $concerto->event))
        ->assertSessionHas('status', __('account.save.stored'));
    expect(flowsFollowingSavedIds($this->user))->toBe([$concerto->id]);

    $this->get('/i-miei-salvataggi')->assertOk()->assertSee('Concerto al Pedrocchi')->assertDontSee('Mostra non salvata')->assertSee('aria-pressed="true"', false);
    $this->getJson('/salvataggi/stato')->assertOk()->assertExactJson(['ids' => [$concerto->id]])->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/salvataggi/pannello')->assertOk()->assertHeader('X-Saved-Count', '1')->assertSee('Concerto al Pedrocchi')->assertDontSee('Mostra non salvata');
    $this->getJson('/api/v1/me/saved')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.occurrence_id', $concerto->id)
        ->assertJsonPath('data.0.is_saved', true);

    $this->from('/i-miei-salvataggi')->delete('/salvataggi/'.$concerto->id)
        ->assertRedirect('/i-miei-salvataggi')
        ->assertSessionHas('status', __('account.save.removed'));
    expect(SavedEvent::query()->count())->toBe(0);
    $this->getJson('/salvataggi/stato')->assertExactJson(['ids' => []]);
    $this->get('/salvataggi/pannello')->assertHeader('X-Saved-Count', '0')->assertDontSee('Concerto al Pedrocchi');
    $this->get('/i-miei-salvataggi')->assertOk()->assertDontSee('Concerto al Pedrocchi');
    $this->getJson('/api/v1/me/saved')->assertOk()->assertJsonCount(0, 'data');
});

it('salva tutte le serate di un evento in una volta, viene avvisato di una sovrapposizione e ne toglie una dall app', function (): void {
    $prima = occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00', '2026-11-20 23:00', event: ['title' => 'Rassegna corale']);
    $seconda = EventOccurrence::factory()->create([
        'event_id' => $prima->event_id,
        'starts_at' => localInstant($this->city, '2026-11-27 21:00')->utc(),
        'ends_at' => localInstant($this->city, '2026-11-27 23:00')->utc(),
        'doors_at' => null,
    ]);
    $sovrapposta = occurrenceAtLocal($this->city, $this->category, '2026-11-20 22:00', '2026-11-20 23:30', event: ['title' => 'Dj set in contemporanea']);

    $this->actingAs($this->user);
    $tutte = $this->postJson('/salvataggi', ['occurrence_ids' => [$prima->id, $seconda->id]])->assertOk()
        ->assertJsonPath('message', __('account.save.stored'));
    expect($tutte->json('saved'))->toEqualCanonicalizing([$prima->id, $seconda->id])
        ->and(array_keys($tutte->json('interested_counts')))->toEqualCanonicalizing([$prima->id, $seconda->id]);

    // La terza si accavalla alla prima: il messaggio lo dice subito.
    $conflitto = $this->postJson('/salvataggi', ['occurrence_id' => $sovrapposta->id])->assertOk();
    expect($conflitto->json('conflicts.0.occurrence_id'))->toBe($prima->id)
        ->and($conflitto->json('conflicts.0.title'))->toBe('Rassegna corale')
        ->and($conflitto->json('message'))->toBe(__('account.save.stored_conflict', ['title' => 'Rassegna corale', 'time' => '21:00']));

    // Salvare di nuovo la stessa data non la duplica.
    $this->postJson('/salvataggi', ['occurrence_id' => $prima->id])->assertOk();
    expect(flowsFollowingSavedIds($this->user))->toBe([$prima->id, $seconda->id, $sovrapposta->id]);

    // Dall'app toglie la seconda serata: il sito se ne accorge subito.
    $this->deleteJson('/api/v1/me/saved/'.$seconda->id)->assertOk()->assertJsonPath('data.message', __('account.api.unsaved'));
    $stato = $this->getJson('/salvataggi/stato')->assertOk()->json('ids');
    expect($stato)->toEqualCanonicalizing([$prima->id, $sovrapposta->id]);

    // E la rimette dall'app.
    $this->postJson('/api/v1/me/saved', ['occurrence_id' => $seconda->id])->assertCreated()->assertJsonPath('data.occurrence_id', $seconda->id);
    expect($this->getJson('/api/v1/me/saved')->json('data.*.occurrence_id'))->toBe([$prima->id, $sovrapposta->id, $seconda->id]);
});

it('esporta le date salvate nel calendario personale, sul sito e nell app, e il calendario filtrato degli eventi', function (): void {
    $teatro = testCategory(['name' => 'Teatro di prosa']);
    $mia = occurrenceAtLocal($this->city, $this->category, '2026-11-14 21:00', event: ['title' => 'Quartetto salvato']);
    $passata = occurrenceAtLocal($this->city, $this->category, '2026-11-01 21:00', event: ['title' => 'Ricordo di novembre']);
    $altrui = occurrenceAtLocal($this->city, $teatro, '2026-11-16 21:00', event: ['title' => 'Prosa di qualcun altro']);
    SavedEvent::query()->create(['user_id' => $this->user->id, 'occurrence_id' => $passata->id]);
    SavedEvent::query()->create(['user_id' => User::factory()->create()->id, 'occurrence_id' => $altrui->id]);

    $this->actingAs($this->user)->post('/salvataggi', ['occurrence_id' => $mia->id])->assertRedirect();

    $ics = $this->get('/i-miei-salvataggi/calendario.ics')->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertHeader('Content-Disposition', 'attachment; filename="incitta-salvati.ics"')
        ->assertHeader('X-Robots-Tag', 'noindex');
    expect($ics->getContent())->toContain('BEGIN:VCALENDAR', 'Quartetto salvato', 'Ricordo di novembre')->not->toContain('Prosa di qualcun altro')
        ->and(substr_count($ics->getContent(), 'BEGIN:VEVENT'))->toBe(2);

    $app = $this->getJson('/api/v1/me/saved/calendar')->assertOk();
    expect($app->json('data.ics'))->toContain('Quartetto salvato')->not->toContain('Prosa di qualcun altro')
        ->and($app->headers->get('Cache-Control'))->toContain('private', 'no-store');

    // Il calendario degli eventi, filtrato per categoria, chiede l'accesso ma non è personale.
    $filtrato = $this->get('/eventi.ics?category='.$teatro->slug)->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="eventi.ics"');
    expect($filtrato->getContent())->toContain('Prosa di qualcun altro')->not->toContain('Quartetto salvato');

    // Togliere la data dai salvati la toglie anche dal calendario personale.
    $this->delete('/salvataggi/'.$mia->id)->assertRedirect();
    expect($this->get('/i-miei-salvataggi/calendario.ics')->assertOk()->getContent())->not->toContain('Quartetto salvato')->toContain('Ricordo di novembre');

    $this->app['auth']->forgetGuards();
    $this->get('/eventi.ics')->assertRedirect(route('login'));
    $this->get('/i-miei-salvataggi/calendario.ics')->assertRedirect(route('login'));
});

it('salva da anonimo nel browser, accede con la password e ritrova le date unite senza duplicati', function (): void {
    $a = occurrenceAtLocal($this->city, $this->category, '2026-11-13 21:00', event: ['title' => 'Prima serata da anonimo']);
    $b = occurrenceAtLocal($this->city, $this->category, '2026-11-14 21:00', event: ['title' => 'Seconda serata da anonimo']);
    $c = occurrenceAtLocal($this->city, $this->category, '2026-11-21 21:00', event: ['title' => 'Terza serata da anonimo']);
    $passata = occurrenceAtLocal($this->city, $this->category, '2026-11-02 21:00', event: ['title' => 'Serata già passata']);
    $bozza = occurrenceAtLocal($this->city, $this->category, '2026-11-22 21:00', event: ['title' => 'Bozza nascosta', 'status' => EventStatus::Draft, 'published_at' => null]);

    // L'anonimo apre il pannello: le date arrivano dal suo browser.
    $this->get('/salvataggi/pannello?ids='.implode(',', [$a->id, $b->id, $b->id, $c->id, $passata->id, $bozza->id, 999999]))
        ->assertOk()->assertHeader('X-Saved-Count', '3')
        ->assertSee('Prima serata da anonimo')->assertSee('Terza serata da anonimo')
        ->assertDontSee('Serata già passata')->assertDontSee('Bozza nascosta');
    $this->get('/i-miei-salvataggi')->assertRedirect(route('login'));

    // Su un altro dispositivo aveva già salvato la prima.
    SavedEvent::query()->create(['user_id' => $this->user->id, 'occurrence_id' => $a->id]);

    // L'accesso riporta dove l'aveva fermato: la pagina dei salvati.
    $this->post('/accedi', ['email' => $this->user->email, 'password' => 'password'])->assertRedirect(route('account.saved'));
    $this->assertAuthenticatedAs($this->user);

    $unione = $this->postJson('/salvataggi/unisci', ['occurrence_ids' => [$a->id, $b->id, $b->id, $c->id, $passata->id, $bozza->id, 999999]])->assertOk();
    expect($unione->json('merged'))->toBe(3)
        ->and($unione->json('ignored'))->toBe(3)
        ->and($unione->json('occurrence_ids'))->toEqualCanonicalizing([$a->id, $b->id, $c->id])
        ->and($unione->json('message'))->toBe(trans_choice('account.save.merged', 3, ['count' => 3]));
    expect(flowsFollowingSavedIds($this->user))->toBe([$a->id, $b->id, $c->id]);

    $this->get('/salvataggi/pannello')->assertOk()->assertHeader('X-Saved-Count', '3');
    $this->get('/i-miei-salvataggi')->assertOk()->assertSee('Seconda serata da anonimo')->assertDontSee('Bozza nascosta');
});

it('unisce dall app i salvataggi del telefono, anche chiedendolo due volte, e li elenca in ordine', function (): void {
    $a = occurrenceAtLocal($this->city, $this->category, '2026-11-25 21:00', event: ['title' => 'Più lontana']);
    $b = occurrenceAtLocal($this->city, $this->category, '2026-11-13 21:00', event: ['title' => 'Più vicina']);
    $passata = occurrenceAtLocal($this->city, $this->category, '2026-11-02 21:00');
    $token = $this->user->createToken('Telefono')->plainTextToken;

    $prima = $this->withToken($token)->postJson('/api/v1/me/saved/merge', ['occurrence_ids' => [$a->id, $b->id, $passata->id]])->assertOk();
    expect($prima->json('data.merged'))->toBe(2)
        ->and($prima->json('data.ignored'))->toBe(1)
        ->and($prima->json('data.message'))->toBe(__('account.api.merged'));

    // Il browser ripete la chiamata (rete instabile): nessun duplicato.
    $this->withToken($token)->postJson('/api/v1/me/saved/merge', ['occurrence_ids' => [$a->id, $b->id]])->assertOk()->assertJsonPath('data.merged', 2);
    expect(SavedEvent::query()->where('user_id', $this->user->id)->count())->toBe(2);

    // L'agenda la ordina il motore, per data.
    expect($this->withToken($token)->getJson('/api/v1/me/saved')->assertOk()->json('data.*.occurrence_id'))->toBe([$b->id, $a->id]);

    $this->withToken($token)->postJson('/api/v1/me/saved/merge', [])->assertUnprocessable();
    $this->withToken($token)->postJson('/api/v1/me/saved/merge', ['occurrence_ids' => range(1, 201)])->assertUnprocessable();
});

it('protegge i salvataggi: ospiti, account da confermare, dati non validi e date di altri', function (): void {
    $data = occurrenceAtLocal($this->city, $this->category, '2026-11-14 21:00');
    $passata = occurrenceAtLocal($this->city, $this->category, '2026-11-02 21:00');

    $this->get('/i-miei-salvataggi')->assertRedirect(route('login'));
    $this->getJson('/salvataggi/stato')->assertUnauthorized();
    $this->post('/salvataggi', ['occurrence_id' => $data->id])->assertRedirect(route('login'));
    $this->postJson('/salvataggi/unisci', ['occurrence_ids' => [$data->id]])->assertUnauthorized();
    $this->getJson('/api/v1/me/saved')->assertUnauthorized();
    $this->postJson('/api/v1/me/saved/merge', ['occurrence_ids' => [$data->id]])->assertUnauthorized();
    expect(SavedEvent::query()->count())->toBe(0);

    // Chi non ha ancora confermato l'indirizzo non salva sul server.
    $nuovo = User::factory()->unverified()->create();
    $this->actingAs($nuovo)->postJson('/salvataggi/unisci', ['occurrence_ids' => [$data->id]])->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
    $this->actingAs($nuovo)->post('/salvataggi', ['occurrence_id' => $data->id])->assertRedirect(route('verification.notice'));
    expect(SavedEvent::query()->count())->toBe(0);

    $this->actingAs($this->user);
    $this->postJson('/salvataggi', [])->assertUnprocessable()->assertJsonValidationErrors('occurrence_id');
    $this->postJson('/salvataggi', ['occurrence_id' => 'abc'])->assertUnprocessable();
    // Una data passata non entra, né dal sito né dall'app.
    $this->postJson('/salvataggi', ['occurrence_id' => $passata->id])->assertOk()->assertJsonPath('message', __('account.save.not_savable'));
    $this->postJson('/api/v1/me/saved', ['occurrence_id' => $passata->id])->assertNotFound()->assertJsonPath('error.message', __('account.api.not_savable'));
    expect(SavedEvent::query()->count())->toBe(0);

    // La data salvata da un'altra persona non si tocca.
    $altra = User::factory()->create();
    SavedEvent::query()->create(['user_id' => $altra->id, 'occurrence_id' => $data->id]);
    $this->deleteJson('/api/v1/me/saved/'.$data->id)->assertNotFound();
    $this->deleteJson('/salvataggi/'.$data->id)->assertOk()->assertJsonPath('removed', false);
    expect(SavedEvent::query()->where('user_id', $altra->id)->count())->toBe(1);
    $this->getJson('/salvataggi/stato')->assertExactJson(['ids' => []]);
});
