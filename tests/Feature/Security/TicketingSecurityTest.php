<?php

declare(strict_types=1);

use App\Http\Controllers\Web\ImpersonationController;
use App\Models\Booking;
use App\Models\BookingMessage;
use App\Models\User;
use App\Notifications\BookingMessageSent;
use App\Notifications\TicketShared;
use App\Services\Ticketing\TicketingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 10, 23)->setTime(12, 0));
    (new RolesAndPermissionsSeeder)->run();
    $this->date = occurrenceAt(testCity(), testCategory(), '2026-10-24 18:00:00', '2026-10-24 22:00:00', ['booking_enabled' => true, 'booking_capacity' => 10]);
    $this->date->event->venue->update(['ticketing_enabled' => true]);
    $this->service = app(TicketingService::class);
    $this->payload = fn (): array => ['attendees' => ['Giulia Rossi'], 'request_key' => (string) Str::uuid(), 'accept_terms' => true];
});

it('mostra come testo, non come link o immagine, il Markdown scritto da chi condivide un biglietto', function (): void {
    $user = User::factory()->create();
    // Con un indirizzo vero il nome verrebbe scartato del tutto (test sotto):
    // qui i link relativi passano quel filtro e mettono alla prova il Markdown.
    $booking = $this->service->reserve($user, $this->date, ['[Conferma i dati](/x) ![](/px.gif)'], (string) Str::uuid(), false);

    $html = (string) (new TicketShared($booking->tickets[0]->id, 'Sospeso: [riattiva qui](/login)', 'https://app.test/biglietto/1'))
        ->toMail(new AnonymousNotifiable)->render();

    expect($html)->not->toContain('href="/login"')
        ->and($html)->not->toContain('href="/x"')
        ->and($html)->not->toContain('src="/px.gif"')
        ->and($html)->toContain('[riattiva qui](/login)')
        // Il pulsante generato dal codice resta un collegamento vero.
        ->and($html)->toContain('href="https://app.test/biglietto/1"');
});

it('non scrive nella mail del biglietto condiviso un nome o un nominativo che contiene un indirizzo web', function (): void {
    $user = User::factory()->create();
    $booking = $this->service->reserve($user, $this->date, ['www.evil.example'], (string) Str::uuid(), false);

    $mail = (new TicketShared($booking->tickets[0]->id, 'Account sospeso, riattiva su https://evil.example/login', 'https://app.test/biglietto/1'))
        ->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();

    // I client di posta rendono cliccabili gli URL in chiaro: non devono
    // comparire né nell'oggetto né nel corpo.
    expect($mail->subject)->not->toContain('evil.example')
        ->and($mail->subject)->toContain(__('ticketing.share.someone'))
        ->and($html)->not->toContain('evil.example')
        ->and($html)->toContain(e(__('ticketing.share.someone')))
        ->and($html)->toContain('href="https://app.test/biglietto/1"');
});

it('non si lascia aggirare con le entità HTML nel nome di chi condivide il biglietto', function (): void {
    $user = User::factory()->create();
    $booking = $this->service->reserve($user, $this->date, ['www&#46;evil&#46;example'], (string) Str::uuid(), false);

    $mail = (new TicketShared($booking->tickets[0]->id, 'Account sospeso, riattiva su https&#58;//evil&#46;example/login', 'https://app.test/biglietto/1'))
        ->toMail(new AnonymousNotifiable);
    $html = (string) $mail->render();

    expect($html)->not->toContain('evil.example')
        ->and($html)->not->toContain('evil&#46;example')
        ->and($html)->toContain(e(__('ticketing.share.someone')));
});

it('lascia nella mail del biglietto condiviso i nomi normali', function (): void {
    $user = User::factory()->create();
    $booking = $this->service->reserve($user, $this->date, ['Giulia Rossi'], (string) Str::uuid(), false);

    $mail = (new TicketShared($booking->tickets[0]->id, 'Marco Bianchi', 'https://app.test/biglietto/1'))->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toContain('Marco Bianchi')
        ->and((string) $mail->render())->toContain('Giulia Rossi');
});

it('non mostra né fa scaricare QR, PDF e link del biglietto durante l’impersonificazione', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $user = User::factory()->create();
    $booking = $this->service->reserve($user, $this->date, ['Giulia Rossi'], (string) Str::uuid(), false);
    $ticket = $booking->tickets[0];
    $shared = 'biglietto/'.$ticket->id.'?';

    // Fuori dall'impersonificazione la persona vede e scarica tutto.
    $this->actingAs($user)->get(route('tickets.index'))->assertOk()->assertSee('role="img" aria-label="'.__('ticketing.ticket'), false)->assertSee($shared, false);
    $this->get(route('tickets.show', $booking))->assertOk()->assertSee('role="img" aria-label="'.__('ticketing.ticket'), false)->assertSee($shared, false);
    $this->get(route('tickets.pdf', $ticket))->assertOk();
    $this->post(route('account.logout'));

    $this->actingAs($admin)->get(URL::signedRoute('impersonate.start', ['user' => $user->id]))->assertRedirect('/');
    expect(session()->has(ImpersonationController::SESSION_KEY))->toBeTrue();

    $this->get(route('tickets.pdf', $ticket))->assertForbidden();
    foreach ([route('tickets.index'), route('tickets.show', $booking)] as $url) {
        $this->get($url)->assertOk()
            ->assertSee('Giulia Rossi')
            ->assertSee(__('ticketing.qr_impersonating'))
            ->assertDontSee('role="img" aria-label="'.__('ticketing.ticket'), false)
            ->assertDontSee($shared, false)
            ->assertDontSee(route('tickets.pdf', $ticket), false);
    }
});

