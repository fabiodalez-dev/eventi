<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\PriceType;
use App\Enums\RecurrenceFrequency;
use App\Enums\Weekday;
use App\Filament\Venue\Resources\Events\Pages\EditEvent;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * §10.3 (duplica) e §10.4 (ricorrenze in linguaggio naturale), più la
 * pubblicazione dalla scheda.
 */
beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->venue = $this->scenario->venueA;
    $this->event = $this->scenario->publishedEventA;

    $this->event->update([
        'description' => 'Concerto con la banda del paese.',
        'price_type' => PriceType::Ticket,
        'price_min' => 8,
        'ticket_url' => 'https://esempio.test/biglietti',
    ]);
    $this->event->tags()->attach(Tag::factory()->create()->getKey());

    $this->actingAs($this->scenario->ownerA);

    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->venue);
});

function editEvent(Event $event): Testable
{
    return Livewire::test(EditEvent::class, ['record' => $event->getRouteKey()]);
}

it('duplica un evento con tutto compilato tranne le date', function (): void {
    editEvent($this->event)->callAction('duplicate');

    $copy = Event::query()
        ->where('venue_id', $this->venue->getKey())
        ->where('title', __('admin.notifications.duplicate_title', ['title' => $this->event->title]))
        ->sole();

    expect($copy->getKey())->not->toBe($this->event->getKey())
        ->and($copy->description)->toBe($this->event->description)
        ->and($copy->category_id)->toBe($this->event->category_id)
        ->and($copy->price_type)->toBe(PriceType::Ticket)
        ->and((float) $copy->price_min)->toBe(8.0)
        ->and($copy->ticket_url)->toBe($this->event->ticket_url)
        ->and($copy->tags()->count())->toBe(1)
        ->and($copy->venue_id)->toBe($this->venue->getKey())
        // Le date no: duplicare serve proprio a dargliene di nuove.
        ->and($copy->occurrences()->count())->toBe(0)
        ->and($copy->status)->toBe(EventStatus::Draft);
});

it('non lascia duplicare l’evento di un altro locale', function (): void {
    expect($this->scenario->ownerA->can('update', $this->scenario->publishedEventB))->toBeFalse()
        ->and($this->scenario->ownerA->can('create', [Event::class, $this->scenario->venueB]))->toBeFalse();
});

it('mette in calendario le date di una serie descritta a parole', function (): void {
    $thursday = CarbonImmutable::now('Europe/Rome')->next(Weekday::Thursday->isoNumber())->setTime(21, 0);

    $this->event->occurrences()->delete();

    $occurrence = new EventOccurrence([
        'event_id' => $this->event->getKey(),
        'starts_at' => $thursday->utc(),
        'is_all_day' => false,
        'status' => OccurrenceStatus::Scheduled,
        'is_exception' => false,
    ]);
    $occurrence->setRelation('event', $this->event);
    $occurrence->save();

    editEvent($this->event)->callAction('repeat', [
        'frequency' => RecurrenceFrequency::Biweekly->value,
        'weekdays' => [Weekday::Thursday->value],
        'until' => $thursday->addWeeks(6)->toDateString(),
    ]);

    $recurrence = $this->event->recurrences()->sole();

    // Il gestore ha detto "una settimana sì e una no, il giovedì": la stringa
    // RFC 5545 la scrive il sistema, e lui non la vede mai.
    expect($recurrence->rrule)->toBe('FREQ=WEEKLY;INTERVAL=2;BYDAY=TH')
        ->and($this->event->occurrences()->count())->toBe(4);
});

it('non aggiunge doppioni se la serie viene rigenerata', function (): void {
    $thursday = CarbonImmutable::now('Europe/Rome')->next(Weekday::Thursday->isoNumber())->setTime(21, 0);

    $this->event->occurrences()->delete();

    $occurrence = new EventOccurrence([
        'event_id' => $this->event->getKey(),
        'starts_at' => $thursday->utc(),
        'is_all_day' => false,
        'status' => OccurrenceStatus::Scheduled,
        'is_exception' => false,
    ]);
    $occurrence->setRelation('event', $this->event);
    $occurrence->save();

    $arguments = [
        'frequency' => RecurrenceFrequency::Weekly->value,
        'weekdays' => [Weekday::Thursday->value],
        'until' => $thursday->addWeeks(3)->toDateString(),
    ];

    editEvent($this->event)->callAction('repeat', $arguments);
    $first = $this->event->occurrences()->count();

    editEvent($this->event)->callAction('repeat', $arguments);

    expect($this->event->occurrences()->count())->toBe($first);
});

it('pubblica dalla scheda quando il locale pubblica da sé', function (): void {
    $this->venue->update(['auto_publish' => true]);
    $this->event->update(['status' => EventStatus::Draft]);

    editEvent($this->event)->callAction('publish');

    expect($this->event->refresh()->status)->toBe(EventStatus::Published);
});

it('manda alla redazione dalla scheda quando il locale non pubblica da sé', function (): void {
    $this->venue->update(['auto_publish' => false]);
    $this->event->update(['status' => EventStatus::Draft]);

    editEvent($this->event)->callAction('publish');

    expect($this->event->refresh()->status)->toBe(EventStatus::Pending);
});

it('non pubblica un evento senza date', function (): void {
    $this->venue->update(['auto_publish' => true]);
    $this->event->update(['status' => EventStatus::Draft]);
    $this->event->occurrences()->delete();

    editEvent($this->event)->assertActionDisabled('publish');
});
