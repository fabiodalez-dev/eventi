<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PromotionMode;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Filament\Venue\Pages\Sponsorships;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Tests\Support\VenueIsolationScenario;

/*
 * Le sponsorizzazioni nel browser vero (D70): la redazione che crea una
 * campagna scegliendo prima il locale e poi l'evento, «tutti gli eventi» con un
 * obiettivo di visualizzazioni, la scheda del gestore con l'avanzamento, e la
 * card che conta una visualizzazione solo dopo un secondo sullo schermo.
 *
 * Playwright dichiara `navigator.webdriver` e «HeadlessChrome»: per vedere una
 * misura contata la pagina riceve `window.__sponsorshipCountAutomation = true`
 * e il browser un user-agent da persona (vedi `resources/js/sponsorship-metrics.js`
 * e `App\Support\Sponsorship\BotFilter`).
 */
const SPONSORSHIP_BROWSER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

function deliveryAdmin(): User
{
    (new RolesAndPermissionsSeeder)->run();
    $admin = User::factory()->create();
    $admin->assignRole(UserRole::Admin->value);

    return $admin;
}

/** Un evento pubblicato del locale, con una data fra `$days` giorni alle 21. */
function deliveryEvent(Venue $venue, string $title, int $days): Event
{
    $event = Event::factory()->create([
        'city_id' => $venue->city_id,
        'venue_id' => $venue->getKey(),
        'category_id' => testCategory()->getKey(),
        'status' => EventStatus::Published,
        'published_at' => CarbonImmutable::now('UTC')->subDay(),
        'title' => $title,
    ]);
    $start = CarbonImmutable::now('Europe/Rome')->addDays($days)->setTime(21, 0)->utc();

    EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => $start,
        'ends_at' => $start->addHours(2),
    ]);

    return $event;
}

/** Sceglie una voce in un `Select` di Filament cercabile, dalla sua etichetta. */
function choose(mixed $page, string $field, string $option): mixed
{
    $page->page()->locator('[data-field-wrapper] :text-is("'.$field.'")')->first()
        ->locator('xpath=ancestor::*[@data-field-wrapper][1]')
        ->locator('.fi-select-input-btn')->click(['timeout' => 5000]);
    $page->page()->locator('.fi-select-input-option:has-text("'.$option.'")')->first()->click(['timeout' => 5000]);

    return $page;
}

/** Aspetta che Livewire abbia risposto: la condizione in JavaScript diventa vera. */
function waitUntil(mixed $page, string $condition, float $seconds = 8.0): void
{
    for ($waited = 0.0; $waited < $seconds; $waited += 0.25) {
        if ($page->script('!!('.$condition.')') === true) {
            return;
        }

        $page->wait(0.25);
    }

    expect($page->script('!!('.$condition.')'))->toBeTrue();
}

function noHorizontalScroll(mixed $page): void
{
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
}

it('crea una campagna scegliendo prima il locale e poi uno dei suoi eventi', function (int $width): void {
    $admin = deliveryAdmin();
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Officina dei Portici', 'email' => 'officina@example.test']);
    $other = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Circolo Termarium']);
    $event = deliveryEvent($venue, 'Jazz in cortile', 3);
    deliveryEvent($other, 'Serata al Termarium', 2);

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);

    $page = visit(SponsorshipResource::getUrl('create'))->resize($width, 900)
        ->assertSee(__('sponsorships.wizard.fields.sponsor_type'))
        ->click(__('sponsorships.wizard.sponsor_types.venue'));
    choose($page, __('sponsorships.wizard.fields.venue'), 'Officina dei Portici');
    waitUntil($page, 'document.querySelector(\'input[id$=".advertiser_name"]\')?.value === "Officina dei Portici"');
    $page->click('Successivo');

    $page->assertSee(__('sponsorships.wizard.fields.scope'));
    $page->page()->locator('.fi-select-input-btn:has-text("'.__('sponsorships.picker.placeholder').'")')->first()->click(['timeout' => 5000]);
    $page->assertSee('Jazz in cortile')->assertDontSee('Serata al Termarium');
    $page->page()->locator('.fi-select-input-option:has-text("Jazz in cortile")')->first()->click(['timeout' => 5000]);
    noHorizontalScroll($page);
    $page->screenshot(filename: 'sponsorship-delivery-event-'.$width);
    $page->click('Successivo');

    $page->click(SponsorshipPlacement::ListTop->label())->click('Successivo');
    $page->assertSee(__('sponsorships.wizard.steps.payment'))->fill('input[id$=".amount_cents"]', '120');
    noHorizontalScroll($page);
    $page->click('Salva');
    waitUntil($page, '!location.pathname.endsWith("/create")', 12);

    $page->assertNoJavascriptErrors();
    $campaign = Sponsorship::query()->latest('id')->firstOrFail();
    expect($campaign->event_id)->toBe($event->getKey())
        ->and($campaign->placement)->toBe(SponsorshipPlacement::ListTop)
        ->and($campaign->advertiser_name)->toBe('Officina dei Portici')
        ->and($campaign->sponsorship_grant_id)->toBeNull();
})->with([1280, 390]);

