<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Events\Pages\EditEvent;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

it('configures ticket limits and booking windows directly from the event', function (): void {
    $s = VenueIsolationScenario::make();
    $s->venueA->update(['ticketing_enabled' => true]);
    $s->publishedEventA->update(['price_type' => 'free']);
    $date = $s->publishedEventA->occurrences()->firstOrFail();
    $this->actingAs($s->admin);
    Filament::setCurrentPanel('admin');
    Livewire::test(EditEvent::class, ['record' => $s->publishedEventA->slug])
        ->callAction(TestAction::make('configureTickets'.$date->id)->schemaComponent(), [
            'booking_enabled' => true, 'booking_capacity' => 35, 'booking_limit' => 1,
            'booking_waitlist' => true, 'booking_opens_at' => '2026-09-14 09:00:00',
            'booking_closes_at' => '2026-09-15 19:00:00',
            'booking_instructions' => 'Mostra il QR all’ingresso.',
        ])->assertHasNoActionErrors();
    $date->refresh();
    expect($date->booking_enabled)->toBeTrue()->and($date->booking_capacity)->toBe(35)
        ->and($date->booking_limit)->toBe(1)->and($date->booking_waitlist)->toBeTrue()
        ->and($date->booking_opens_at->utc()->format('H:i'))->toBe('07:00');
});
