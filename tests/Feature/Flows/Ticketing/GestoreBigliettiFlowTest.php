<?php

declare(strict_types=1);

use App\Enums\AdmissionStatus;
use App\Enums\BookingStatus;
use App\Models\AdmissionTicket;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingChanged;
use App\Notifications\BookingMessageSent;
use App\Notifications\GuestBookingConfirmation;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/TicketingFlowSupport.php';

/*
 * I flussi di chi gestisce le prenotazioni di una data: il proprietario del
 * locale, l'organizzatore, il personale alla porta. Ogni passo passa dalle
 * pagine e dai moduli di `/gestione-biglietti`.
 */
beforeEach(function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
});

it('mostra a proprietario, organizzatore e personale le date che gestiscono, e a nessun altro', function (): void {
    ['date' => $date, 'owner' => $owner, 'venue' => $venue] = tktFlowScenario();
    // Una seconda serata, in un altro locale, organizzata da un collettivo.
    $organizerUser = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Aurora', 'city_id' => $date->event->city_id, 'owner_id' => $organizerUser->id, 'is_active' => true]);
    $otherVenue = Venue::factory()->approved()->create(['city_id' => $date->event->city_id, 'ticketing_enabled' => true]);
    $festival = occurrenceAt($date->event->city, $date->event->category, '2026-10-20 18:00:00', '2026-10-20 22:00:00', ['booking_enabled' => true], ['title' => 'Festa del collettivo', 'organizer_id' => $organizer->id], $otherVenue);
    $past = occurrenceAt($date->event->city, $date->event->category, '2026-10-01 18:00:00', '2026-10-01 22:00:00', [], ['title' => 'Serata di settembre passata'], $venue);

    $this->actingAs($owner)->get(route('ticketing.manage.index'))->assertOk()
        ->assertSee('Concerto al chiostro')->assertSee(route('ticketing.manage.show', $date), false)
        ->assertDontSee('Festa del collettivo')->assertDontSee('Serata di settembre passata');
    $this->get(route('ticketing.manage.index', ['period' => 'past']))->assertOk()
        ->assertSee('Serata di settembre passata')->assertDontSee('Concerto al chiostro');
    $this->get(route('ticketing.manage.index', ['q' => 'chiostro']))->assertOk()->assertSee('Concerto al chiostro');
    $this->get(route('ticketing.manage.index', ['q' => 'inesistente']))->assertOk()->assertDontSee('Concerto al chiostro');
    $this->get(route('ticketing.manage.show', $date))->assertOk()->assertSee('Concerto al chiostro')->assertSee('data-ticket-statistics', false);

    // L'organizzatore gestisce la sua serata anche se il locale non è suo.
    $this->actingAs($organizerUser)->get(route('ticketing.manage.index'))->assertOk()
        ->assertSee('Festa del collettivo')->assertSee(route('ticketing.manage.show', $festival), false)->assertDontSee('Concerto al chiostro');
    $this->get(route('ticketing.manage.show', $festival))->assertOk();
    $this->get(route('ticketing.manage.show', $date))->assertForbidden();

    // Il personale della porta vede la data, ma con il collegamento allo scanner.
    $doorman = User::factory()->create();
    $date->checkinStaff()->attach($doorman);
    $this->actingAs($doorman)->get(route('ticketing.manage.index'))->assertOk()
        ->assertSee('Concerto al chiostro')->assertSee(route('ticketing.manage.scanner', $date), false)
        ->assertDontSee('href="'.route('ticketing.manage.show', $date).'"', false);

    // Chi non gestisce nulla non entra nemmeno nell'elenco.
    $this->actingAs(User::factory()->create())->get(route('ticketing.manage.index'))->assertForbidden();
});

