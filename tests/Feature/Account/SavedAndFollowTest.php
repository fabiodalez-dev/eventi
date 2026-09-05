<?php

declare(strict_types=1);

use App\Actions\GenerateOccurrencesAction;
use App\Enums\EventStatus;
use App\Enums\FollowableType;
use App\Enums\NotificationStatus;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\Follow;
use App\Models\SavedEvent;
use App\Models\ScheduledNotification;
use App\Models\Tag;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Che cosa si salva e che cosa si segue (§15.3).
 *
 * **Si salva l'occorrenza, non l'evento**, e «segui questo evento» è un'altra
 * cosa ancora: significa «salvami ogni data nuova». Le tre regole di §15.3
 * sono qui, una per gruppo di prove.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();

    freezeLocal($this->city, '2026-09-10 18:00');

    $this->user = User::factory()->create();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('salva una data futura e la mostra fra i salvataggi', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    $this->actingAs($this->user)
        ->post('/salvataggi', ['occurrence_id' => $occurrence->getKey()])
        ->assertRedirect();

    expect($this->user->savedEvents()->count())->toBe(1);

    /* E la pagina dei salvataggi la mostra con il cuore già acceso. */
    $this->actingAs($this->user)
        ->get('/i-miei-salvataggi')
        ->assertOk()
        ->assertSee($occurrence->event->title)
        ->assertSee('aria-pressed="true"', false);
});

it('mostra i salvati anche come calendario con il collegamento a Google Calendar', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    SavedEvent::query()->create([
        'user_id' => $this->user->getKey(),
        'occurrence_id' => $occurrence->getKey(),
    ]);

    $this->actingAs($this->user)
        ->get('/i-miei-salvataggi?vista=calendario')
        ->assertOk()
        ->assertSee(__('account.saved.calendar_view'))
        ->assertSee('data-saved-calendar', false)
        ->assertSee('settembre 2026')
        ->assertSee('mese=2026-10', false)
        ->assertSee(__('common.actions.google_calendar'))
        ->assertSee('calendar.google.com', false);
});

it('il calendario dei salvati naviga i mesi e include anche le date passate', function (): void {
    $past = occurrenceAt($this->city, $this->category, '2026-08-20 19:00:00', event: ['title' => 'Ricordo di agosto']);
    $future = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00', event: ['title' => 'Appuntamento di settembre']);

    SavedEvent::query()->create(['user_id' => $this->user->getKey(), 'occurrence_id' => $past->getKey()]);
    SavedEvent::query()->create(['user_id' => $this->user->getKey(), 'occurrence_id' => $future->getKey()]);

    $this->actingAs($this->user)
        ->get('/i-miei-salvataggi?vista=calendario&mese=2026-08')
        ->assertOk()
        ->assertSee('Ricordo di agosto')
        ->assertDontSee('Appuntamento di settembre')
        ->assertSee('mese=2026-09', false);
});

it('non salva una data già passata né una che non è pubblica', function (): void {
    $passata = occurrenceAt($this->city, $this->category, '2026-08-20 21:00:00');

    $bozza = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00', event: [
        'status' => EventStatus::Draft,
        'published_at' => null,
    ]);

    $this->actingAs($this->user)->postJson('/salvataggi', ['occurrence_id' => $passata->getKey()])->assertOk();
    $this->actingAs($this->user)->postJson('/salvataggi', ['occurrence_id' => $bozza->getKey()])->assertOk();

    /*
     * Nessuna delle due entra: «passata» e «non pubblica» le decide il motore,
     * che è già ristretto agli eventi pubblicati della città.
     */
    expect($this->user->savedEvents()->count())->toBe(0);

    $this->withToken($this->user->createToken('t')->plainTextToken)
        ->postJson('/api/v1/me/saved', ['occurrence_id' => $passata->getKey()])
        ->assertNotFound()
        ->assertJsonPath('error.message', __('account.api.not_savable'));
});

it('togliere una data dai salvati annulla i promemoria ancora in attesa', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    SavedEvent::query()->create(['user_id' => $this->user->getKey(), 'occurrence_id' => $occurrence->getKey()]);

    $pending = ScheduledNotification::factory()->create([
        'user_id' => $this->user->getKey(),
        'notifiable_type' => 'event_occurrence',
        'notifiable_id' => $occurrence->getKey(),
        'status' => NotificationStatus::Pending,
        'dedupe_key' => 'reminder_3h:user_'.$this->user->getKey().':occ_'.$occurrence->getKey(),
    ]);

    $inviato = ScheduledNotification::factory()->create([
        'user_id' => $this->user->getKey(),
        'notifiable_type' => 'event_occurrence',
        'notifiable_id' => $occurrence->getKey(),
        'status' => NotificationStatus::Sent,
        'dedupe_key' => 'reminder_24h:user_'.$this->user->getKey().':occ_'.$occurrence->getKey(),
    ]);

    $this->actingAs($this->user)
        ->delete('/salvataggi/'.$occurrence->getKey())
        ->assertRedirect();

    expect($this->user->savedEvents()->count())->toBe(0)
        ->and($pending->fresh()?->status)->toBe(NotificationStatus::Cancelled)
        /* Ciò che è già partito resta cronaca, non programma. */
        ->and($inviato->fresh()?->status)->toBe(NotificationStatus::Sent);
});

it('non lascia togliere dai salvati la data di un altro', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $altro = User::factory()->create();

    SavedEvent::query()->create(['user_id' => $altro->getKey(), 'occurrence_id' => $occurrence->getKey()]);

    $this->actingAs($this->user)
        ->deleteJson('/salvataggi/'.$occurrence->getKey())
        ->assertOk();

    /* La riga dell'altro non si tocca: la cancellazione parte dalla relazione
       dell'utente, non dall'identificativo dell'occorrenza. */
    expect($altro->savedEvents()->count())->toBe(1);

    $this->withToken($this->user->createToken('t')->plainTextToken)
        ->deleteJson('/api/v1/me/saved/'.$occurrence->getKey())
        ->assertNotFound();

    expect($altro->savedEvents()->count())->toBe(1);
});

