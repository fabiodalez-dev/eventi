<?php

declare(strict_types=1);

use App\Actions\Account\FollowSubject;
use App\Actions\Account\SaveOccurrences;
use App\Enums\EventStatus;
use App\Enums\FollowableType;
use App\Enums\ImportSourceType;
use App\Exceptions\ImportException;
use App\Http\Middleware\CachePage;
use App\Models\Category;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\ImportSource;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\Scheduled\ScheduledMessage;
use App\Services\Import\CsvImportDriver;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Ticketing\AvailabilityAlerts;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Correzioni dell'audit di novembre su notifiche, cache delle pagine e import
 * dei calendari CSV.
 */
function eventoPubblicoConData(): array
{
    app()->setLocale('it');
    $city = City::factory()->padova()->create();
    $category = Category::factory()->create(['default_duration_minutes' => 180]);
    $venue = Venue::factory()->approved()->create(['city_id' => $city->id]);
    $event = Event::factory()->create(['city_id' => $city->id, 'category_id' => $category->id, 'venue_id' => $venue->id,
        'title' => 'Evento pubblico', 'status' => EventStatus::Published, 'published_at' => now()]);
    $date = EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => CarbonImmutable::parse('2026-09-05 19:00:00', 'UTC'), 'ends_at' => null]);

    return [$city, $event, $date];
}

it('non salva né ricorda a chi segue un evento le date nuove di un evento tornato in revisione', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    [, $event] = eventoPubblicoConData();
    $follower = User::factory()->create();
    app(FollowSubject::class)($follower, FollowableType::Event, $event->id);
    $event->update(['status' => EventStatus::Pending, 'title' => 'Titolo in revisione']);
    $new = EventOccurrence::factory()->create(['event_id' => $event->id, 'starts_at' => CarbonImmutable::parse('2026-09-10 19:00:00', 'UTC'), 'ends_at' => null]);

    expect(SavedEvent::query()->where('user_id', $follower->id)->where('occurrence_id', $new->id)->exists())->toBeFalse();
});

it('non manda il promemoria di una data salvata quando l\'evento non è più pubblico', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    [, $event, $date] = eventoPubblicoConData();
    $saver = User::factory()->create();
    app(SaveOccurrences::class)->one($saver, $date);
    $event->update(['status' => EventStatus::Draft, 'title' => 'Bozza ritirata']);

    Notification::fake();
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-04 19:00:00', 'UTC'));
    app(NotificationDispatcher::class)->run();

    Notification::assertNotSentTo($saver, ScheduledMessage::class, fn (ScheduledMessage $n): bool => str_contains($n->message->subject, 'Bozza ritirata'));
});

it('non avvisa che i biglietti sono tornati disponibili per un evento non più pubblicato', function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    [, $event, $date] = eventoPubblicoConData();
    $event->update(['status' => EventStatus::Draft]);

    expect(app(AvailabilityAlerts::class)->announceIfAvailable($date))->toBe(0);
});

it('mette nella chiave della cache la pagina delle recensioni', function (): void {
    $key = fn (string $url): string => app(CachePage::class)->key(Request::create($url));

    expect($key('/locali/bar-centrale'))->not->toBe($key('/locali/bar-centrale?recensioni=2'));
});

it('non mette in cache le pagine evento nemmeno con il prefisso della città', function (): void {
    config()->set('page_cache.enabled', true);
    [$city, $event] = eventoPubblicoConData();
    $url = route('city.events.show', ['city' => $city->slug, 'slug' => $event->slug], false);

    $this->get($url)->assertOk();
    expect($this->get($url)->headers->get('X-Page-Cache'))->toBeNull();
});

it('rifiuta un nome di file CSV che esce dalla cartella del locale con le barre rovesciate', function (): void {
    Storage::fake('local');
    $city = City::factory()->padova()->create();
    [$venueA, $venueB] = Venue::factory()->approved()->count(2)->create(['city_id' => $city->id])->all();
    Storage::disk('local')->put('imports/'.$venueB->id.'/altrui.csv', "title,start\nRiservato,2027-02-11 20:30\n");
    $source = new ImportSource(['city_id' => $city->id, 'venue_id' => $venueA->id, 'type' => ImportSourceType::Csv,
        'url' => CsvImportDriver::UPLOAD_PREFIX.'..\\'.$venueB->id.'\\altrui.csv']);

    expect(fn () => app(CsvImportDriver::class)->fetch($source))->toThrow(ImportException::class);
});
