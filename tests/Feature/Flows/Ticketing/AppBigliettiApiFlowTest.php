<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Jobs\DeactivateWalletPass;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\Sponsorship;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\BookingChanged;
use App\Notifications\BookingMessageSent;
use App\Notifications\GuestBookingConfirmation;
use App\Notifications\TicketShared;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/TicketingFlowSupport.php';

/*
 * Gli stessi flussi visti dall'app: prenotare, ritrovare i biglietti, metterli
 * nel portafoglio, mandarli a qualcuno; e, dall'altra parte, gestire le date
 * e la porta con le API di gestione.
 */
beforeEach(function (): void {
    Notification::fake();
    (new RolesAndPermissionsSeeder)->run();
});

it('prenota dall app, ritrova i biglietti, si fa rispedire l email e annulla un posto alla volta', function (): void {
    ['date' => $date] = tktFlowScenario();
    $base = '/api/v1/occurrences/'.$date->id;

    // Prima di accedere l'app legge la disponibilità e se esiste il portafoglio.
    $availability = $this->getJson($base.'/booking')->assertOk();
    expect($availability->headers->get('Cache-Control'))->toContain('no-store');
    $availability->assertJsonPath('data.enabled', true)->assertJsonPath('data.open', true)
        ->assertJsonPath('data.sale_state', 'available')->assertJsonPath('data.remaining', 10)->assertJsonPath('data.limit_per_account', 4);
    $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.google_wallet', false);
    $this->postJson($base.'/bookings', tktFlowApiPayload(['Giulia Rossi']))->assertUnauthorized();

    // Un account non confermato non prenota nemmeno dall'app.
    Sanctum::actingAs(User::factory()->unverified()->create());
    $this->postJson($base.'/bookings', tktFlowApiPayload(['Giulia Rossi']))
        ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
    expect(Booking::query()->count())->toBe(0);

    $giulia = User::factory()->create();
    Sanctum::actingAs($giulia);
    $payload = tktFlowApiPayload(['Giulia Rossi', 'Marco Bianchi']);
    $created = $this->postJson($base.'/bookings', $payload)->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.title', 'Concerto al chiostro')
        ->assertJsonCount(2, 'data.tickets')->assertJsonPath('data.tickets.1.attendee_name', 'Marco Bianchi');
    $bookingId = $created->json('data.id');
    expect($created->json('data.tickets.0.qr_payload'))->toHaveLength(64)
        ->and($created->json('data.tickets.1.share_url'))->toContain('/biglietto/')->toContain('signature=');
    // La stessa richiesta ripetuta (rete ballerina) non occupa altri posti.
    $this->postJson($base.'/bookings', $payload)->assertCreated()->assertJsonPath('data.id', $bookingId);
    expect(Booking::query()->count())->toBe(1);

    $this->getJson('/api/v1/me/bookings')->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bookingId)
        ->assertJsonPath('data.0.can_cancel', true)->assertJsonPath('meta.no_shows', 0)->assertJsonPath('meta.next_page', null);

    Notification::fake();
    $this->postJson('/api/v1/me/bookings/'.$bookingId.'/email')->assertOk()->assertJsonPath('data.message', __('ticketing.email_queued'));
    Notification::assertSentTo($giulia, BookingChanged::class, fn (BookingChanged $n): bool => $n->bookingId === $bookingId && $n->kind === 'confirmed');

    // Un'altra persona non spedisce né annulla questa prenotazione.
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/me/bookings/'.$bookingId.'/email')->assertForbidden();
    $this->postJson('/api/v1/me/bookings/'.$bookingId.'/cancel')->assertForbidden();
    $this->getJson('/api/v1/me/bookings')->assertOk()->assertJsonCount(0, 'data');

    Sanctum::actingAs($giulia);
    $marcoId = $created->json('data.tickets.1.id');
    $this->postJson('/api/v1/me/bookings/'.$bookingId.'/cancel', ['ticket_id' => $marcoId])->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.tickets.1.status', 'cancelled')->assertJsonPath('data.tickets.1.qr_payload', null)
        ->assertJsonPath('data.tickets.0.status', 'valid');
    $this->getJson($base.'/booking')->assertJsonPath('data.remaining', 9);

    $this->postJson('/api/v1/me/bookings/'.$bookingId.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    Notification::assertSentTo($giulia, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'cancelled');
    $this->getJson($base.'/booking')->assertJsonPath('data.remaining', 10);
    // Le prenotazioni annullate finiscono in fondo, ma restano nell'elenco.
    $this->getJson('/api/v1/me/bookings')->assertJsonPath('data.0.status', 'cancelled');
});

