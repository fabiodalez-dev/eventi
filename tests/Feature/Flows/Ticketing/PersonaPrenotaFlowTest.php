<?php

declare(strict_types=1);

use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\User;
use App\Notifications\BookingChanged;
use App\Notifications\TicketShared;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/TicketingFlowSupport.php';

/*
 * I flussi di chi prenota dal sito: dalla pagina di prenotazione al biglietto
 * mandato a un amico, passando per la lista d'attesa e l'annullamento.
 */
beforeEach(function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
});

it('prenota due posti dal sito, ritrova la prenotazione nei biglietti e non può prenotare due volte la stessa data', function (): void {
    ['date' => $date] = tktFlowScenario();
    $giulia = User::factory()->create(['name' => 'Giulia Rossi']);

    // La pagina di prenotazione mostra i posti e il limite per account.
    $this->actingAs($giulia)->get(route('tickets.create', $date))->assertOk()
        ->assertSee('Concerto al chiostro')
        ->assertSee(__('ticketing.remaining', ['count' => 10]))
        ->assertSee(__('ticketing.limit', ['count' => 4]))
        ->assertSee(route('tickets.store', $date), false);

    $this->post(route('tickets.store', $date), tktFlowWebPayload([['Giulia', 'Rossi'], ['Marco', 'Bianchi']]))
        ->assertRedirect(route('tickets.index'))
        ->assertSessionHas('status', __('ticketing.mail.confirmed'));

    $booking = Booking::query()->sole();
    expect($booking->user_id)->toBe($giulia->id)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->privacy_accepted_at)->not->toBeNull()
        ->and($booking->booker_data)->toMatchArray(['first_name' => 'Giulia', 'last_name' => 'Rossi'])
        ->and($booking->tickets()->pluck('attendee_name')->all())->toBe(['Giulia Rossi', 'Marco Bianchi'])
        ->and($booking->tickets()->where('status', AdmissionStatus::Valid)->count())->toBe(2)
        ->and(strlen((string) $booking->tickets()->first()->code))->toBe(64);
    Notification::assertSentTo($giulia, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'confirmed' && $n->bookingId === $booking->id);
    expect(Activity::query()->where('description', 'reserved')->where('subject_id', $booking->id)->exists())->toBeTrue();

    // «I miei biglietti» e la scheda della prenotazione mostrano i due nomi e il QR.
    $this->get(route('tickets.index'))->assertOk()
        ->assertSee('Concerto al chiostro')->assertSee('Giulia Rossi')->assertSee('Marco Bianchi')
        ->assertSee(__('ticketing.show_qr'))
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    $this->get(route('tickets.show', $booking))->assertOk()
        ->assertSee(__('ticketing.booking_number', ['id' => $booking->id]))
        ->assertSee(route('tickets.pdf', $booking->tickets()->first()), false);

    // Tornare alla pagina di prenotazione porta alla prenotazione esistente.
    $this->get(route('tickets.create', $date))->assertRedirect(route('tickets.show', $booking));
    // Un secondo invio con un'altra chiave è rifiutato e non consuma posti.
    $this->post(route('tickets.store', $date), tktFlowWebPayload([['Anna', 'Verdi']]))
        ->assertRedirect(route('tickets.create', $date))
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.already_booked')]);
    expect(Booking::query()->count())->toBe(1)->and(AdmissionTicket::query()->count())->toBe(2);

    // Il posto occupato si vede anche nella disponibilità pubblica.
    $this->getJson('/api/v1/occurrences/'.$date->id.'/booking')->assertOk()->assertJsonPath('data.remaining', 8);
});

