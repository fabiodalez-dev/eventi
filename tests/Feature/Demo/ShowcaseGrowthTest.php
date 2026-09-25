<?php

declare(strict_types=1);

use App\Console\Commands\ShowcaseDemoCommand;
use App\Enums\BookingStatus;
use App\Enums\SavedVisibility;
use App\Models\Booking;
use App\Models\EventOccurrence;
use App\Models\EventPoll;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\Venue;
use App\Support\DeclaredCosts;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Le funzioni di crescita appese alla vetrina.
 *
 * Quello che conta qui non è il numero di righe scritte: è che ogni funzione
 * arrivi nello stato in cui si vede davvero — costi dichiarati per intero e a
 * metà, «ci vado» pubblici, quattordici giorni di condivisioni invece di una
 * colonna sola, e soprattutto una coda con un posto promosso, che è uno stato
 * a cui si arriva solo passando dalla sequenza vera.
 */
beforeEach(function (): void {
    config(['community.enabled' => true]);
    Notification::fake();
    $this->city = testCity();
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-24 10:00');
    $this->venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'ticketing_enabled' => true]);
    $this->people = User::factory()->count(9)->sequence(fn ($sequence) => [
        'email' => 'vetrina'.$sequence->index.'@'.ShowcaseDemoCommand::EMAIL_DOMAIN,
    ])->create(['city_id' => $this->city->getKey()]);

    /* Le date della vetrina: eventi riconoscibili dal prefisso, tutti in
       avanti — il comando lavora solo sul futuro, ed è la stessa ragione per
       cui una vetrina della settimana scorsa non si può mostrare. */
    $this->dates = collect(['2026-09-26 21:00', '2026-09-27 21:00', '2026-09-28 21:00', '2026-09-29 21:00', '2026-09-30 21:00'])
        ->map(function (string $inizio): EventOccurrence {
            $date = occurrenceAtLocal($this->city, $this->category, $inizio, venue: $this->venue);
            $date->event->forceFill(['source_ref' => ShowcaseDemoCommand::PREFIX.'test-'.$date->getKey(), 'is_demo' => true])->save();

            return $date;
        });

    /* Una replica della stessa serata: è ciò che rende possibile un
       sondaggio, che mette in fila le date di un evento solo. */
    $this->replica = EventOccurrence::factory()->create([
        'event_id' => $this->dates->last()->event_id,
        'starts_at' => localInstant($this->city, '2026-10-01 21:00')->utc(),
        'ends_at' => localInstant($this->city, '2026-10-01 23:00')->utc(),
        'doors_at' => null,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('dichiara i costi per intero, a metà e per niente', function (): void {
    $this->artisan('demo:crescita', ['city' => $this->city->slug])->assertSuccessful();

    $profili = EventOccurrence::query()->whereIn('id', $this->dates->pluck('id'))->orderBy('starts_at')->get()
        ->map(fn (EventOccurrence $date): ?array => DeclaredCosts::for($date));

    expect($profili[0]['complete'])->toBeTrue()
        ->and($profili[0]['total_cents'])->toBe(1700)
        ->and($profili[1]['complete'])->toBeFalse()
        ->and($profili[2])->toBeNull();

    // Le indicazioni pratiche ci sono anche dove non c'è un costo da dire.
    expect(EventOccurrence::query()->find($this->dates[2]->getKey())->practical_details['parking_type'])->toBe('free');
});

it('rende pubblici i «ci vado» e non ne crea di nuovi alla seconda esecuzione', function (): void {
    $this->artisan('demo:crescita', ['city' => $this->city->slug])->assertSuccessful();
    $primi = SavedEvent::query()->where('visibility', SavedVisibility::Public->value)->count();

    $this->artisan('demo:crescita', ['city' => $this->city->slug])->assertSuccessful();

    expect($primi)->toBeGreaterThan(0)
        ->and(SavedEvent::query()->where('visibility', SavedVisibility::Public->value)->count())->toBe($primi);
});

it('scrive quattordici giorni di condivisioni, non una colonna sola', function (): void {
    $this->artisan('demo:crescita', ['city' => $this->city->slug])->assertSuccessful();

    $giorni = DB::table('event_share_daily')->distinct()->count('date');
    expect($giorni)->toBe(14);
});

/**
 * Il posto promosso.
 *
 * Non si scrive: si ottiene riempiendo la capienza, mettendo qualcuno in coda
 * e annullando una prenotazione. Se un giorno la promozione smettesse di
 * scattare sull'annullamento, questo test lo direbbe — ed è l'unica cosa
 * della vetrina che vale la pena proteggere davvero.
 */
it('arriva a una coda con un posto promosso che scade', function (): void {
    $this->artisan('demo:crescita', ['city' => $this->city->slug])->assertSuccessful();

    $data = EventOccurrence::query()->whereIn('id', Booking::query()->select('occurrence_id'))->orderBy('starts_at')->firstOrFail();

    $promossa = Booking::query()->where('occurrence_id', $data->getKey())->whereNotNull('promotion_expires_at')->first();

    expect($promossa)->not->toBeNull()
        ->and($promossa->status)->toBe(BookingStatus::Confirmed)
        ->and($promossa->promotion_expires_at->isFuture())->toBeTrue()
        ->and(Booking::query()->where('occurrence_id', $data->getKey())->where('status', BookingStatus::Cancelled)->count())->toBe(1)
        ->and($data->checkinStaff()->count())->toBe(2);
});

it('apre un sondaggio con i voti di più persone', function (): void {
    $this->artisan('demo:crescita', ['city' => $this->city->slug])->assertSuccessful();

    $poll = EventPoll::query()->with('options.votes')->first();

    expect($poll)->not->toBeNull()
        ->and($poll->options->count())->toBeGreaterThanOrEqual(2)
        ->and($poll->options->sum(fn ($option): int => $option->votes->count()))->toBeGreaterThan(0)
        ->and($poll->isOpen())->toBeTrue();
});

it('toglie ciò che ha aggiunto e lascia in piedi la vetrina', function (): void {
    $this->artisan('demo:crescita', ['city' => $this->city->slug])->assertSuccessful();

    $this->artisan('demo:crescita', ['city' => $this->city->slug, '--purge' => true])->assertSuccessful();

    expect(SavedEvent::query()->where('visibility', SavedVisibility::Public->value)->count())->toBe(0)
        ->and(DB::table('event_share_daily')->count())->toBe(0)
        ->and(EventPoll::query()->count())->toBe(0)
        ->and(Booking::query()->count())->toBe(0)
        ->and(EventOccurrence::query()->whereNotNull('cost_breakdown')->count())->toBe(0)
        // Gli eventi e le persone della vetrina non li tocca: li ha creati un altro comando.
        ->and(EventOccurrence::query()->whereIn('id', $this->dates->pluck('id'))->count())->toBe(5)
        ->and(User::query()->where('email', 'like', '%@'.ShowcaseDemoCommand::EMAIL_DOMAIN)->count())->toBe(9);
});

it('si ferma se la vetrina non c’è', function (): void {
    User::query()->where('email', 'like', '%@'.ShowcaseDemoCommand::EMAIL_DOMAIN)->delete();

    $this->artisan('demo:crescita', ['city' => $this->city->slug])
        ->expectsOutputToContain('Esegui prima')
        ->assertExitCode(1);
});