it('dall app finisce in coda a posti esauriti, vede la posizione e riceve il QR quando viene promosso', function (): void {
    ['date' => $date] = tktFlowScenario(['booking_capacity' => 1, 'booking_limit' => 2]);
    $url = '/api/v1/occurrences/'.$date->id.'/bookings';
    $holder = User::factory()->create();
    $waiting = User::factory()->create();

    Sanctum::actingAs($holder);
    $holderBooking = $this->postJson($url, tktFlowApiPayload(['Ha Ilposto']))->assertCreated()->json('data.id');
    $this->getJson('/api/v1/occurrences/'.$date->id.'/booking')->assertJsonPath('data.sale_state', 'sold_out');

    Sanctum::actingAs($waiting);
    // Oltre il limite per account, e senza consenso alla coda, nessuna prenotazione.
    $this->postJson($url, tktFlowApiPayload(['Uno', 'Due', 'Tre'], ['waitlist' => true]))
        ->assertUnprocessable()->assertJsonPath('error.fields.ticketing.0', __('ticketing.errors.account_limit'));
    $this->postJson($url, tktFlowApiPayload(['Chi Aspetta']))
        ->assertUnprocessable()->assertJsonPath('error.fields.ticketing.0', __('ticketing.errors.full'));
    $this->postJson($url, tktFlowApiPayload(['Chi Aspetta'], ['accept_terms' => false, 'waitlist' => true]))->assertUnprocessable();
    expect(Booking::query()->where('user_id', $waiting->id)->exists())->toBeFalse();

    $queued = $this->postJson($url, tktFlowApiPayload(['Chi Aspetta'], ['waitlist' => true]))->assertCreated()
        ->assertJsonPath('data.status', 'waitlisted')->assertJsonPath('data.queue_position', 1)
        ->assertJsonPath('data.tickets.0.status', 'waitlisted')->assertJsonPath('data.tickets.0.qr_payload', null)
        ->assertJsonPath('data.tickets.0.share_url', null)->json('data.id');

    // Chi aveva il posto rinuncia dall'app.
    Sanctum::actingAs($holder);
    $this->postJson('/api/v1/me/bookings/'.$holderBooking.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');

    Sanctum::actingAs($waiting);
    $mine = $this->getJson('/api/v1/me/bookings')->assertOk()
        ->assertJsonPath('data.0.id', $queued)->assertJsonPath('data.0.status', 'confirmed')
        ->assertJsonPath('data.0.queue_position', null)->assertJsonPath('data.0.tickets.0.status', 'valid');
    expect($mine->json('data.0.promotion_expires_at'))->toBe(now()->addHours(12)->toIso8601String())
        ->and($mine->json('data.0.tickets.0.qr_payload'))->toHaveLength(64);
    Notification::assertSentTo($waiting, BookingChanged::class, fn (BookingChanged $n): bool => $n->kind === 'promoted');

    // L'API non ha una rotta per confermare: la conferma passa dal sito.
    $this->actingAs($waiting)->post(route('tickets.confirm', $queued))->assertSessionHasNoErrors();
    expect(Booking::query()->findOrFail($queued)->promotion_expires_at)->toBeNull();
});

it('mette un biglietto nel portafoglio Google, lo manda a un amico dall app e lo spegne quando lo annulla', function (): void {
    ['date' => $date] = tktFlowScenario();
    $pem = '';
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048]), $pem);
    config(['wallet.google.issuer_id' => 'issuer', 'wallet.google.class_id' => 'serate', 'wallet.google.service_account_email' => 'wallet@example.test', 'wallet.google.private_key' => $pem]);
    Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'finto']), 'walletobjects.googleapis.com/*' => Http::response([], 200)]);
    $giulia = User::factory()->create(['name' => 'Giulia Rossi']);
    $booking = tktFlowBook($giulia, $date, ['Giulia Rossi', 'Marco Bianchi']);
    [$own, $friend] = $booking->tickets()->orderBy('id')->get()->all();

    $this->getJson('/api/v1/wallet')->assertOk()->assertJsonPath('data.google_wallet', true);

    Sanctum::actingAs($giulia);
    $saveUrl = $this->postJson('/api/v1/me/tickets/'.$own->id.'/wallet')->assertOk()
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private')->json('data.save_url');
    expect($saveUrl)->toStartWith('https://pay.google.com/gp/v/save/')
        ->and($own->fresh()->wallet_requested_at)->not->toBeNull();
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_contains($request->url(), 'eventTicketObject')
        && $request['id'] === 'issuer.incitta-'.$own->id && $request['barcode']['value'] === $own->code && $request['ticketHolderName'] === 'Giulia Rossi');

    // Il biglietto di Marco parte per email, solo con il consenso.
    $this->postJson('/api/v1/me/tickets/'.$friend->id.'/send', ['email' => 'marco@example.test'])->assertUnprocessable();
    $this->postJson('/api/v1/me/tickets/'.$friend->id.'/send', ['email' => 'marco@example.test', 'consent' => true])
        ->assertOk()->assertJsonPath('data.message', __('ticketing.share.sent'));
    Notification::assertSentTo(new AnonymousNotifiable, TicketShared::class, fn (TicketShared $n, $c, $to): bool => $to->routes['mail'] === 'marco@example.test' && $n->ticketId === $friend->id);

    // Un estraneo non mette nel portafoglio né spedisce biglietti altrui.
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/me/tickets/'.$own->id.'/wallet')->assertForbidden();
    $this->postJson('/api/v1/me/tickets/'.$own->id.'/send', ['email' => 'x@example.test', 'consent' => true])->assertForbidden();

    // Annullato il biglietto, il pass viene disattivato e non si riemette.
    Queue::fake();
    Sanctum::actingAs($giulia);
    $this->postJson('/api/v1/me/bookings/'.$booking->id.'/cancel', ['ticket_id' => $own->id])->assertOk();
    Queue::assertPushed(DeactivateWalletPass::class, fn (DeactivateWalletPass $job): bool => $job->objectId === 'issuer.incitta-'.$own->id);
    $this->postJson('/api/v1/me/tickets/'.$own->id.'/wallet')->assertNotFound();
    // Nemmeno il link condiviso di un biglietto annullato si rimanda.
    $this->postJson('/api/v1/me/tickets/'.$own->id.'/send', ['email' => 'altro@example.test', 'consent' => true])
        ->assertUnprocessable()->assertJsonPath('error.fields.email.0', __('ticketing.share.not_valid'));
});