it('salva tutte le date di un evento con più serate', function (): void {
    $primo = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    $secondo = EventOccurrence::factory()->create([
        'event_id' => $primo->event_id,
        'starts_at' => CarbonImmutable::parse('2026-09-19 19:00:00', 'UTC'),
        'ends_at' => null,
        'doors_at' => null,
    ]);

    $this->actingAs($this->user)
        ->post('/salvataggi', ['occurrence_ids' => [$primo->getKey(), $secondo->getKey()]])
        ->assertRedirect();

    expect($this->user->savedEvents()->count())->toBe(2);
});

it('mostra il selettore delle date quando le serate future sono più di una', function (): void {
    $primo = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    EventOccurrence::factory()->create([
        'event_id' => $primo->event_id,
        'starts_at' => CarbonImmutable::parse('2026-09-19 19:00:00', 'UTC'),
        'ends_at' => null,
        'doors_at' => null,
    ]);

    $this->actingAs($this->user)
        ->get(route('events.show', $primo->event))
        ->assertOk()
        ->assertSee(__('account.save.choose_dates'))
        ->assertSee(__('account.save.all_dates'));
});

it('non chiede niente quando la serata futura è una sola', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    $this->actingAs($this->user)
        ->get(route('events.show', $occurrence->event))
        ->assertOk()
        ->assertSee(__('account.save.action'))
        ->assertDontSee(__('account.save.choose_dates'));
});

it('seguendo una serie salva le date già in programma e ogni data nuova', function (): void {
    $primo = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    $recurrence = EventRecurrence::factory()->create([
        'event_id' => $primo->event_id,
        'rrule' => 'FREQ=WEEKLY;BYDAY=SA;COUNT=3',
    ]);

    $this->actingAs($this->user)
        ->post('/segui', ['type' => FollowableType::Event->value, 'id' => $primo->event_id])
        ->assertRedirect();

    /* Le date già in programma entrano subito: chi preme il pulsante oggi non
       deve restare con l'agenda vuota fino alla prossima generazione. */
    expect($this->user->savedEvents()->count())->toBe(1);

    /* E ogni data generata dopo entra da sola (§15.3). */
    app(GenerateOccurrencesAction::class)($recurrence);

    $generate = EventOccurrence::query()->where('event_id', $primo->event_id)->count();

    expect($generate)->toBeGreaterThan(1)
        ->and($this->user->savedEvents()->count())->toBe($generate);
});

it('smettere di seguire una serie non toglie dall agenda le date già salvate', function (): void {
    $primo = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    $this->actingAs($this->user)->post('/segui', ['type' => 'event', 'id' => $primo->event_id]);

    expect($this->user->savedEvents()->count())->toBe(1);

    $this->actingAs($this->user)->delete('/segui/event/'.$primo->event_id)->assertRedirect();

    expect(Follow::query()->where('user_id', $this->user->getKey())->count())->toBe(0)
        /* Le serate a cui si voleva andare restano: toglierle sarebbe una
           perdita silenziosa di dati. */
        ->and($this->user->savedEvents()->count())->toBe(1);
});

it('segue locali, tag e categorie e rifiuta un soggetto che non esiste', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $venue = $occurrence->event->venue;
    $tag = Tag::factory()->create();

    $this->actingAs($this->user)->post('/segui', ['type' => 'venue', 'id' => $venue?->getKey()])->assertRedirect();
    $this->actingAs($this->user)->post('/segui', ['type' => 'category', 'id' => $this->category->getKey()])->assertRedirect();
    $this->actingAs($this->user)->post('/segui', ['type' => 'tag', 'id' => $tag->getKey()])->assertRedirect();

    expect($this->user->follows()->count())->toBe(3)
        ->and($this->user->followsAnything())->toBeTrue();

    /* `follows` è polimorfa e non ha una chiave esterna: senza controllo si
       potrebbe seguire un locale mai esistito. */
    $this->actingAs($this->user)->post('/segui', ['type' => 'venue', 'id' => 999999])->assertNotFound();

    /* Seguire due volte non crea una seconda riga. */
    $this->actingAs($this->user)->post('/segui', ['type' => 'venue', 'id' => $venue?->getKey()])->assertRedirect();

    expect($this->user->follows()->count())->toBe(3);
});

it('mette il pulsante segui sulla scheda del locale e lo mostra già premuto a chi lo segue', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');
    $venue = $occurrence->event->venue;

    $this->actingAs($this->user)
        ->get(route('venues.show', $venue))
        ->assertOk()
        ->assertSee(__('account.follow.venue'));

    Follow::query()->create([
        'user_id' => $this->user->getKey(),
        'followable_type' => FollowableType::Venue->value,
        'followable_id' => $venue?->getKey(),
    ]);

    $this->actingAs($this->user)
        ->get(route('venues.show', $venue))
        ->assertOk()
        ->assertSee(__('account.follow.following'));
});

it('non lascia salvare né seguire a chi non è collegato, ma la pagina resta pubblica', function (): void {
    $occurrence = occurrenceAt($this->city, $this->category, '2026-09-12 19:00:00');

    $this->get(route('events.show', $occurrence->event))->assertOk();

    $this->post('/salvataggi', ['occurrence_id' => $occurrence->getKey()])->assertRedirect(route('login'));
    $this->post('/segui', ['type' => 'venue', 'id' => 1])->assertRedirect(route('login'));
});
