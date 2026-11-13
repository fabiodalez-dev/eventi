<?php

declare(strict_types=1);

use App\Enums\EventCommentStatus;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventComment;
use App\Models\EventCommentReaction;
use App\Models\ScheduledNotification;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
 * Flussi reali dei commenti a un evento: chi scrive, chi reagisce, chi
 * cancella, chi arriva da ospite e viene riportato alla conversazione, e la
 * stessa storia raccontata dall'app attraverso l'API.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
    Mail::fake();
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-11-12 12:00');
    $this->occurrence = occurrenceAtLocal($this->city, $this->category, '2026-11-20 21:00', '2026-11-20 23:30', event: ['title' => 'Concerto al Portello']);
    $this->event = $this->occurrence->event;
    $this->venue = $this->event->venue;
    $this->eventUrl = route('events.show', ['slug' => $this->event->slug]);
    $this->storeUrl = route('events.comments.store', ['slug' => $this->event->slug]);
    $this->apiUrl = '/api/v1/events/'.$this->event->slug.'/comments';
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function intFlowPerson(string $first, string $last): User
{
    return User::factory()->create(['first_name' => $first, 'last_name' => $last, 'name' => $first.' '.$last]);
}

it('una persona verificata commenta dalla scheda, il locale viene avvisato e un\'altra persona reagisce, cambia e ritira la reazione', function (): void {
    $owner = User::factory()->create();
    $this->venue->members()->attach($owner, ['role' => 'owner']);
    $anna = intFlowPerson('Anna', 'Bianchi');
    $marco = intFlowPerson('Marco', 'Verdi');

    // Anna apre la scheda: trova il modulo dei commenti, non ancora commenti.
    $this->actingAs($anna)->get($this->eventUrl)->assertOk()
        ->assertSee($this->storeUrl, false)
        ->assertSee(__('comments.empty'));

    $risposta = $this->post($this->storeUrl, ['body' => '  Si riesce a parcheggiare vicino al locale?  ']);
    $commento = EventComment::query()->sole();
    $risposta->assertRedirect($commento->permalink())->assertSessionHas('status', __('comments.submitted'));

    expect($commento->body)->toBe('Si riesce a parcheggiare vicino al locale?')
        ->and($commento->user_id)->toBe($anna->id)
        ->and($commento->status)->toBe(EventCommentStatus::Published)
        ->and($commento->parent_id)->toBeNull();

    // Lo staff del locale riceve l'avviso del nuovo commento; l'autrice no.
    expect(ScheduledNotification::query()->where('user_id', $owner->id)->where('type', NotificationType::EventNewComment->value)->count())->toBe(1)
        ->and(ScheduledNotification::query()->where('user_id', $anna->id)->exists())->toBeFalse();

    // Il permalink porta al commento, firmato col solo nome proprio (visto da un ospite).
    $this->app['auth']->forgetGuards();
    $this->get($commento->permalink())->assertOk()
        ->assertSee('Si riesce a parcheggiare vicino al locale?')
        ->assertSee('id="commento-'.$commento->id.'"', false)
        ->assertDontSee('Bianchi');

    // Marco reagisce col form classico: torna al commento, Anna è avvisata.
    $reactUrl = route('events.comments.react', ['slug' => $this->event->slug, 'comment' => $commento->id]);
    $this->actingAs($marco)->post($reactUrl, ['type' => 'like'])->assertRedirect($commento->permalink());
    expect(EventCommentReaction::query()->where('event_comment_id', $commento->id)->sole()->type->value)->toBe('like');
    $avviso = ScheduledNotification::query()->where('user_id', $anna->id)->where('type', NotificationType::CommentReaction->value)->sole();
    expect($avviso->payload['actor_id'])->toBe($marco->id)->and($avviso->payload['reaction'])->toBe('like');

    // Con JavaScript cambia reazione: una riga sola, nessun nuovo avviso.
    $this->postJson($reactUrl, ['type' => 'love'])->assertOk()
        ->assertJson(['stato' => 'cambiata', 'tipo' => 'love', 'conteggio' => 1]);
    expect(EventCommentReaction::query()->where('event_comment_id', $commento->id)->sole()->type->value)->toBe('love');

    // Premere di nuovo la stessa la ritira.
    $this->postJson($reactUrl, ['type' => 'love'])->assertOk()
        ->assertJson(['stato' => 'ritirata', 'tipo' => null, 'conteggio' => 0]);
    expect(EventCommentReaction::query()->count())->toBe(0)
        ->and(ScheduledNotification::query()->where('type', NotificationType::CommentReaction->value)->count())->toBe(1);

    // Una reazione inventata non passa.
    $this->post($reactUrl, ['type' => 'dislike'])->assertSessionHasErrors('type');
});