it('chiede i dati e le risposte richiesti dal gestore e li salva con la prenotazione', function (): void {
    ['date' => $date] = tktFlowScenario([
        'booking_fields' => ['phone' => 'required'],
        'booking_questions' => [['id' => 'qmaglia01', 'label' => 'Taglia della maglietta', 'type' => 'choice', 'options' => ['S', 'M', 'L'], 'required' => true]],
        'booking_instructions' => 'Porta la tessera ARCI.',
    ]);
    $luca = User::factory()->create();

    $this->actingAs($luca)->get(route('tickets.create', $date))->assertOk()
        ->assertSee(__('ticketing.fields.phone'))->assertSee('Taglia della maglietta')->assertSee('Porta la tessera ARCI.');

    // Senza telefono e senza risposta la prenotazione non parte e non occupa posti.
    $this->post(route('tickets.store', $date), tktFlowWebPayload([['Luca', 'Neri']]))
        ->assertRedirect(route('tickets.create', $date))->assertSessionHasErrors(['phone']);
    $this->post(route('tickets.store', $date), tktFlowWebPayload([['Luca', 'Neri']], ['booker' => ['first_name' => 'Luca', 'last_name' => 'Neri', 'phone' => '+39 333 1234567'], 'answers' => ['qmaglia01' => 'XXL']]))
        ->assertSessionHasErrors(['qmaglia01']);
    expect(Booking::query()->count())->toBe(0);

    $this->post(route('tickets.store', $date), tktFlowWebPayload([['Luca', 'Neri']], ['booker' => ['first_name' => 'Luca', 'last_name' => 'Neri', 'phone' => '+39 333 1234567'], 'answers' => ['qmaglia01' => 'M']]))
        ->assertRedirect(route('tickets.index'))->assertSessionHasNoErrors();

    $booking = Booking::query()->sole();
    expect($booking->booker_data['phone'])->toBe('+39 333 1234567')
        ->and($booking->booking_answers)->toBe([['id' => 'qmaglia01', 'question' => 'Taglia della maglietta', 'answer' => 'M']]);
    $this->get(route('tickets.index'))->assertOk()->assertSee(__('ticketing.questions.yours'))->assertSee('Taglia della maglietta');
});

it('scarica il PDF del biglietto e si fa rispedire il riepilogo, entro il tetto di invii', function (): void {
    ['date' => $date] = tktFlowScenario();
    $sara = User::factory()->create();
    $this->actingAs($sara)->post(route('tickets.store', $date), tktFlowWebPayload([['Sara', 'Gialli']]))->assertRedirect();
    $booking = Booking::query()->sole();
    $ticket = $booking->tickets()->sole();

    $pdf = $this->get(route('tickets.pdf', $ticket))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($pdf->headers->get('content-disposition'))->toContain('inCitta-ticket-'.$ticket->id.'.pdf')
        ->and(substr((string) $pdf->getContent(), 0, 5))->toBe('%PDF-');

    Notification::fake();
    $this->post(route('tickets.resend', $booking))
        ->assertRedirect(route('tickets.show', $booking))->assertSessionHas('status', __('ticketing.email_queued'));
    Notification::assertSentToTimes($sara, BookingChanged::class, 1);
    Notification::assertSentTo($sara, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'confirmed');

    // Tre invii all'ora: il quarto si ferma al limite.
    $this->post(route('tickets.resend', $booking))->assertRedirect();
    $this->post(route('tickets.resend', $booking))->assertRedirect();
    $this->post(route('tickets.resend', $booking))->assertTooManyRequests();
    Notification::assertSentToTimes($sara, BookingChanged::class, 3);
});

