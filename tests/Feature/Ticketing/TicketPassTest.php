<?php

use App\Enums\AdmissionStatus;
use App\Http\Resources\V1\BookingResource;
use App\Models\User;
use App\Services\Ticketing\TicketingService;
use App\Services\Ticketing\TicketPdf;
use App\Services\Ticketing\TicketSharing;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * Il biglietto disegnato come un biglietto: serata, data in grande, nome di
 * chi entra, matrice col QR. Sulla scheda della prenotazione, sulla pagina
 * condivisa e nel PDF.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(now()->setDate(2026, 9, 5)->setTime(12, 0));
    (new RolesAndPermissionsSeeder)->run();
    $this->date = occurrenceAt(testCity(), testCategory(), '2026-09-06 18:00:00', '2026-09-06 22:00:00', [
        'booking_enabled' => true,
        'booking_limit' => 4,
        'booking_instructions' => "<p>👉 Porte aperte alle 20.45\nIngresso dal cortile</p><p>✅ Portate la tessera</p>",
    ]);
    $this->date->event->venue->update(['ticketing_enabled' => true]);
    $this->user = User::factory()->create();
    $this->booking = app(TicketingService::class)->reserve($this->user, $this->date, ['Giulia Pennacchiotti', 'Marco Zanellonzi'], (string) Str::uuid(), false);
});

it('shows each ticket as a pass with the evening, the holder and the QR', function (): void {
    $html = $this->actingAs($this->user)->get(route('tickets.show', $this->booking))->assertOk()->getContent();

    expect(substr_count($html, 'data-ticket-pass'))->toBe(2)
        ->and($html)->toContain('Giulia Pennacchiotti', 'Marco Zanellonzi')
        ->and($html)->toContain(e(__('ticketing.pass.of', ['position' => 1, 'total' => 2])), e(__('ticketing.pass.of', ['position' => 2, 'total' => 2])))
        ->and($html)->toContain(e($this->date->event->title))
        ->and($html)->toContain(str_pad((string) $this->booking->tickets->first()->id, 6, '0', STR_PAD_LEFT))
        ->and(substr_count($html, 'class="ticket-pass__qr"'))->toBe(2);
});

it('keeps the pass but hides the QR of a ticket that is no longer valid', function (): void {
    $this->booking->tickets->first()->update(['status' => AdmissionStatus::Cancelled, 'cancelled_at' => now()]);

    $html = $this->actingAs($this->user)->get(route('tickets.show', $this->booking))->assertOk()->getContent();

    expect(substr_count($html, 'data-ticket-pass'))->toBe(2)
        ->and(substr_count($html, 'class="ticket-pass__qr"'))->toBe(1)
        ->and($html)->toContain('ticket-pass--inactive');
});

it('shows the shared ticket as the same pass, with the entry instructions on their own lines', function (): void {
    $ticket = $this->booking->tickets->first();
    $url = app(TicketSharing::class)->url($ticket);

    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->toContain('data-ticket-pass', 'Giulia Pennacchiotti', 'class="ticket-pass__qr"')
        ->and($html)->not->toContain('Marco Zanellonzi')
        ->and($html)->toContain(e(__('ticketing.pass.instructions')))
        ->and($html)->toContain("Porte aperte alle 20.45\nIngresso dal cortile\n\n✅ Portate la tessera");
});

it('prints the PDF with the instructions line by line and without emoji DejaVu cannot draw', function (): void {
    $ticket = $this->booking->tickets->first()->load('booking.tickets', 'booking.occurrence.event.city');
    $html = view('ticketing.pdf', ['ticket' => $ticket, 'booking' => BookingResource::toArray($ticket->booking), 'poster' => null])->render();

    expect($html)->toContain('Giulia Pennacchiotti', e(__('ticketing.pass.of', ['position' => 1, 'total' => 2])))
        ->and($html)->toContain('Porte aperte alle 20.45<br />')
        ->and($html)->toContain('Portate la tessera')
        ->and($html)->not->toContain('👉')
        ->and($html)->not->toContain('✅')
        ->and(app(TicketPdf::class)->render($ticket))->toStartWith('%PDF');
});

it('downloads the PDF only for the person who booked', function (): void {
    $ticket = $this->booking->tickets->first();

    $this->actingAs($this->user)->get(route('tickets.pdf', $ticket))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="inCitta-ticket-'.$ticket->id.'.pdf"');
    $this->actingAs(User::factory()->create())->get(route('tickets.pdf', $ticket))->assertForbidden();
});
