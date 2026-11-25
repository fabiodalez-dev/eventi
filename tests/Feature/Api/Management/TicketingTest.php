<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\UserRole;
use App\Enums\VenueStatus;
use App\Models\Booking;
use App\Models\Organizer;
use App\Models\User;
use App\Models\Venue;
use App\Services\Ticketing\TicketingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

/*
 * La biglietteria completa dell'app (P3): impostazioni e domande,
 * annullamento a nome del locale, storico dei messaggi, CSV e PDF, ricerca
 * per locale e riquadro «Ticketing inCittà» dell'evento. Ogni rotta con la
 * griglia di permessi del piano: referente, collaboratore, proprietario e
 * membro dell'organizzazione, personale d'ingresso, estraneo, locale
 * sospeso, data di un altro soggetto.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->city = testCity();
    $this->category = testCategory();
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->id, 'name' => 'Circolo Aurora', 'ticketing_enabled' => true]);
    $this->date = occurrenceAt($this->city, $this->category, now()->addDays(3)->format('Y-m-d H:i:s'), now()->addDays(3)->addHours(3)->format('Y-m-d H:i:s'),
        ['booking_enabled' => true, 'booking_capacity' => 10, 'booking_limit' => 4], ['title' => 'Concerto di primavera'], $this->venue);
    $this->owner = User::factory()->create();
    $this->editor = User::factory()->create();
    $this->staff = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->venue->members()->attach($this->owner, ['role' => 'owner', 'invited_at' => now(), 'accepted_at' => now()]);
    $this->venue->members()->attach($this->editor, ['role' => 'editor', 'invited_at' => now(), 'accepted_at' => now()]);
    // Il ruolo globale dà il permesso sugli eventi, la riga del locale dice dove (come nei pannelli).
    $this->owner->assignRole(UserRole::VenueOwner->value);
    $this->editor->assignRole(UserRole::VenueEditor->value);
    $this->date->checkinStaff()->attach($this->staff);
    $this->base = '/api/v1/management/dates/'.$this->date->id;
});

function settingsPayload(array $overrides = []): array
{
    return [
        'booking_enabled' => true,
        'booking_capacity' => 40,
        'booking_limit' => 2,
        'booking_waitlist' => true,
        'booking_opens_at' => null,
        'booking_closes_at' => null,
        'cancellation_closes_at' => null,
        ...$overrides,
    ];
}

function organizerDate(object $test): array
{
    $orgOwner = User::factory()->create();
    $orgMember = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $test->city->id, 'owner_id' => $orgOwner->id, 'is_active' => true]);
    $organizer->users()->attach($orgMember);
    $date = occurrenceAt($test->city, $test->category, now()->addDays(5)->format('Y-m-d H:i:s'), null,
        ['booking_enabled' => true, 'booking_capacity' => 10, 'booking_limit' => 2], ['organizer_id' => $organizer->id], $test->venue);

    return [$orgOwner, $orgMember, $date];
}

it('salva impostazioni e domande con le ore della città, e risponde con la scheda aggiornata', function (): void {
    Sanctum::actingAs($this->owner);

    $response = $this->patchJson($this->base.'/settings', settingsPayload([
        'booking_opens_at' => '2026-12-01T10:00:00+01:00',
        'booking_closes_at' => '2026-12-04 20:00',
        'booking_instructions' => "Porta la tessera.\nSi entra dal cortile.",
        'booking_fields' => ['phone' => 'required', 'city' => 'optional'],
        'booking_questions' => [
            ['label' => 'Allergie?', 'type' => 'text', 'required' => true],
            ['label' => 'Turno', 'type' => 'choice', 'options' => "Primo\nSecondo"],
            ['label' => '', 'type' => 'text'],
        ],
    ]))->assertOk();

    $date = $this->date->fresh();
    expect($date->booking_capacity)->toBe(40)
        ->and($date->booking_waitlist)->toBeTrue()
        ->and($date->booking_opens_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-01 09:00')
        // Un'ora senza scarto è l'ora di Padova: le 20 di dicembre sono le 19 UTC.
        ->and($date->booking_closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-04 19:00')
        ->and($date->booking_questions)->toHaveCount(2);
    $response->assertJsonPath('data.settings.booking_capacity', 40)
        ->assertJsonPath('data.settings.booking_fields.phone', 'required')
        ->assertJsonPath('data.settings.booking_fields.address', 'hidden')
        ->assertJsonPath('data.settings.booking_questions.1.options', ['Primo', 'Secondo'])
        ->assertJsonPath('data.settings.booking_instructions', "Porta la tessera.\nSi entra dal cortile.")
        ->assertJsonPath('data.settings.booking_instructions_rich', false)
        ->assertJsonPath('data.settings.limits.questions', 5)
        ->assertJsonPath('data.timezone', 'Europe/Rome')
        ->assertJsonPath('data.booking_enabled', true)
        ->assertJsonPath('data.ended', false);
    expect(Activity::query()->where('log_name', 'ticketing')->where('description', 'configured')->where('causer_id', $this->owner->id)->exists())->toBeTrue();

    // Le istruzioni che mancano restano come sono: l'app non le manda se non le ha toccate.
    $this->patchJson($this->base.'/settings', settingsPayload(['booking_capacity' => 30]))->assertOk();
    expect($this->date->fresh()->booking_instructions)->toBe("Porta la tessera.\nSi entra dal cortile.")
        ->and($this->date->fresh()->booking_questions)->toHaveCount(2);
});

it('ferma le impostazioni con le regole del sito: campi, capienza sotto i posti presi, locale non abilitato', function (): void {
    Sanctum::actingAs($this->owner);
    $this->patchJson($this->base.'/settings', settingsPayload(['booking_limit' => 21, 'booking_opens_at' => '2026-12-05 10:00', 'booking_closes_at' => '2026-12-04 10:00']))
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['booking_limit', 'booking_closes_at']]]);
    $this->patchJson($this->base.'/settings', settingsPayload(['booking_questions' => [['label' => 'Turno', 'type' => 'choice', 'options' => 'Uno solo']]]))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['booking_questions.0.options']]]);

    app(TicketingService::class)->reserve(User::factory()->create(), $this->date, ['Ada', 'Bruno', 'Carla'], (string) Str::uuid(), false);
    $this->patchJson($this->base.'/settings', settingsPayload(['booking_capacity' => 2]))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['ticketing']]]);

    $this->venue->update(['ticketing_enabled' => false]);
    $this->patchJson($this->base.'/settings', settingsPayload())->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['ticketing']]]);
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.ticketing_enabled', false);
});

it('lascia le impostazioni a chi gestisce la data e a nessun altro', function (): void {
    foreach ([$this->editor, $this->staff, $this->stranger] as $user) {
        Sanctum::actingAs($user);
        $this->patchJson($this->base.'/settings', settingsPayload())->assertForbidden();
        $this->getJson($this->base.'/messages')->assertForbidden();
        $this->getJson($this->base.'/export.csv')->assertForbidden();
        $this->getJson($this->base.'/list.pdf')->assertForbidden();
    }

    [$orgOwner, $orgMember, $orgDate] = organizerDate($this);
    foreach ([$orgOwner, $orgMember] as $user) {
        Sanctum::actingAs($user);
        $this->patchJson('/api/v1/management/dates/'.$orgDate->id.'/settings', settingsPayload())->assertOk();
        // La data di un altro soggetto: l'organizzazione non gestisce quelle del locale.
        $this->patchJson($this->base.'/settings', settingsPayload())->assertForbidden();
    }

    // Il locale sospeso: il referente perde la biglietteria, come sul sito.
    $this->venue->update(['status' => VenueStatus::Suspended]);
    Sanctum::actingAs($this->owner);
    $this->patchJson($this->base.'/settings', settingsPayload())->assertForbidden();
    $this->getJson($this->base.'/messages')->assertForbidden();
});

it('mostra le impostazioni solo a chi gestisce, e a tutti lo stato delle prenotazioni', function (): void {
    Sanctum::actingAs($this->staff);
    $this->getJson($this->base)->assertOk()
        ->assertJsonMissingPath('data.settings')
        ->assertJsonPath('data.booking_enabled', true)
        ->assertJsonPath('data.ticketing_enabled', true);

    Sanctum::actingAs($this->owner);
    $this->getJson($this->base)->assertOk()->assertJsonPath('data.settings.booking_limit', 4)->assertJsonPath('data.settings.booking_opens_at', null);
});

it('annulla a nome del locale anche oltre il termine, una prenotazione intera o un biglietto, con il motivo', function (): void {
    $booker = User::factory()->create();
    $booking = app(TicketingService::class)->reserve($booker, $this->date, ['Ada', 'Bruno'], (string) Str::uuid(), false);
    $this->date->update(['cancellation_closes_at' => now()->subHour()]);

    // Chi ha prenotato non può più: il termine è passato.
    Sanctum::actingAs($booker);
    $this->postJson('/api/v1/me/bookings/'.$booking->id.'/cancel')->assertUnprocessable();

    Sanctum::actingAs($this->owner);
    $this->postJson($this->base.'/bookings/'.$booking->id.'/cancel', ['ticket_id' => $booking->tickets[1]->id])->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.tickets.1.status', 'cancelled')
        ->assertJsonPath('data.tickets.0.status', 'valid');

    $this->postJson($this->base.'/bookings/'.$booking->id.'/cancel', ['reason' => 'Serata annullata per maltempo'])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.cancellation_reason', 'Serata annullata per maltempo');
    expect($booking->fresh()->status)->toBe(BookingStatus::Cancelled);
});

it('annulla anche la prenotazione presa al banco, e solo dentro la sua data', function (): void {
    $guest = app(TicketingService::class)->reserveForGuest($this->date, ['Ospite uno'], ['note' => 'al telefono'], $this->owner);
    [, , $orgDate] = organizerDate($this);
    $other = app(TicketingService::class)->reserve(User::factory()->create(), $orgDate, ['Dario'], (string) Str::uuid(), false);

    Sanctum::actingAs($this->owner);
    $this->postJson($this->base.'/bookings/'.$guest->id.'/cancel', ['reason' => 'Doppia'])->assertOk()
        ->assertJsonPath('data.staff_booking', true)
        ->assertJsonPath('data.status', 'cancelled');
    // La prenotazione di un'altra data, passata con l'indirizzo di questa: non esiste.
    $this->postJson($this->base.'/bookings/'.$other->id.'/cancel')->assertNotFound();
    expect($other->fresh()->status)->toBe(BookingStatus::Confirmed);

    foreach ([$this->editor, $this->staff, $this->stranger] as $user) {
        Sanctum::actingAs($user);
        $this->postJson($this->base.'/bookings/'.$guest->id.'/cancel')->assertForbidden();
    }
});

it('elenca gli ultimi dieci messaggi con chi li ha mandati e il tetto del giorno', function (): void {
    $this->owner->update(['name' => 'Marta Referente']);
    $service = app(TicketingService::class);
    $service->reserve(User::factory()->create(), $this->date, ['Ada'], (string) Str::uuid(), false);
    $service->message($this->date, $this->owner, 'Porta la tessera', 'Ricordati la tessera ARCI.', false);
    $service->message($this->date, $this->owner, 'Orario', 'Si comincia alle 22.', true);

    Sanctum::actingAs($this->owner);
    $this->getJson($this->base.'/messages')->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.subject', 'Orario')
        ->assertJsonPath('data.0.include_waitlisted', true)
        ->assertJsonPath('data.1.body', 'Ricordati la tessera ARCI.')
        ->assertJsonPath('data.1.recipients_count', 1)
        ->assertJsonPath('data.1.sender', 'Marta Referente')
        ->assertJsonPath('meta.daily_limit', config()->integer('ticketing.messages.daily_limit'))
        ->assertJsonPath('meta.sent_last_24h', 2)
        ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
});

it('scarica il CSV dei partecipanti e la lista d’ingresso in PDF, con il registro attività', function (): void {
    app(TicketingService::class)->reserve(User::factory()->create(), $this->date, ['Ada Lovelace'], (string) Str::uuid(), false);
    Sanctum::actingAs($this->owner);

    $csv = $this->get($this->base.'/export.csv')->assertOk();
    expect($csv->headers->get('Content-Type'))->toContain('text/csv')
        ->and($csv->headers->get('Content-Disposition'))->toContain('partecipanti-'.$this->date->id.'.csv')
        ->and($csv->streamedContent())->toContain('Ada Lovelace');

    $pdf = $this->get($this->base.'/list.pdf')->assertOk();
    expect($pdf->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($pdf->headers->get('Content-Disposition'))->toContain('lista-ingressi-'.$this->date->id.'.pdf');

    expect(Activity::query()->where('log_name', 'ticketing')->whereIn('description', ['participants_exported', 'participants_printed'])->count())->toBe(2);
});

it('cerca le date anche nel nome del locale, come il riepilogo del sito', function (): void {
    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/management/dates?q=Aurora')->assertOk()->assertJsonPath('data.0.id', $this->date->id)
        ->assertJsonPath('data.0.booking_enabled', true)->assertJsonPath('data.0.ended', false);
    $this->getJson('/api/v1/management/dates?q=primavera')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/management/dates?q=Belzoni')->assertOk()->assertJsonCount(0, 'data');
    // I caratteri jolly si cercano per quello che sono.
    $this->getJson('/api/v1/management/dates?q=%25')->assertOk()->assertJsonCount(0, 'data');
});

it('mostra il riquadro «Ticketing inCittà» dell’evento con le date che si gestiscono', function (): void {
    $event = $this->date->event;
    $url = '/api/v1/management/venues/'.$this->venue->id.'/events/'.$event->id.'/ticketing';

    Sanctum::actingAs($this->owner);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.ticketing_enabled', true)
        ->assertJsonPath('data.dates_count', 1)
        ->assertJsonPath('data.dates.0.id', $this->date->id)
        ->assertJsonPath('data.dates.0.booking_enabled', true)
        ->assertJsonPath('data.dates.0.counts.capacity', 10);

    // Il collaboratore vede il riquadro, non le date: la biglietteria è del referente.
    Sanctum::actingAs($this->editor);
    $this->getJson($url)->assertOk()->assertJsonPath('data.dates_count', 1)->assertJsonCount(0, 'data.dates');

    // Fuori dal locale, o sotto un'organizzazione, non esiste.
    Sanctum::actingAs($this->stranger);
    $this->getJson($url)->assertNotFound();
    $other = occurrenceAt($this->city, $this->category, now()->addDays(2)->format('Y-m-d H:i:s'));
    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/management/venues/'.$this->venue->id.'/events/'.$other->event_id.'/ticketing')->assertNotFound();
    [$orgOwner, , $orgDate] = organizerDate($this);
    Sanctum::actingAs($orgOwner);
    $this->getJson('/api/v1/management/organizers/'.$orgDate->event->organizer_id.'/events/'.$orgDate->event_id.'/ticketing')->assertNotFound();
});

it('segnala la data finita, dove il sito toglie i moduli', function (): void {
    $past = occurrenceAt($this->city, $this->category, CarbonImmutable::now()->subDays(2)->format('Y-m-d H:i:s'), CarbonImmutable::now()->subDays(2)->addHours(2)->format('Y-m-d H:i:s'), [], [], $this->venue);
    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/management/dates/'.$past->id)->assertOk()->assertJsonPath('data.ended', true);
    expect(Booking::query()->count())->toBe(0);
});