it('chi risponde avvisa l\'autore; solo l\'autore e l\'amministratore cancellano, il moderatore e gli altri no', function (): void {
    $anna = intFlowPerson('Anna', 'Bianchi');
    $marco = intFlowPerson('Marco', 'Verdi');
    $moderatore = User::factory()->create();
    $moderatore->assignRole(UserRole::Moderator->value);
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);

    $this->actingAs($anna)->post($this->storeUrl, ['body' => 'A che ora aprono le porte?'])->assertRedirect();
    $radice = EventComment::query()->sole();

    $this->actingAs($marco)->post($this->storeUrl, ['body' => 'Di solito mezz\'ora prima.', 'parent_id' => $radice->id]);
    $risposta = EventComment::query()->where('user_id', $marco->id)->sole();
    expect($risposta->parent_id)->toBe($radice->id)->and($risposta->reply_to_id)->toBe($radice->id);
    expect(ScheduledNotification::query()->where('user_id', $anna->id)->where('type', NotificationType::CommentReply->value)->count())->toBe(1);

    // La scheda mostra la conversazione intera.
    $this->get($this->eventUrl)->assertOk()->assertSee('A che ora aprono le porte?')->assertSee('Di solito mezz&#039;ora prima.', false);

    $destroyRadice = route('events.comments.destroy', ['slug' => $this->event->slug, 'comment' => $radice->id]);
    $destroyRisposta = route('events.comments.destroy', ['slug' => $this->event->slug, 'comment' => $risposta->id]);

    // Marco non cancella il commento di Anna, e nemmeno il moderatore: lui nasconde.
    $this->actingAs($marco)->delete($destroyRadice)->assertForbidden();
    $this->actingAs($moderatore)->delete($destroyRadice)->assertForbidden();
    expect(EventComment::query()->whereKey($radice->id)->exists())->toBeTrue();

    // L'amministratore cancella la risposta di Marco.
    $this->actingAs($admin)->delete($destroyRisposta)
        ->assertRedirect($this->eventUrl.'#commenti')->assertSessionHas('status', __('comments.deleted'));
    expect(EventComment::query()->whereKey($risposta->id)->exists())->toBeFalse();

    // Anna cancella il proprio commento e torna alla scheda, senza più il testo.
    $this->actingAs($anna)->delete($destroyRadice)->assertRedirect($this->eventUrl.'#commenti');
    expect(EventComment::query()->count())->toBe(0);
    $this->get($this->eventUrl)->assertOk()->assertDontSee('A che ora aprono le porte?')->assertSee(__('comments.empty'));
});

it('l\'ospite che vuole commentare viene mandato all\'accesso e, entrato, torna alla conversazione dell\'evento', function (): void {
    $giulia = User::factory()->create(['email' => 'giulia@example.test']);
    $destinazione = $this->eventUrl.'#commenti';

    // Da ospite la scheda offre l'accesso con il ritorno ai commenti, non il modulo.
    $this->get($this->eventUrl)->assertOk()
        ->assertSee(route('login', ['intended' => $destinazione]), false)
        ->assertDontSee('name="body"', false);

    // Un invio diretto senza account finisce all'accesso e non scrive nulla.
    $this->post($this->storeUrl, ['body' => 'Commento da ospite'])->assertRedirect(route('login'));
    expect(EventComment::query()->count())->toBe(0);

    // Segue il collegamento della scheda, entra e viene riportata all'evento.
    $this->get(route('login', ['intended' => $destinazione]))->assertOk();
    $this->post('/accedi', ['email' => 'giulia@example.test', 'password' => 'password'])->assertRedirect($destinazione);
    $this->assertAuthenticatedAs($giulia);

    // Ora il modulo c'è, e il commento parte.
    $this->get($this->eventUrl)->assertOk()->assertSee('name="body"', false);
    $this->post($this->storeUrl, ['body' => 'Finalmente sono entrata, ci vediamo lì!'])->assertRedirect();
    expect(EventComment::query()->sole()->user_id)->toBe($giulia->id);
});

it('l\'app legge, scrive, risponde, reagisce e cancella i commenti attraverso l\'API', function (): void {
    $anna = intFlowPerson('Anna', 'Bianchi');
    $marco = intFlowPerson('Marco', 'Verdi');

    // Ospite: legge l'elenco vuoto e non può scrivere.
    $this->getJson($this->apiUrl)->assertOk()
        ->assertJsonPath('data.total', 0)->assertJsonPath('data.can_comment', false);
    $this->postJson($this->apiUrl, ['body' => 'Scrivo da ospite'])->assertUnauthorized();

    Sanctum::actingAs($anna);
    $this->getJson($this->apiUrl)->assertJsonPath('data.can_comment', true);
    $id = $this->postJson($this->apiUrl, ['body' => 'C\'è un guardaroba?'])->assertCreated()
        ->assertJsonPath('data.message', __('comments.submitted'))->json('data.id');

    Sanctum::actingAs($marco);
    $replyId = $this->postJson($this->apiUrl, ['body' => 'Sì, all\'ingresso.', 'parent_id' => $id])->assertCreated()->json('data.id');
    $this->postJson($this->apiUrl.'/'.$id.'/reaction', ['type' => 'useful'])->assertOk()->assertJsonPath('data.count', 1);

    $elenco = $this->getJson($this->apiUrl)->assertOk();
    $elenco->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.comments.0.id', $id)
        ->assertJsonPath('data.comments.0.author', 'Anna')
        ->assertJsonPath('data.comments.0.reactions_count', 1)
        ->assertJsonPath('data.comments.0.my_reaction', 'useful')
        ->assertJsonPath('data.comments.0.can_delete', false)
        ->assertJsonPath('data.comments.0.replies_count', 1)
        ->assertJsonPath('data.comments.0.replies.0.id', $replyId)
        ->assertJsonPath('data.comments.0.replies.0.can_delete', true);
    expect($elenco->headers->get('Cache-Control'))->toContain('no-store');

    // Marco non cancella il commento di Anna.
    $this->deleteJson($this->apiUrl.'/'.$id)->assertForbidden();

    // Anna sì: con la radice se ne vanno risposte e reazioni.
    Sanctum::actingAs($anna);
    $this->getJson($this->apiUrl)->assertJsonPath('data.comments.0.can_delete', true);
    $this->deleteJson($this->apiUrl.'/'.$id)->assertOk()->assertJsonPath('data.message', __('comments.deleted'));
    expect(EventComment::query()->count())->toBe(0)->and(EventCommentReaction::query()->count())->toBe(0);
    $this->getJson($this->apiUrl)->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.comments');
});