it('configura la data con orari locali, domande e posti, e alzando i posti promuove chi aspetta', function (): void {
    ['date' => $date, 'owner' => $owner] = tktFlowScenario(['booking_capacity' => 10, 'booking_limit' => 4]);
    $settings = [
        'booking_enabled' => '1', 'booking_waitlist' => '1', 'booking_capacity' => '3', 'booking_limit' => '2',
        'booking_opens_at' => '2026-10-12T10:00', 'booking_closes_at' => '2026-10-17T20:00', 'cancellation_closes_at' => '2026-10-16T12:00',
        'booking_instructions' => 'Ingresso dal cortile interno.',
        'booking_fields' => ['phone' => 'optional', 'address' => 'hidden', 'city' => 'hidden', 'postal_code' => 'hidden', 'country' => 'hidden'],
        'booking_questions' => [['id' => '', 'label' => 'Allergie alimentari', 'type' => 'text', 'options' => '', 'required' => '0'], ['id' => '', 'label' => '', 'type' => 'text', 'options' => '', 'required' => '0']],
    ];

    $this->actingAs($owner)->post(route('ticketing.manage.configure', $date), $settings)
        ->assertRedirect(route('ticketing.manage.show', $date))->assertSessionHas('status', __('ticketing.updated'));

    $date->refresh();
    // Gli orari scritti nell'ora di Padova (UTC+2 a ottobre) si salvano in UTC.
    expect($date->booking_capacity)->toBe(3)->and($date->booking_limit)->toBe(2)
        ->and($date->booking_opens_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-12 08:00')
        ->and($date->booking_closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-17 18:00')
        ->and($date->cancellation_closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-16 10:00')
        ->and($date->booking_fields['phone'])->toBe('optional')
        ->and($date->booking_questions)->toHaveCount(1)
        ->and($date->booking_questions[0]['label'])->toBe('Allergie alimentari')
        ->and($date->booking_questions[0]['id'])->toMatch('/^q[a-z0-9]{8}$/');
    expect(Activity::query()->where('description', 'configured')->where('subject_id', $date->id)->exists())->toBeTrue();

    // Le prenotazioni non sono ancora aperte: lo dicono la disponibilità e il rifiuto.
    $this->getJson('/api/v1/occurrences/'.$date->id.'/booking')->assertOk()
        ->assertJsonPath('data.open', false)->assertJsonPath('data.sale_state', 'not_yet_on_sale')
        ->assertJsonPath('data.limit_per_account', 2)->assertJsonPath('data.questions.0.label', 'Allergie alimentari');
    $early = User::factory()->create();
    $this->actingAs($early)->post(route('tickets.store', $date), tktFlowWebPayload([['Troppo', 'Presto']]))
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.closed')]);

    // Aperte le prenotazioni, due gruppi da due: il secondo finisce in coda.
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(9, 0));
    $this->get(route('tickets.create', $date))->assertOk()->assertSee('Ingresso dal cortile interno.')->assertSee('Allergie alimentari');
    $first = tktFlowBook(User::factory()->create(), $date, ['Ada', 'Bea']);
    $queued = tktFlowBook($waiting = User::factory()->create(), $date, ['Cri', 'Dan'], true);
    expect($first->status)->toBe(BookingStatus::Confirmed)->and($queued->status)->toBe(BookingStatus::Waitlisted);

    // I posti non scendono sotto i biglietti già emessi.
    $this->actingAs($owner)->post(route('ticketing.manage.configure', $date), [...$settings, 'booking_capacity' => '1'])
        ->assertRedirect(route('ticketing.manage.show', $date))
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.capacity_too_low')]);
    expect($date->fresh()->booking_capacity)->toBe(3);

    // Alzarli a quattro fa entrare il gruppo in coda, che riceve l'avviso.
    $this->post(route('ticketing.manage.configure', $date), [...$settings, 'booking_capacity' => '4'])->assertSessionHasNoErrors();
    expect($queued->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($queued->fresh()->promotion_expires_at)->not->toBeNull();
    Notification::assertSentTo($waiting, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'promoted');
    $this->get(route('ticketing.manage.show', $date))->assertOk()->assertSee(__('ticketing.remaining', ['count' => 0]));

    // Una chiusura prima dell'apertura non passa la validazione.
    $this->post(route('ticketing.manage.configure', $date), [...$settings, 'booking_closes_at' => '2026-10-11T10:00'])->assertSessionHasErrors('booking_closes_at');
});

it('aggiunge una persona al personale della porta, che usa lo scanner ma non vede la gestione, e poi la toglie', function (): void {
    ['date' => $date, 'owner' => $owner] = tktFlowScenario();
    $doorman = User::factory()->create(['email' => 'porta@example.test']);
    $booking = tktFlowBook(User::factory()->create(), $date, ['Ospite Atteso']);

    $this->actingAs($owner)->post(route('ticketing.manage.staff', $date), ['email' => 'porta@example.test'])
        ->assertRedirect(route('ticketing.manage.show', $date))->assertSessionHas('status', __('decision.staff_saved'));
    // Parte un invito: finché la persona non accetta, la data non la aggancia.
    expect($date->checkinStaff()->whereKey($doorman->id)->exists())->toBeFalse();
    $this->get(route('ticketing.manage.show', $date))->assertSee('porta@example.test')->assertSee(__('decision.staff_pending'));
    // Un indirizzo che non corrisponde a nessun account riceve lo stesso invito e compare allo
    // stesso modo: il campo non deve dire chi ha un account.
    $this->post(route('ticketing.manage.staff', $date), ['email' => 'nessuno@example.test'])->assertSessionHasNoErrors();
    expect($date->staffInvitations()->count())->toBe(2)->and($date->checkinStaff()->count())->toBe(0);

    acceptCheckinInvitation($date, $doorman);
    expect($date->checkinStaff()->whereKey($doorman->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('description', 'staff_assigned')->where('subject_id', $date->id)->exists())->toBeTrue();

    // Il personale apre lo scanner, con la fotocamera permessa, ma non la gestione.
    $this->actingAs($doorman)->get(route('ticketing.manage.scanner', $date))->assertOk()
        ->assertSee('Concerto al chiostro')
        ->assertHeader('Permissions-Policy', 'geolocation=(self), microphone=(), payment=(), usb=(), camera=(self)');
    $this->get(route('ticketing.manage.show', $date))->assertForbidden();
    $this->get(route('ticketing.manage.export', $date))->assertForbidden();
    $this->post(route('ticketing.manage.staff', $date), ['email' => $doorman->email, 'remove' => '1'])->assertForbidden();

    // Alla porta registra l'ingresso e torna allo scanner.
    $this->travelTo(now()->setDate(2026, 10, 17)->setTime(18, 30));
    $ticket = $booking->tickets()->sole();
    $this->post(route('ticketing.manage.checkin', $date), ['code' => $ticket->code])
        ->assertRedirect(route('ticketing.manage.scanner', $date))
        ->assertSessionHas('status', __('ticketing.checkin_success', ['name' => 'Ospite Atteso']));
    expect($ticket->fresh()->status)->toBe(AdmissionStatus::CheckedIn)->and($ticket->fresh()->checked_in_by)->toBe($doorman->id);

    // Il proprietario lo toglie: da quel momento lo scanner gli è chiuso.
    $this->actingAs($owner)->post(route('ticketing.manage.staff', $date), ['email' => 'porta@example.test', 'remove' => '1'])->assertRedirect(route('ticketing.manage.show', $date));
    expect($date->checkinStaff()->whereKey($doorman->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('description', 'staff_revoked')->exists())->toBeTrue();
    $this->actingAs($doorman)->get(route('ticketing.manage.scanner', $date))->assertForbidden();
    $this->get(route('ticketing.manage.index'))->assertForbidden();
});

it('registra gli ingressi alla porta e rifiuta QR già usati, di un altra data, in coda o fuori orario', function (): void {
    ['date' => $date, 'owner' => $owner] = tktFlowScenario(['booking_capacity' => 2]);
    $tomorrow = occurrenceAt($date->event->city, $date->event->category, '2026-10-18 19:00:00', '2026-10-18 22:00:00', ['booking_enabled' => true], [], $date->event->venue);
    $giulia = User::factory()->create();
    $booking = tktFlowBook($giulia, $date, ['Giulia Rossi', 'Marco Bianchi']);
    $otherDay = tktFlowBook(User::factory()->create(), $tomorrow, ['Del Giorno Dopo']);
    $queued = tktFlowBook(User::factory()->create(), $date, ['In Coda'], true);
    [$giuliaTicket, $marcoTicket] = $booking->tickets()->orderBy('id')->get()->all();
    $url = route('ticketing.manage.checkin', $date);

    // Una settimana prima la porta è chiusa.
    $this->actingAs($owner)->post($url, ['code' => $giuliaTicket->code])
        ->assertRedirect(route('ticketing.manage.show', $date))
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.checkin_closed')]);

    // La sera stessa, un'ora prima dell'inizio.
    $this->travelTo(now()->setDate(2026, 10, 17)->setTime(18, 0));
    $this->post($url, ['code' => $giuliaTicket->code])
        ->assertRedirect(route('ticketing.manage.show', $date))
        ->assertSessionHas('status', __('ticketing.checkin_success', ['name' => 'Giulia Rossi']));
    $giuliaTicket->refresh();
    expect($giuliaTicket->status)->toBe(AdmissionStatus::CheckedIn)
        ->and($giuliaTicket->checked_in_by)->toBe($owner->id)
        ->and($giuliaTicket->checked_in_at->equalTo(now()))->toBeTrue()
        ->and(Activity::query()->where('description', 'checked_in:'.$giuliaTicket->id)->exists())->toBeTrue();

    $this->post($url, ['code' => $giuliaTicket->code])->assertSessionHasErrors(['ticketing' => __('ticketing.errors.already_used')]);
    $this->post($url, ['code' => $otherDay->tickets()->sole()->code])->assertSessionHasErrors(['ticketing' => __('ticketing.errors.invalid_qr')]);
    $this->post($url, ['code' => $queued->tickets()->sole()->code])->assertSessionHasErrors(['ticketing' => __('ticketing.errors.invalid_qr')]);
    $this->post($url, ['code' => 'non-è-un-qr'])->assertSessionHasErrors('code');
    expect($otherDay->tickets()->sole()->status)->toBe(AdmissionStatus::Valid);

    // Il contatore della pagina e la pagina di chi ha prenotato riflettono l'ingresso.
    expect(app(TicketingService::class)->statistics($date))->toMatchArray(['checked_in' => 1, 'valid' => 1, 'waitlisted' => 1]);
    $this->get(route('ticketing.manage.show', $date))->assertOk()->assertSee(__('ticketing.statuses.checked_in'));
    $this->actingAs($giulia)->get(route('tickets.index'))->assertOk()->assertSee(__('ticketing.statuses.checked_in'));
    // Chi è già entrato non può annullare quel biglietto.
    $this->post(route('tickets.cancel', $booking), ['ticket_id' => $giuliaTicket->id])
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.already_used')]);

    // Marco entra con il suo biglietto; la data di domani non apre la porta prima del tempo.
    $this->actingAs($owner)->post($url, ['code' => $marcoTicket->code])->assertSessionHasNoErrors();
    $this->travelTo(now()->setDate(2026, 10, 18)->setTime(1, 0));
    $this->post(route('ticketing.manage.checkin', $tomorrow), ['code' => $otherDay->tickets()->sole()->code])
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.checkin_closed')]);
});

it('scarica l elenco ingressi per la porta senza rete, con le sole impronte dei QR ammessi', function (): void {
    ['date' => $date, 'owner' => $owner] = tktFlowScenario(['booking_capacity' => 3]);
    $doorman = User::factory()->create();
    $date->checkinStaff()->attach($doorman);
    $booking = tktFlowBook(User::factory()->create(), $date, ['Ada Lovelace', 'Bea Bianchi']);
    $guest = app(TicketingService::class)->reserveForGuest($date, ['Carla Banco'], [], $owner);
    // Un gruppo di due in coda: il posto che si libera non basta a farlo entrare.
    $queued = tktFlowBook(User::factory()->create(), $date, ['Dora Coda', 'Ezio Coda'], true);
    [$ada, $bea] = $booking->tickets()->orderBy('id')->get()->all();
    app(TicketingService::class)->cancel($booking, $booking->user, $bea->id);

    $response = $this->actingAs($doorman)->getJson(route('ticketing.manage.manifest', $date))->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertJsonPath('data.window.from', '2026-10-17T16:00:00+00:00')
        ->assertJsonCount(2, 'data.tickets');
    $hashes = collect($response->json('data.tickets'))->pluck('h')->all();
    expect($hashes)->toBe([hash('sha256', $ada->code), hash('sha256', $guest->tickets()->sole()->code)])
        ->and(collect($response->json('data.tickets'))->pluck('name')->all())->toBe(['Ada Lovelace', 'Carla Banco'])
        ->and($response->getContent())->not->toContain($ada->code)
        ->and($response->getContent())->not->toContain('Bea Bianchi')
        ->and($response->getContent())->not->toContain('Dora Coda');

    // Dopo l'ingresso di Ada, l'elenco aggiornato lo dice.
    $this->travelTo(now()->setDate(2026, 10, 17)->setTime(18, 45));
    $this->postJson(route('ticketing.manage.checkin', $date), ['code' => $ada->code, 'request_key' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('data.status', 'checked_in')->assertJsonPath('data.attendee_name', 'Ada Lovelace');
    $this->getJson(route('ticketing.manage.manifest', $date))->assertJsonPath('data.tickets.0.status', 'checked_in');

    expect($queued->fresh()->status)->toBe(BookingStatus::Waitlisted);
    $this->actingAs(User::factory()->create())->getJson(route('ticketing.manage.manifest', $date))->assertForbidden();
});

it('scrive a chi ha prenotato, sceglie se includere la coda e rispetta il tetto giornaliero', function (): void {
    ['date' => $date, 'owner' => $owner] = tktFlowScenario(['booking_capacity' => 3]);
    $confirmed = User::factory()->create();
    $waiting = User::factory()->create();
    tktFlowBook($confirmed, $date, ['Ada', 'Bea']);
    app(TicketingService::class)->reserveForGuest($date, ['Telefonata'], ['email' => 'banco@example.test'], $owner);
    tktFlowBook($waiting, $date, ['Cri'], true);
    Notification::fake();

    $this->actingAs($owner)->post(route('ticketing.manage.message', $date), ['subject' => 'Porta la tessera', 'body' => 'Senza tessera ARCI non si entra.', 'include_waitlisted' => '0'])
        ->assertRedirect(route('ticketing.manage.show', $date))
        ->assertSessionHas('status', __('ticketing.messages.sent', ['count' => 2]));
    $message = BookingMessage::query()->sole();
    expect($message->recipients_count)->toBe(2)->and($message->sender_id)->toBe($owner->id)->and($message->include_waitlisted)->toBeFalse();
    Notification::assertSentTo($confirmed, BookingMessageSent::class, fn (BookingMessageSent $n): bool => $n->messageId === $message->id);
    Notification::assertSentTo(new AnonymousNotifiable, BookingMessageSent::class, fn ($n, $c, $to): bool => $to->routes['mail'] === 'banco@example.test');
    Notification::assertNotSentTo($waiting, BookingMessageSent::class);
    expect(Activity::query()->where('description', 'message_sent')->where('subject_id', $date->id)->exists())->toBeTrue();

    // Il secondo messaggio raggiunge anche la coda.
    $this->post(route('ticketing.manage.message', $date), ['subject' => 'Si comincia alle 22', 'body' => 'Porte aperte alle 21.', 'include_waitlisted' => '1'])
        ->assertSessionHas('status', __('ticketing.messages.sent', ['count' => 3]));
    Notification::assertSentTo($waiting, BookingMessageSent::class);

    // La storia è nella pagina della data.
    $this->get(route('ticketing.manage.show', $date))->assertOk()
        ->assertSee(__('ticketing.messages.history'))->assertSee('Porta la tessera')->assertSee('Si comincia alle 22');

    // Un messaggio vuoto non parte; dopo tre al giorno nemmeno uno pieno.
    $this->post(route('ticketing.manage.message', $date), ['subject' => '   ', 'body' => 'x'])->assertSessionHasErrors('subject');
    $this->post(route('ticketing.manage.message', $date), ['subject' => 'Tre', 'body' => 'Terzo avviso'])->assertSessionHasNoErrors();
    $this->post(route('ticketing.manage.message', $date), ['subject' => 'Quattro', 'body' => 'Quarto avviso'])
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.message_limit')]);
    expect(BookingMessage::query()->count())->toBe(3);
});

it('registra al banco la prenotazione di un ospite senza account e gli spedisce i biglietti', function (): void {
    ['date' => $date, 'owner' => $owner] = tktFlowScenario(['booking_capacity' => 3]);

    $this->actingAs($owner)->post(route('ticketing.manage.guest', $date), ['names' => "Paolo Rossi\n\n  Lucia Rossi  \n", 'email' => 'paolo@example.test', 'note' => 'Ha telefonato alle 18'])
        ->assertRedirect(route('ticketing.manage.show', $date))
        ->assertSessionHas('status', __('ticketing.guest.saved', ['count' => 2]));

    $booking = Booking::query()->sole();
    expect($booking->user_id)->toBeNull()
        ->and($booking->created_by)->toBe($owner->id)
        ->and($booking->isStaffBooking())->toBeTrue()
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->privacy_accepted_at)->toBeNull()
        ->and($booking->booker_data)->toMatchArray(['email' => 'paolo@example.test', 'note' => 'Ha telefonato alle 18'])
        ->and($booking->tickets()->orderBy('id')->pluck('attendee_name')->all())->toBe(['Paolo Rossi', 'Lucia Rossi'])
        ->and($booking->tickets()->where('status', AdmissionStatus::Valid)->count())->toBe(2);
    Notification::assertSentTo(new AnonymousNotifiable, GuestBookingConfirmation::class, fn (GuestBookingConfirmation $n, $c, $to): bool => $to->routes['mail'] === 'paolo@example.test' && $n->bookingId === $booking->id);
    expect(Activity::query()->where('description', 'reserved_by_staff')->where('subject_id', $booking->id)->sole()->properties['mailed'])->toBeTrue();

    // Nella pagina della data l'ospite porta il segno del banco e si trova con la ricerca.
    $this->get(route('ticketing.manage.show', $date))->assertOk()->assertSee(__('ticketing.guest.badge'))->assertSee('Lucia Rossi');
    $this->get(route('ticketing.manage.show', ['occurrence' => $date, 'q' => 'Lucia']))->assertOk()->assertSee('Paolo Rossi');
    $this->get(route('ticketing.manage.show', ['occurrence' => $date, 'q' => 'Nessunonome']))->assertOk()->assertDontSee('Paolo Rossi')->assertSee('corrispondenti alla ricerca');

    // Senza nomi o con un'email sbagliata il modulo si ferma; oltre i posti rimasti pure.
    $this->post(route('ticketing.manage.guest', $date), ['names' => "  \n "])->assertSessionHasErrors();
    $this->post(route('ticketing.manage.guest', $date), ['names' => 'Mario', 'email' => 'non-una-email'])->assertSessionHasErrors('email');
    $this->post(route('ticketing.manage.guest', $date), ['names' => "Uno\nDue"])
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.full')]);
    // Senza email la prenotazione si registra lo stesso, e non parte posta.
    Notification::fake();
    $this->post(route('ticketing.manage.guest', $date), ['names' => 'Ultimo Posto'])->assertSessionHasNoErrors();
    Notification::assertNothingSent();
    expect(Booking::query()->count())->toBe(2);
    $this->getJson('/api/v1/occurrences/'.$date->id.'/booking')->assertJsonPath('data.remaining', 0);
});

it('annulla dal pannello la prenotazione di una persona anche oltre il termine, con il motivo, e promuove chi aspetta', function (): void {
    ['date' => $date, 'owner' => $owner] = tktFlowScenario(['booking_capacity' => 2, 'cancellation_closes_at' => '2026-10-11 12:00:00']);
    $anna = User::factory()->create();
    $waiting = User::factory()->create();
    $booking = tktFlowBook($anna, $date, ['Anna Prima', 'Anna Seconda']);
    $queued = tktFlowBook($waiting, $date, ['Chi Aspetta'], true);
    [$first, $second] = $booking->tickets()->orderBy('id')->get()->all();

    // Passato il termine, la persona non può più annullare da sola.
    $this->travelTo(now()->setDate(2026, 10, 12)->setTime(12, 0));
    $this->actingAs($anna)->post(route('tickets.cancel', $booking))
        ->assertSessionHasErrors(['ticketing' => __('ticketing.errors.cancellation_closed')]);
    expect($booking->fresh()->status)->toBe(BookingStatus::Confirmed);

    // Il gestore toglie un biglietto: il posto liberato va a chi è in coda.
    $this->actingAs($owner)->post(route('ticketing.manage.cancel', $booking), ['ticket_id' => $first->id])
        ->assertRedirect(route('ticketing.manage.show', $date))->assertSessionHas('status', __('ticketing.mail.cancelled'));
    expect($first->fresh()->status)->toBe(AdmissionStatus::Cancelled)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and($queued->fresh()->status)->toBe(BookingStatus::Confirmed);
    Notification::assertSentTo($waiting, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'promoted');

    // Poi annulla il resto, scrivendo il motivo.
    $this->post(route('ticketing.manage.cancel', $booking), ['reason' => 'Doppia prenotazione segnalata al telefono'])
        ->assertRedirect(route('ticketing.manage.show', $date));
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled)
        ->and($booking->fresh()->cancellation_reason)->toBe('Doppia prenotazione segnalata al telefono')
        ->and($second->fresh()->status)->toBe(AdmissionStatus::Cancelled);
    Notification::assertSentTo($anna, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'cancelled');
    expect(Activity::query()->where('description', 'cancelled')->where('subject_id', $booking->id)->where('causer_id', $owner->id)->count())->toBe(2);

    // Il personale della porta non può annullare prenotazioni.
    $doorman = User::factory()->create();
    $date->checkinStaff()->attach($doorman);
    $this->actingAs($doorman)->post(route('ticketing.manage.cancel', $queued))->assertForbidden();
    expect($queued->fresh()->status)->toBe(BookingStatus::Confirmed);
});

it('esporta i partecipanti in CSV e stampa la lista d ingresso in PDF, lasciandone traccia', function (): void {
    ['date' => $date, 'owner' => $owner] = tktFlowScenario([
        'booking_fields' => ['phone' => 'optional'],
        'booking_questions' => [['id' => 'qallergie', 'label' => 'Allergie', 'type' => 'text', 'options' => [], 'required' => false]],
    ]);
    $giulia = User::factory()->create(['email' => 'giulia@example.test']);
    $booking = app(TicketingService::class)->reserve($giulia, $date, [['first_name' => 'Giulia', 'last_name' => 'Rossi'], ['first_name' => '=HYPERLINK("x")', 'last_name' => 'Furbo']],
        (string) Str::uuid(), false, ['first_name' => 'Giulia', 'last_name' => 'Rossi', 'phone' => '3331234567'], ['qallergie' => 'noci']);
    app(TicketingService::class)->reserveForGuest($date, ['Carla Banco'], ['email' => 'carla@example.test'], $owner);

    $response = $this->actingAs($owner)->get(route('ticketing.manage.export', $date))->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->headers->get('content-disposition'))->toContain('partecipanti-'.$date->id.'.csv');
    $csv = $response->streamedContent();
    $rows = array_map(fn (string $line): array => str_getcsv($line, ';', '"', ''), array_values(array_filter(explode("\n", substr($csv, 3)))));
    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($rows[0][0])->toBe(__('ticketing.ticket'))
        ->and(end($rows[0]))->toBe('Allergie')
        ->and($rows)->toHaveCount(4)
        ->and($csv)->toContain('Giulia Rossi')->toContain('giulia@example.test')->toContain('3331234567')->toContain('noci')
        ->and($csv)->toContain('Carla Banco')->toContain('carla@example.test')
        // Le formule si neutralizzano e i codici dei QR non escono mai.
        ->and($csv)->toContain("'=HYPERLINK")
        ->and($csv)->not->toContain($booking->tickets()->first()->code);
    expect(Activity::query()->where('description', 'participants_exported')->where('causer_id', $owner->id)->count())->toBe(1);

    $pdf = $this->get(route('ticketing.manage.print', $date))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect($pdf->headers->get('content-disposition'))->toContain('lista-ingressi-'.$date->id.'.pdf')
        ->and(substr((string) $pdf->getContent(), 0, 5))->toBe('%PDF-')
        ->and(Activity::query()->where('description', 'participants_printed')->where('causer_id', $owner->id)->count())->toBe(1);

    // Chi ha prenotato e il personale della porta non scaricano i dati degli altri.
    $doorman = User::factory()->create();
    $date->checkinStaff()->attach($doorman);
    foreach ([$giulia, $doorman] as $user) {
        $this->actingAs($user)->get(route('ticketing.manage.export', $date))->assertForbidden();
        $this->actingAs($user)->get(route('ticketing.manage.print', $date))->assertForbidden();
    }
    expect(Activity::query()->whereIn('description', ['participants_exported', 'participants_printed'])->count())->toBe(2);
});

it('chiude ogni pagina e azione della gestione a chi non gestisce la data, senza cambiare nulla', function (): void {
    ['date' => $date] = tktFlowScenario();
    $booking = tktFlowBook(User::factory()->create(), $date, ['Persona Vera']);
    $ticket = $booking->tickets()->sole();
    $this->travelTo(now()->setDate(2026, 10, 17)->setTime(18, 0));
    Notification::fake();

    // Il proprietario di un altro locale e un redattore dello stesso locale.
    $otherOwner = User::factory()->create();
    $otherOwner->venues()->attach(Venue::factory()->approved()->create()->id, ['role' => 'owner']);
    $editor = User::factory()->create();
    $editor->venues()->attach($date->event->venue_id, ['role' => 'editor']);

    foreach ([$otherOwner, $editor] as $intruder) {
        $this->actingAs($intruder);
        $this->get(route('ticketing.manage.show', $date))->assertForbidden();
        $this->get(route('ticketing.manage.scanner', $date))->assertForbidden();
        $this->post(route('ticketing.manage.configure', $date), ['booking_enabled' => '0', 'booking_limit' => '1', 'booking_waitlist' => '0'])->assertForbidden();
        $this->post(route('ticketing.manage.staff', $date), ['email' => $intruder->email])->assertForbidden();
        $this->post(route('ticketing.manage.checkin', $date), ['code' => $ticket->code])->assertForbidden();
        $this->getJson(route('ticketing.manage.manifest', $date))->assertForbidden();
        $this->post(route('ticketing.manage.message', $date), ['subject' => 'Spam', 'body' => 'Spam'])->assertForbidden();
        $this->post(route('ticketing.manage.guest', $date), ['names' => 'Abusivo'])->assertForbidden();
        $this->get(route('ticketing.manage.export', $date))->assertForbidden();
        $this->get(route('ticketing.manage.print', $date))->assertForbidden();
        $this->post(route('ticketing.manage.cancel', $booking), ['reason' => 'Dispetto'])->assertForbidden();
    }
    $this->actingAs($otherOwner)->get(route('ticketing.manage.index'))->assertOk()->assertDontSee('Concerto al chiostro');

    // Nulla è cambiato: stessa data, stesso biglietto, nessun messaggio, nessun ospite.
    $date->refresh();
    expect($date->booking_enabled)->toBeTrue()
        ->and($date->checkinStaff()->count())->toBe(0)
        ->and($ticket->fresh()->status)->toBe(AdmissionStatus::Valid)
        ->and($booking->fresh()->status)->toBe(BookingStatus::Confirmed)
        ->and(BookingMessage::query()->count())->toBe(0)
        ->and(AdmissionTicket::query()->count())->toBe(1);
    Notification::assertNothingSent();

    // Senza accesso si va al login.
    $this->app['auth']->forgetGuards();
    $this->get(route('ticketing.manage.index'))->assertRedirect();
    $this->get(route('ticketing.manage.show', $date))->assertRedirect();
});