it('non trasforma in link il Markdown del messaggio del gestore ai prenotati', function (): void {
    $user = User::factory()->create();
    $booking = $this->service->reserve($user, $this->date, ['Ada'], (string) Str::uuid(), false);
    $message = BookingMessage::query()->create([
        'occurrence_id' => $this->date->id, 'sender_id' => $user->id,
        'subject' => '[Rimborso](https://evil.example/r)', 'body' => 'Leggi qui: [modulo](https://evil.example/m)',
        'include_waitlisted' => false, 'recipients_count' => 0,
    ]);

    $html = (string) (new BookingMessageSent($message->id, $booking->id))->toMail($user)->render();

    expect($html)->not->toContain('href="https://evil.example')
        ->and($html)->toContain('[modulo](https://evil.example/m)');
});

it('chiede l’email confermata per prenotare dal sito e dall’app, senza occupare posti', function (): void {
    $unverified = User::factory()->unverified()->create();

    $this->actingAs($unverified)->get(route('tickets.create', $this->date))->assertRedirect(route('verification.notice'));
    $this->actingAs($unverified)->post(route('tickets.store', $this->date), ($this->payload)())->assertRedirect(route('verification.notice'));

    Sanctum::actingAs($unverified);
    $this->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', ($this->payload)())
        ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');

    expect(Booking::query()->count())->toBe(0);
    Notification::assertNothingSent();

    // Con l'indirizzo confermato la stessa prenotazione passa.
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/occurrences/'.$this->date->id.'/bookings', ($this->payload)())->assertCreated();
});

it('non spedisce biglietti né riepiloghi da un account non confermato, ma lascia leggere e annullare', function (): void {
    $unverified = User::factory()->create();
    $booking = $this->service->reserve($unverified, $this->date, ['Giulia', 'Marco'], (string) Str::uuid(), false);
    $unverified->forceFill(['email_verified_at' => null])->save();
    Notification::fake();
    $ticket = $booking->tickets[1];

    $this->actingAs($unverified)->post(route('tickets.share', $ticket), ['email' => 'vittima@example.test', 'consent' => 1])->assertRedirect(route('verification.notice'));
    $this->actingAs($unverified)->post(route('tickets.resend', $booking))->assertRedirect(route('verification.notice'));

    Sanctum::actingAs($unverified);
    $this->postJson('/api/v1/me/tickets/'.$ticket->id.'/send', ['email' => 'vittima@example.test', 'consent' => true])
        ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
    $this->postJson('/api/v1/me/bookings/'.$booking->id.'/email')
        ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_VERIFICATION_REQUIRED');
    Notification::assertNothingSent();

    $this->getJson('/api/v1/me/bookings')->assertOk();
    $this->postJson('/api/v1/me/bookings/'.$booking->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('limita anche sul sito l’aggiunta dello staff per email, come nell’app', function (): void {
    $owner = User::factory()->create();
    $this->date->event->venue->members()->attach($owner, ['role' => 'owner']);
    $this->actingAs($owner);

    foreach (range(1, 30) as $i) {
        $this->post(route('ticketing.manage.staff', $this->date), ['email' => 'nessuno'.$i.'@example.test'])->assertSessionHasErrors('email');
    }
    $this->post(route('ticketing.manage.staff', $this->date), ['email' => 'altro@example.test'])->assertTooManyRequests();
});

it('non scrive nel codice la password degli account demo e la genera solo alla creazione', function (): void {
    expect(file_get_contents(database_path('seeders/TicketingDemoSeeder.php')))->not->toContain('DemoTicket-2026');

    $this->artisan('ticketing:demo --force')->expectsOutputToContain('gestore-ticket@example.test')->assertSuccessful();
    $emails = ['gestore-ticket@example.test', 'biglietti@example.test', 'attesa-ticket@example.test'];
    $hashes = User::query()->whereIn('email', $emails)->pluck('password', 'email');
    expect($hashes)->toHaveCount(3)
        ->and($hashes->unique())->toHaveCount(3);
    foreach ($hashes as $hash) {
        expect(Hash::check('DemoTicket-2026!', $hash))->toBeFalse();
    }

    // Una seconda esecuzione non tocca né mostra le password già date.
    $this->artisan('ticketing:demo --force')->doesntExpectOutputToContain('gestore-ticket@example.test')->assertSuccessful();
    expect(User::query()->whereIn('email', $emails)->pluck('password', 'email')->all())->toBe($hashes->all());
});