it('chi non ha confermato l\'email viene mandato a confermarla e commenta solo dopo', function (): void {
    $luca = User::factory()->unverified()->create();
    $altro = intFlowPerson('Sara', 'Neri');
    $this->actingAs($altro)->post($this->storeUrl, ['body' => 'Commento già presente'])->assertRedirect();
    $esistente = EventComment::query()->sole();

    // Sulla scheda niente modulo, ma l'invito a confermare.
    $this->actingAs($luca)->get($this->eventUrl)->assertOk()
        ->assertSee(__('comments.verify_required'))->assertDontSee('name="body"', false);

    // Sito: il middleware `verified` porta alla conferma; API: 403.
    $this->post($this->storeUrl, ['body' => 'Provo lo stesso'])->assertRedirect(route('verification.notice'));
    $this->post(route('events.comments.react', ['slug' => $this->event->slug, 'comment' => $esistente->id]), ['type' => 'like'])
        ->assertRedirect(route('verification.notice'));
    Sanctum::actingAs($luca);
    $this->postJson($this->apiUrl, ['body' => 'Provo dall\'app'])->assertForbidden();
    $this->getJson($this->apiUrl)->assertJsonPath('data.can_comment', false);
    expect(EventComment::query()->where('user_id', $luca->id)->exists())->toBeFalse()
        ->and(EventCommentReaction::query()->exists())->toBeFalse();

    // Confermato l'indirizzo, lo stesso commento passa.
    $luca->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($luca->fresh(), 'web')->post($this->storeUrl, ['body' => 'Ora che ho confermato, eccomi'])->assertRedirect();
    expect(EventComment::query()->where('user_id', $luca->id)->count())->toBe(1);
});

it('rifiuta testi non validi, risposte a commenti di altri eventi ed eventi non pubblicati', function (): void {
    $anna = intFlowPerson('Anna', 'Bianchi');
    $altroEvento = occurrenceAtLocal($this->city, $this->category, '2026-11-21 21:00', event: ['title' => 'Altra serata'])->event;
    $this->actingAs($anna)->post(route('events.comments.store', ['slug' => $altroEvento->slug]), ['body' => 'Commento sull\'altra serata'])->assertRedirect();
    $estraneo = EventComment::query()->sole();

    // Testo troppo corto: si torna ai commenti con l'errore, nulla salvato.
    $this->from($this->eventUrl)->post($this->storeUrl, ['body' => 'no'])
        ->assertRedirect($this->eventUrl.'#commenti')->assertSessionHasErrors('body');
    $this->post($this->storeUrl, ['body' => str_repeat('a', 2001)])->assertSessionHasErrors('body');

    // Un dato personale nel testo viene fermato prima di essere pubblicato.
    $this->post($this->storeUrl, ['body' => 'Scrivetemi a anna.bianchi@example.test per i biglietti'])->assertSessionHasErrors();

    // Una risposta a un commento di un altro evento non si attacca qui.
    $this->post($this->storeUrl, ['body' => 'Risposta nel posto sbagliato', 'parent_id' => $estraneo->id])->assertNotFound();
    // Né reazioni o cancellazioni passando dallo slug sbagliato.
    $this->post(route('events.comments.react', ['slug' => $this->event->slug, 'comment' => $estraneo->id]), ['type' => 'like'])->assertNotFound();
    $this->delete(route('events.comments.destroy', ['slug' => $this->event->slug, 'comment' => $estraneo->id]))->assertNotFound();

    // Un evento in bozza non ha conversazione.
    $bozza = Event::factory()->for($this->city)->create(['status' => 'draft', 'category_id' => $this->category->id]);
    $this->post(route('events.comments.store', ['slug' => $bozza->slug]), ['body' => 'Commento su una bozza'])->assertNotFound();
    $this->getJson('/api/v1/events/'.$bozza->slug.'/comments')->assertNotFound();

    expect(EventComment::query()->count())->toBe(1)
        ->and(EventComment::query()->where('event_id', $this->event->id)->exists())->toBeFalse();
});