it('manda un biglietto a un amico per email, e l amico lo apre dal link firmato finché è valido', function (): void {
    ['date' => $date] = tktFlowScenario();
    $giulia = User::factory()->create(['name' => 'Giulia Rossi']);
    $this->actingAs($giulia)->post(route('tickets.store', $date), tktFlowWebPayload([['Giulia', 'Rossi'], ['Marco', 'Bianchi']]))->assertRedirect();
    $booking = Booking::query()->sole();
    $marco = $booking->tickets()->where('first_name', 'Marco')->sole();

    // Senza la spunta di consenso l'email non parte.
    $this->from(route('tickets.index'))->post(route('tickets.share', $marco), ['email' => 'marco@example.test'])
        ->assertRedirect(route('tickets.index'))->assertSessionHasErrors('consent');
    Notification::assertNotSentTo(new AnonymousNotifiable, TicketShared::class);

    $this->from(route('tickets.index'))->post(route('tickets.share', $marco), ['email' => 'marco@example.test', 'consent' => '1'])
        ->assertRedirect(route('tickets.index'))->assertSessionHas('status', __('ticketing.share.sent'));

    $sharedUrl = null;
    Notification::assertSentTo(new AnonymousNotifiable, TicketShared::class, function (TicketShared $n, array $channels, AnonymousNotifiable $to) use ($marco, &$sharedUrl): bool {
        $sharedUrl = $n->url;

        return $to->routes['mail'] === 'marco@example.test' && $n->ticketId === $marco->id && $n->senderName === 'Giulia Rossi';
    });
    // Il registro dice che il biglietto è partito, non a chi.
    expect(Activity::query()->where('description', 'ticket_shared')->where('subject_id', $marco->id)->exists())->toBeTrue()
        ->and(json_encode(Activity::query()->where('description', 'ticket_shared')->get()->toArray()))->not->toContain('marco@example.test');

    // Lo stesso biglietto non riparte per email nello stesso giorno.
    $this->from(route('tickets.index'))->post(route('tickets.share', $marco), ['email' => 'marco@example.test', 'consent' => '1'])
        ->assertSessionHasErrors(['email' => __('ticketing.share.already_sent')]);

    // Marco apre il link senza account: vede il suo nome e il QR, non quello di Giulia.
    $this->app['auth']->forgetGuards();
    $this->get((string) $sharedUrl)->assertOk()
        ->assertSee('Marco Bianchi')->assertDontSee('Giulia Rossi')
        ->assertSee('role="img"', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    // Senza firma il link non apre nulla.
    $this->get(route('tickets.shared', $marco))->assertForbidden();

    // Giulia annulla il biglietto di Marco: il link resta ma il QR sparisce.
    $this->actingAs($giulia)->post(route('tickets.cancel', $booking), ['ticket_id' => $marco->id])->assertRedirect(route('tickets.show', $booking));
    $this->app['auth']->forgetGuards();
    $this->get((string) $sharedUrl)->assertOk()->assertDontSee('role="img"', false)
        ->assertSee(e(__('ticketing.share.page_invalid', ['status' => __('ticketing.statuses.cancelled')])), false);
});

it('annulla prima un biglietto e poi tutta la prenotazione, liberando i posti, ma non dopo la scadenza', function (): void {
    ['date' => $date] = tktFlowScenario();
    $anna = User::factory()->create();
    $this->actingAs($anna)->post(route('tickets.store', $date), tktFlowWebPayload([['Anna', 'Verdi'], ['Bruno', 'Verdi'], ['Carla', 'Verdi']]))->assertRedirect();
    $booking = Booking::query()->sole();
    [$first, $second, $third] = $booking->tickets()->orderBy('id')->get()->all();

    $this->post(route('tickets.cancel', $booking), ['ticket_id' => $first->id])
        ->assertRedirect(route('tickets.show', $booking))->assertSessionHas('status', __('ticketing.mail.cancelled'));
    expect($first->fresh()->status)->toBe(AdmissionStatus::Cancelled)
        ->and($first->fresh()->cancelled_at)->not->toBeNull()
        ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed);
    $this->getJson('/api/v1/occurrences/'.$date->id.'/booking')->assertJsonPath('data.remaining', 8);
    Notification::assertSentTo($anna, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'cancelled');

    // Un biglietto di un'altra prenotazione non si annulla passando da questa.
    $other = User::factory()->create();
    $this->actingAs($other)->post(route('tickets.store', $date), tktFlowWebPayload([['Dario', 'Blu']]))->assertRedirect();
    $foreign = Booking::query()->where('user_id', $other->id)->sole()->tickets()->sole();
    $this->actingAs($anna)->post(route('tickets.cancel', $booking), ['ticket_id' => $foreign->id])->assertNotFound();
    expect($foreign->fresh()->status)->toBe(AdmissionStatus::Valid);

    // Dopo il termine per annullare la persona non può più farlo da sola.
    $date->update(['cancellation_closes_at' => now()->addDay()]);
    $this->travel(2)->days();
    $this->post(route('tickets.cancel', $booking), ['ticket_id' => $second->id])
        ->assertRedirect(route('tickets.show', $booking))
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.cancellation_closed')]);
    expect($second->fresh()->status)->toBe(AdmissionStatus::Valid);

    // Prima del termine, l'annullamento di tutto chiude la prenotazione.
    $this->travel(-2)->days();
    $this->post(route('tickets.cancel', $booking))->assertRedirect(route('tickets.show', $booking));
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->tickets()->where('status', '!=', AdmissionStatus::Cancelled)->count())->toBe(0)
        ->and($third->fresh()->status)->toBe(AdmissionStatus::Cancelled);
    $this->getJson('/api/v1/occurrences/'.$date->id.'/booking')->assertJsonPath('data.remaining', 9);
    // Con la prenotazione annullata si può prenotare di nuovo.
    $this->get(route('tickets.create', $date))->assertOk();
});

