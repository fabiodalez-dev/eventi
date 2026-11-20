<?php

declare(strict_types=1);

use App\Models\Follow;
use App\Models\User;
use App\Models\Venue;
use App\Services\Calendar\MonthCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Il calendario del mese nella pagina iniziale: generale per chi non è
 * collegato, secondo gli interessi per chi lo è, mai nella cache di pagina.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

/** La sezione del calendario, oppure null. */
function homeCalendar(string $html): ?DOMElement
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $node = (new DOMXPath($dom))->query('//section[@data-home-calendar]')->item(0);

    return $node instanceof DOMElement ? $node : null;
}

it('disegna il calendario del mese subito sotto l\'apertura', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-12 12:00:00');
    occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00:00', event: ['title' => 'Concerto di stasera']);

    $html = (string) $this->get('/')->assertOk()->getContent();
    $section = homeCalendar($html);

    expect($section)->not->toBeNull()
        ->and($section->getAttribute('data-calendar-mode'))->toBe('city')
        ->and(strpos($html, 'data-home-calendar'))->toBeGreaterThan(strpos($html, 'home-opening'))
        ->and(strpos($html, 'data-home-calendar'))->toBeLessThan(strpos($html, 'home-stats'))
        ->and($html)->toContain(route('events.date', ['date' => '2026-09-12']))
        ->and($html)->toContain('aria-current="date"')
        ->and($html)->toContain('month-calendar--compact');
});

it('non disegna il calendario quando nel mese non c\'è niente', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-12 12:00:00');
    occurrenceAtLocal($city, testCategory(), '2026-10-20 21:00:00');

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect(homeCalendar($html))->toBeNull();
});

it('collega solo i giorni che hanno eventi', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00:00');

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect($html)->toContain(route('events.date', ['date' => '2026-09-12']))
        ->and($html)->not->toContain(route('events.date', ['date' => '2026-09-13']));
});

it('segue gli interessi di chi è collegato', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');
    $category = testCategory();
    $followed = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);

    occurrenceAtLocal($city, $category, '2026-09-12 21:00:00', venue: $followed);
    occurrenceAtLocal($city, $category, '2026-09-20 21:00:00');

    $user = User::factory()->create();
    Follow::factory()->forVenue($followed)->create(['user_id' => $user->getKey()]);

    $html = (string) $this->actingAs($user)->get('/')->assertOk()->getContent();
    $section = homeCalendar($html);

    expect($section?->getAttribute('data-calendar-mode'))->toBe('personal')
        ->and($html)->toContain(route('events.date', ['date' => '2026-09-12']))
        ->and($html)->not->toContain(route('events.date', ['date' => '2026-09-20']));

    /* Chi non è collegato vede tutto il mese. */
    auth()->logout();
    $guest = (string) $this->get('/')->assertOk()->getContent();

    expect(homeCalendar($guest)?->getAttribute('data-calendar-mode'))->toBe('city')
        ->and($guest)->toContain(route('events.date', ['date' => '2026-09-20']));
});

it('ripiega sul calendario della città quando gli interessi questo mese non portano a niente', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00:00');

    $user = User::factory()->create();
    Follow::factory()->forVenue(Venue::factory()->approved()->create(['city_id' => $city->getKey()]))->create(['user_id' => $user->getKey()]);

    $html = (string) $this->actingAs($user)->get('/')->assertOk()->getContent();

    expect(homeCalendar($html)?->getAttribute('data-calendar-mode'))->toBe('fallback')
        ->and($html)->toContain(route('events.date', ['date' => '2026-09-12']))
        ->and($html)->toContain(route('account.content-preferences'));
});

it('toglie dal calendario le categorie che la persona ha nascosto', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');
    $hidden = testCategory(['slug' => 'nascosta', 'name' => 'Nascosta']);
    $visible = testCategory(['slug' => 'visibile', 'name' => 'Visibile']);

    occurrenceAtLocal($city, $hidden, '2026-09-12 21:00:00');
    occurrenceAtLocal($city, $visible, '2026-09-20 21:00:00');

    $user = User::factory()->create(['content_preferences' => ['mode' => 'all', 'hidden_categories' => [$hidden->getKey()]]]);

    $html = (string) $this->actingAs($user)->get('/')->assertOk()->getContent();

    expect(homeCalendar($html)?->getAttribute('data-calendar-mode'))->toBe('filtered')
        ->and($html)->not->toContain(route('events.date', ['date' => '2026-09-12']))
        ->and($html)->toContain(route('events.date', ['date' => '2026-09-20']));
});

it('non lascia le preferenze di una persona nel calendario in cache degli altri', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');
    $hidden = testCategory(['slug' => 'nascosta', 'name' => 'Nascosta']);
    occurrenceAtLocal($city, $hidden, '2026-09-12 21:00:00');

    $user = User::factory()->create(['content_preferences' => ['mode' => 'all', 'hidden_categories' => [$hidden->getKey()]]]);

    /* La persona collegata apre per prima il calendario generale. */
    $this->actingAs($user)->get('/calendario')->assertOk();
    auth()->logout();

    $mese = CarbonImmutable::create(2026, 9, 1, 0, 0, 0, $city->timezone);

    expect(app(MonthCalendar::class)->digest($city, $mese))->toHaveKey('2026-09-12');
    $this->get('/calendario')->assertOk()->assertSee(route('events.date', ['date' => '2026-09-12']), escape: false);
});

it('fa lo stesso numero di query con pochi o tanti eventi nel mese', function (): void {
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');
    $category = testCategory();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey()]);
    $user = User::factory()->create();
    Follow::factory()->forVenue($venue)->create(['user_id' => $user->getKey()]);
    $calendar = app(MonthCalendar::class);
    $mese = CarbonImmutable::create(2026, 9, 1, 0, 0, 0, $city->timezone);

    occurrenceAtLocal($city, $category, '2026-09-12 21:00:00', venue: $venue);

    $count = function () use ($calendar, $city, $mese, $user): int {
        /* Ogni misura come una richiesta nuova: gli interessi si leggono una
           volta per richiesta (`RequestMemo`). */
        app()->instance('request', Request::create('/'));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $calendar->personalGrid($city, $mese, $user);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count();

    foreach (range(13, 28) as $giorno) {
        occurrenceAtLocal($city, $category, '2026-09-'.$giorno.' 21:00:00', venue: $venue);
    }

    expect($count())->toBe($few)->and($few)->toBeLessThanOrEqual(3);
});

it('non mette mai in cache la pagina con il calendario personale', function (): void {
    config(['page_cache.enabled' => true]);
    $city = testCity();
    freezeLocal($city, '2026-09-05 12:00:00');
    occurrenceAtLocal($city, testCategory(), '2026-09-12 21:00:00');

    $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertHeaderMissing('X-Page-Cache');
});