it('gestisce dall app le date, il personale e i dettagli pratici, e il personale fa entrare la gente', function (): void {
    ['date' => $date, 'owner' => $owner] = tktFlowScenario();
    $doorman = User::factory()->create(['email' => 'porta@example.test']);
    $booking = tktFlowBook(User::factory()->create(), $date, ['Ada Lovelace']);
    $base = '/api/v1/management/dates/'.$date->id;

    $this->getJson('/api/v1/management/dates')->assertUnauthorized();

    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/management/dates')->assertOk()
        ->assertJsonPath('data.0.id', $date->id)->assertJsonPath('data.0.title', 'Concerto al chiostro')
        ->assertJsonPath('data.0.can_manage', true)->assertJsonPath('data.0.can_check_in', true);
    $this->getJson('/api/v1/management/dates?period=past')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/management/dates?q=chiostro')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/management/dates?q=altro')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson($base)->assertOk()
        ->assertJsonPath('data.availability.remaining', 9)->assertJsonPath('data.statistics.valid', 1)
        ->assertJsonPath('data.staff', [])->assertJsonPath('data.currency', 'EUR');

    $this->postJson($base.'/staff', ['email' => 'porta@example.test'])->assertOk()->assertJsonPath('data.message', __('decision.staff_saved'));
    $this->getJson($base)->assertJsonPath('data.staff.0.email', 'porta@example.test')->assertJsonPath('data.staff.0.status', 'pending');
    // Un indirizzo sconosciuto risponde e compare come uno registrato, in attesa: né la risposta
    // né la lista dicono chi ha un account.
    $this->postJson($base.'/staff', ['email' => 'nessuno@example.test'])->assertOk();
    expect($this->getJson($base)->json('data.staff'))->toBe([
        ['id' => 1, 'name' => 'nessuno@example.test', 'email' => 'nessuno@example.test', 'status' => 'pending'],
        ['id' => 2, 'name' => 'porta@example.test', 'email' => 'porta@example.test', 'status' => 'pending'],
    ]);
    $this->postJson($base.'/staff', ['email' => 'nessuno@example.test', 'remove' => true])->assertOk();
    acceptCheckinInvitation($date, $doorman);
    Sanctum::actingAs($owner);
    expect($this->getJson($base)->json('data.staff'))->toBe([['id' => 1, 'name' => 'porta@example.test', 'email' => 'porta@example.test', 'status' => 'accepted']]);

    $this->patchJson($base.'/details', ['practical_details' => ['accessibility' => 'yes', 'entrance_notes' => 'Ingresso dal cortile'], 'cost_breakdown' => ['admission' => '10.00', 'drink' => 0]])
        ->assertOk()->assertJsonPath('data.message', __('ticketing.updated'));
    expect($date->fresh()->practical_details)->toMatchArray(['accessibility' => 'yes', 'entrance_notes' => 'Ingresso dal cortile'])
        ->and(Activity::query()->where('description', 'decision_details_updated')->where('subject_id', $date->id)->exists())->toBeTrue();
    $this->getJson($base)->assertJsonPath('data.practical_details.entrance_notes', 'Ingresso dal cortile')->assertJsonPath('data.cost_breakdown.drink', 0);
    $this->patchJson($base.'/details', ['practical_details' => ['accessibility' => 'forse'], 'cost_breakdown' => []])->assertUnprocessable();

    // Il personale vede la data ma non i dati di gestione, e non li cambia.
    Sanctum::actingAs($doorman);
    $this->getJson('/api/v1/management/dates')->assertOk()->assertJsonPath('data.0.can_manage', false)->assertJsonPath('data.0.can_check_in', true);
    $this->getJson($base)->assertOk()->assertJsonMissingPath('data.staff')->assertJsonMissingPath('data.statistics');
    $this->patchJson($base.'/details', ['practical_details' => [], 'cost_breakdown' => []])->assertForbidden();
    $this->postJson($base.'/staff', ['email' => $doorman->email, 'remove' => true])->assertForbidden();

    // Alla porta: elenco scaricato, poi la lettura del QR, anche ripetuta dalla stessa coda offline.
    $this->travelTo(now()->setDate(2026, 10, 17)->setTime(18, 15));
    $code = $booking->tickets()->sole()->code;
    $this->getJson('/api/v1/ticketing/'.$date->id.'/manifest')->assertOk()->assertJsonPath('data.tickets.0.h', hash('sha256', $code));
    $scan = ['code' => $code, 'request_key' => (string) Str::uuid()];
    $this->postJson('/api/v1/ticketing/'.$date->id.'/check-in', $scan)->assertOk()
        ->assertJsonPath('data.status', 'checked_in')->assertJsonPath('data.attendee_name', 'Ada Lovelace');
    $this->postJson('/api/v1/ticketing/'.$date->id.'/check-in', $scan)->assertOk();
    $this->postJson('/api/v1/ticketing/'.$date->id.'/check-in', [...$scan, 'request_key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonPath('error.fields.ticketing.0', __('ticketing.errors.already_used'));
    expect($booking->tickets()->sole()->checked_in_by)->toBe($doorman->id);

    // Tolto dal personale, la porta gli si chiude subito.
    Sanctum::actingAs($owner);
    $this->postJson($base.'/staff', ['email' => 'porta@example.test', 'remove' => true])->assertOk();
    Sanctum::actingAs($doorman);
    $this->getJson($base)->assertForbidden();
    $this->getJson('/api/v1/ticketing/'.$date->id.'/manifest')->assertForbidden();
    $this->postJson('/api/v1/ticketing/'.$date->id.'/check-in', $scan)->assertForbidden();
    $this->getJson('/api/v1/management/dates')->assertOk()->assertJsonCount(0, 'data');
});

it('dall app il gestore registra ospiti al banco e scrive ai prenotati, e nessun altro può farlo', function (): void {
    ['date' => $date, 'owner' => $owner] = tktFlowScenario(['booking_capacity' => 4]);
    $person = User::factory()->create();
    $waiting = User::factory()->create();
    tktFlowBook($person, $date, ['Ada', 'Bea']);
    $base = '/api/v1/ticketing/'.$date->id;

    Sanctum::actingAs($owner);
    $guest = $this->postJson($base.'/guest-booking', ['names' => "Carla Banco\nDino Banco", 'email' => 'carla@example.test', 'note' => 'Amici del gruppo'])
        ->assertCreated()->assertJsonPath('data.status', 'confirmed')->assertJsonCount(2, 'data.tickets')
        ->assertJsonPath('data.tickets.0.attendee_name', 'Carla Banco');
    expect(Booking::query()->findOrFail($guest->json('data.id'))->created_by)->toBe($owner->id);
    Notification::assertSentTo(new AnonymousNotifiable, GuestBookingConfirmation::class, fn ($n, $c, $to): bool => $to->routes['mail'] === 'carla@example.test');
    $this->postJson($base.'/guest-booking', ['names' => 'Uno di troppo'])
        ->assertUnprocessable()->assertJsonPath('error.fields.ticketing.0', __('ticketing.errors.full'));
    $this->postJson($base.'/guest-booking', [])->assertUnprocessable();

    // A posti esauriti una persona entra in coda.
    tktFlowBook($waiting, $date, ['Eva'], true);
    Notification::fake();

    $this->postJson($base.'/message', ['subject' => 'Parcheggio', 'body' => 'Il parcheggio interno è chiuso.'])
        ->assertCreated()->assertJsonPath('data.recipients_count', 2);
    Notification::assertSentTo($person, BookingMessageSent::class);
    Notification::assertNotSentTo($waiting, BookingMessageSent::class);
    $this->postJson($base.'/message', ['subject' => 'Coda', 'body' => 'Si liberano posti?', 'include_waitlisted' => true])
        ->assertCreated()->assertJsonPath('data.recipients_count', 3);
    Notification::assertSentTo($waiting, BookingMessageSent::class);
    $this->postJson($base.'/message', ['subject' => 'Senza testo'])->assertUnprocessable();
    expect(BookingMessage::query()->count())->toBe(2);

    // Il personale della porta, chi ha prenotato e gli estranei non scrivono né aggiungono ospiti.
    $doorman = User::factory()->create();
    $date->checkinStaff()->attach($doorman);
    Notification::fake();
    foreach ([$doorman, $person, User::factory()->create()] as $intruder) {
        Sanctum::actingAs($intruder);
        $this->postJson($base.'/message', ['subject' => 'Spam', 'body' => 'Spam'])->assertForbidden();
        $this->postJson($base.'/guest-booking', ['names' => 'Abusivo'])->assertForbidden();
    }
    Notification::assertNothingSent();
    expect(BookingMessage::query()->count())->toBe(2)
        ->and(Booking::query()->where('occurrence_id', $date->id)->count())->toBe(3)
        ->and(Booking::query()->where('status', BookingStatus::Waitlisted)->count())->toBe(1);
});

it('mostra dall app le campagne di promozione del proprio locale e i loro conti, non quelle degli altri', function (): void {
    ['date' => $date, 'owner' => $owner] = tktFlowScenario();
    $mine = Sponsorship::factory()->create(['event_id' => $date->event_id, 'amount_cents' => 5000]);
    $otherVenue = Venue::factory()->approved()->create(['city_id' => $date->event->city_id]);
    $elsewhere = occurrenceAt($date->event->city, $date->event->category, '2026-10-20 19:00:00', null, [], ['title' => 'Serata altrui'], $otherVenue);
    $theirs = Sponsorship::factory()->create(['event_id' => $elsewhere->event_id, 'amount_cents' => 9000]);
    foreach (range(1, 2) as $i) {
        tktFlowBook(User::factory()->create(), $date, ['Persona '.$i]);
    }

    $this->getJson('/api/v1/management/campaigns')->assertUnauthorized();

    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/management/campaigns')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id)
        ->assertJsonPath('data.0.title', 'Concerto al chiostro')->assertJsonPath('meta.next_page', null);
    $this->getJson('/api/v1/management/campaigns/'.$mine->id)->assertOk()
        ->assertJsonPath('data.id', $mine->id)->assertJsonPath('data.economics.spend_cents', 5000)
        // Con due sole prenotazioni il rapporto non si mostra: campione troppo piccolo.
        ->assertJsonPath('data.economics.per_booking_cents', null);
    $this->getJson('/api/v1/management/campaigns/'.$theirs->id)->assertNotFound();
    $this->getJson('/api/v1/management/campaigns/non-un-numero')->assertNotFound();

    // L'amministrazione le vede tutte; chi non gestisce nulla, nessuna.
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Sanctum::actingAs($admin);
    $this->getJson('/api/v1/management/campaigns')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/management/campaigns/'.$theirs->id)->assertOk()->assertJsonPath('data.economics.spend_cents', 9000);

    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/management/campaigns')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/management/campaigns/'.$mine->id)->assertNotFound();
});