it('trova i posti esauriti, entra in lista d attesa e conferma il posto quando un annullamento lo promuove', function (): void {
    ['date' => $date] = tktFlowScenario(['booking_capacity' => 2]);
    $first = User::factory()->create();
    $waiting = User::factory()->create();

    $this->actingAs($first)->post(route('tickets.store', $date), tktFlowWebPayload([['Primo', 'Arrivato'], ['Seconda', 'Arrivata']]))->assertRedirect(route('tickets.index'));
    $this->getJson('/api/v1/occurrences/'.$date->id.'/booking')->assertJsonPath('data.remaining', 0)->assertJsonPath('data.sale_state', 'sold_out');

    // Senza la spunta della lista d'attesa la prenotazione è rifiutata.
    $this->actingAs($waiting)->post(route('tickets.store', $date), tktFlowWebPayload([['Terzo', 'Incoda']]))
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.full')]);
    expect(Booking::query()->where('user_id', $waiting->id)->exists())->toBeFalse();

    $this->post(route('tickets.store', $date), tktFlowWebPayload([['Terzo', 'Incoda']], ['waitlist' => '1']))
        ->assertRedirect(route('tickets.index'))->assertSessionHas('status', __('ticketing.mail.waitlisted'));
    $queued = Booking::query()->where('user_id', $waiting->id)->sole();
    expect($queued->status)->toBe(BookingStatus::Waitlisted)
        ->and($queued->tickets()->sole()->status)->toBe(AdmissionStatus::Waitlisted);
    Notification::assertSentTo($waiting, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'waitlisted');
    $this->get(route('tickets.index'))->assertOk()
        ->assertSee(__('ticketing.waiting_hint'))
        ->assertSee(__('ticketing.queue_position', ['position' => 1]))
        ->assertDontSee(__('ticketing.show_qr'));

    // Chi aveva il posto annulla un biglietto: il primo in coda è promosso.
    $firstBooking = Booking::query()->where('user_id', $first->id)->sole();
    $this->actingAs($first)->post(route('tickets.cancel', $firstBooking), ['ticket_id' => $firstBooking->tickets()->orderBy('id')->first()->id])->assertRedirect();
    $queued->refresh();
    expect($queued->status)->toBe(BookingStatus::Confirmed)
        ->and($queued->promotion_expires_at)->not->toBeNull()
        ->and($queued->promotion_expires_at->equalTo(now()->addHours(12)))->toBeTrue()
        ->and($queued->tickets()->sole()->status)->toBe(AdmissionStatus::Valid);
    Notification::assertSentTo($waiting, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'promoted');

    // La persona promossa vede il pulsante e conferma il posto.
    $this->actingAs($waiting)->get(route('tickets.index'))->assertOk()->assertSee(__('ticketing.promotion_confirm'));
    $this->from(route('tickets.index'))->post(route('tickets.confirm', $queued))
        ->assertRedirect(route('tickets.index'))->assertSessionHas('status', __('ticketing.promotion_confirmed'));
    expect($queued->fresh()->promotion_expires_at)->toBeNull()
        ->and(Activity::query()->where('description', 'promotion_confirmed')->where('subject_id', $queued->id)->exists())->toBeTrue();
    $this->get(route('tickets.index'))->assertDontSee(__('ticketing.promotion_confirm'));

    // Confermare di nuovo non ha senso: non c'è più una promozione in sospeso.
    $this->post(route('tickets.confirm', $queued))->assertSessionHasErrors(['ticketing' => __('ticketing.errors.promotion_missing')]);
});