it('crea «tutti gli eventi» di un locale con un obiettivo di visualizzazioni', function (): void {
    $admin = deliveryAdmin();
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Officina dei Portici', 'email' => 'officina@example.test']);
    $uno = deliveryEvent($venue, 'Jazz in cortile', 2);
    $due = deliveryEvent($venue, 'Quiz musicale', 6);

    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);

    $page = visit(SponsorshipResource::getUrl('create'))->resize(1280, 900)
        ->click(__('sponsorships.wizard.sponsor_types.venue'));
    choose($page, __('sponsorships.wizard.fields.venue'), 'Officina dei Portici');
    waitUntil($page, 'document.querySelector(\'input[id$=".advertiser_name"]\')?.value === "Officina dei Portici"');
    $page->click('Successivo')->assertSee(__('sponsorships.wizard.fields.scope'))
        ->click(__('sponsorships.wizard.scopes.all'))
        ->assertSee(__('sponsorships.wizard.all_events.lead'))
        ->click('Successivo')->assertSee(SponsorshipPlacement::ListTop->label())
        ->click(SponsorshipPlacement::ListTop->label())
        ->click(__('sponsorships.wizard.products.views'));
    waitUntil($page, 'document.querySelector(\'input[id$=".impressions_goal"]\')');
    $page->fill('input[id$=".impressions_goal"]', '300');
    $page->wait(1);
    $page->screenshot(filename: 'sponsorship-delivery-all-step3');
    $page->click('Successivo')->assertSee(__('sponsorships.wizard.summary.lead_grant'));
    $page->page()->locator('[id$=".complimentary"]')->first()->click(['timeout' => 5000]);
    waitUntil($page, 'document.querySelector(\'textarea[id$=".notes"]\')');
    $page->fill('textarea[id$=".notes"]', 'Patrocinio della rassegna');
    $page->wait(1);
    $page->screenshot(filename: 'sponsorship-delivery-all-step4');
    $page->click('Salva');
    waitUntil($page, 'location.pathname.includes("sponsorship-grants")', 12);

    $page->assertNoJavascriptErrors();
    $grant = SponsorshipGrant::query()->latest('id')->firstOrFail();
    expect($grant->mode)->toBe(PromotionMode::Automatic)
        ->and($grant->venue_id)->toBe($venue->getKey())
        ->and($grant->impressions_goal)->toBe(300)
        ->and($grant->pacing)->toBe(SponsorshipPacing::Even)
        ->and($grant->complimentary)->toBeTrue()
        ->and($grant->sponsorships()->pluck('event_id')->sort()->values()->all())->toBe([$uno->getKey(), $due->getKey()]);
});

it('mostra al gestore la barra e la frase dell avanzamento della sua abilitazione', function (int $width): void {
    $scenario = VenueIsolationScenario::make();
    $grant = SponsorshipGrant::factory()->automatic()->goal(3000)->create([
        'venue_id' => $scenario->venueA->getKey(),
        'starts_at' => CarbonImmutable::now('UTC')->subDays(5),
        'ends_at' => CarbonImmutable::now('UTC')->addDays(20),
    ]);
    $row = Sponsorship::factory()->forGrant($grant)->create([
        'city_id' => $scenario->venueA->city_id,
        'event_id' => $scenario->publishedEventA->getKey(),
    ]);
    $row->forceFill(['impressions' => 1240])->saveQuietly();

    $this->actingAs($scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($scenario->venueA);

    $page = visit(Sponsorships::getUrl(panel: 'venue', tenant: $scenario->venueA))->resize($width, 900)
        ->assertSee(__('sponsorship-panel.progress.goal_numbers', ['delivered' => '1.240', 'goal' => '3.000']));
    noHorizontalScroll($page);
    $page->screenshot(filename: 'sponsorship-delivery-manager-'.$width);
    $page->assertNoJavascriptErrors();
})->with([1280, 390]);

it('conta la card solo dopo un secondo sullo schermo e non chi scorre veloce', function (): void {
    $city = testCity();
    $venue = Venue::factory()->approved()->create(['city_id' => $city->getKey(), 'name' => 'Officina dei Portici']);
    $event = deliveryEvent($venue, 'Jazz in cortile', 3);
    $campaign = Sponsorship::factory()->placement(SponsorshipPlacement::HomeCard)->create([
        'city_id' => $city->getKey(),
        'event_id' => $event->getKey(),
        'advertiser_name' => 'Etichetta del cortile',
    ]);

    $page = visit('/')->withUserAgent(SPONSORSHIP_BROWSER_AGENT)->resize(390, 844);
    $card = '[data-sponsorship-placement="home_card"]';
    $page->assertPresent($card);

    /* La card parte fuori dallo schermo: il conto non comincia finché non entra. */
    expect($page->script('document.querySelector(\''.$card.'\').getBoundingClientRect().top > window.innerHeight'))->toBeTrue();
    $page->script('window.__sponsorshipCountAutomation = true; document.dispatchEvent(new CustomEvent("event-browser:updated"));');

    /* Passa davanti agli occhi per tre decimi di secondo e se ne va. */
    $page->script('document.querySelector(\''.$card.'\').scrollIntoView({block: "center"}); setTimeout(() => window.scrollTo(0, 0), 300);');
    $page->wait(1.6);
    expect($campaign->fresh()->impressions)->toBe(0);

    /* Resta: dopo un secondo conta, una volta sola. */
    $page->script('document.querySelector(\''.$card.'\').scrollIntoView({block: "center"});');
    $page->wait(2.5);
    expect($campaign->fresh()->impressions)->toBe(1);
    $page->wait(1.2);
    expect($campaign->fresh()->impressions)->toBe(1);
    $page->assertNoJavascriptErrors();
});