it('perde il posto promosso se non lo conferma in tempo, e la coda va avanti con il lavoro programmato', function (): void {
    ['date' => $date] = tktFlowScenario(['booking_capacity' => 1]);
    [$holder, $second, $third] = User::factory()->count(3)->create()->all();

    $this->actingAs($holder)->post(route('tickets.store', $date), tktFlowWebPayload([['Ha', 'Ilposto']]))->assertRedirect();
    $this->actingAs($second)->post(route('tickets.store', $date), tktFlowWebPayload([['Secondo', 'Incoda']], ['waitlist' => '1']))->assertRedirect();
    $this->actingAs($third)->post(route('tickets.store', $date), tktFlowWebPayload([['Terzo', 'Incoda']], ['waitlist' => '1']))->assertRedirect();
    $secondBooking = Booking::query()->where('user_id', $second->id)->sole();
    $thirdBooking = Booking::query()->where('user_id', $third->id)->sole();
    $this->actingAs($third)->get(route('tickets.index'))->assertSee(__('ticketing.queue_position', ['position' => 2]));

    $holderBooking = Booking::query()->where('user_id', $holder->id)->sole();
    $this->actingAs($holder)->post(route('tickets.cancel', $holderBooking))->assertRedirect();
    expect($secondBooking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($thirdBooking->fresh()->status)->toBe(BookingStatus::Waitlisted);

    // Passano tredici ore senza conferma: il lavoro di ogni minuto libera il posto.
    $this->travel(13)->hours();
    $this->artisan('ticketing:promote')->assertSuccessful();

    expect($secondBooking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($secondBooking->fresh()->cancellation_reason)->toBe(__('ticketing.errors.promotion_expired'))
        ->and($thirdBooking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($thirdBooking->fresh()->promotion_expires_at)->not->toBeNull();
    Notification::assertSentTo($second, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'promotion_expired');
    Notification::assertSentTo($third, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'promoted');

    // Chi è arrivato tardi non può più confermare il posto perso.
    $this->actingAs($second)->post(route('tickets.confirm', $secondBooking))
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.promotion_missing')]);
    $this->actingAs($third)->post(route('tickets.confirm', $thirdBooking))->assertSessionHasNoErrors();
    expect($thirdBooking->fresh()->promotion_expires_at)->toBeNull();
});

it('blocca la prenotazione a chi non ha confermato l email, e la lascia fare appena conferma', function (): void {
    ['date' => $date] = tktFlowScenario();
    $nuovo = User::factory()->unverified()->create();

    $this->actingAs($nuovo)->get(route('tickets.create', $date))->assertRedirect(route('verification.notice'));
    $this->post(route('tickets.store', $date), tktFlowWebPayload([['Nuovo', 'Utente']]))->assertRedirect(route('verification.notice'));
    // La pagina dei biglietti resta leggibile, ma è vuota.
    $this->get(route('tickets.index'))->assertOk()->assertSee(__('ticketing.empty'));
    expect(Booking::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $nuovo->forceFill(['email_verified_at' => now()])->save();

    $this->actingAs($nuovo->fresh())->get(route('tickets.create', $date))->assertOk();
    $this->post(route('tickets.store', $date), tktFlowWebPayload([['Nuovo', 'Utente']]))->assertRedirect(route('tickets.index'));
    expect(Booking::query()->where('user_id', $nuovo->id)->sole()->status)->toBe(BookingStatus::Confirmed);
});

it('non lascia a un estraneo vedere, scaricare, spedire, confermare o annullare la prenotazione di un altro', function (): void {
    ['date' => $date] = tktFlowScenario();
    $owner = User::factory()->create();
    $this->actingAs($owner)->post(route('tickets.store', $date), tktFlowWebPayload([['Elena', 'Viola']]))->assertRedirect();
    $booking = Booking::query()->sole();
    $ticket = $booking->tickets()->sole();
    Notification::fake();

    $stranger = User::factory()->create();
    $this->actingAs($stranger);
    $this->get(route('tickets.show', $booking))->assertForbidden();
    $this->get(route('tickets.pdf', $ticket))->assertForbidden();
    $this->post(route('tickets.resend', $booking))->assertForbidden();
    $this->post(route('tickets.share', $ticket), ['email' => 'x@example.test', 'consent' => '1'])->assertForbidden();
    $this->post(route('tickets.confirm', $booking))->assertForbidden();
    $this->post(route('tickets.cancel', $booking))->assertForbidden();
    // La sua pagina dei biglietti non mostra nulla dell'altra persona.
    $this->get(route('tickets.index'))->assertOk()->assertDontSee('Elena Viola');

    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed)->and($ticket->fresh()->status)->toBe(AdmissionStatus::Valid);
    Notification::assertNothingSent();

    // Chi non ha fatto l'accesso viene mandato al login.
    $this->app['auth']->forgetGuards();
    $this->get(route('tickets.index'))->assertRedirect();
    $this->get(route('tickets.create', $date))->assertRedirect();
});
