<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PromotionMode;
use App\Enums\SponsorshipPacing;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\SponsorshipGrants\SponsorshipGrantResource;
use App\Filament\Admin\Resources\Sponsorships\Pages\CreateSponsorship;
use App\Filament\Admin\Resources\Sponsorships\SponsorshipResource;
use App\Filament\Admin\Resources\Sponsorships\Support\SponsorshipCapacity;
use App\Filament\Admin\Resources\Sponsorships\Support\SponsorshipProgress;
use App\Filament\Admin\Resources\Sponsorships\Support\SponsorshipWizard;
use App\Filament\Forms\Components\EventPicker;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipPrice;
use App\Models\User;
use App\Models\Venue;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Il percorso in quattro passi di «Nuova sponsorizzazione».
 *
 * Le promesse che questi test tengono ferme: scelto un committente, il
 * selettore mostra solo i suoi eventi sponsorizzabili con locandina, titolo,
 * data e locale; si trova un evento anche scrivendo il nome del locale; eventi
 * con lo stesso titolo si distinguono; «tutti gli eventi» crea un'abilitazione
 * automatica con le sue righe; un obiettivo oltre lo spazio non si salva;
 * l'apertura si vende solo a periodo; ogni passo si ferma con un messaggio in
 * italiano; moderatore e referente restano fuori.
 *
 * Prima di ogni `Livewire::test` c'è la richiesta HTTP della pagina, come
 * vuole la nota sugli scope di Filament.
 */
beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));

    $this->city = testCity();
    $this->category = testCategory();
    $this->admin = User::factory()->create();
    $this->admin->assignRole(UserRole::Admin->value);

    $this->venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'name' => 'Officina dei Portici',
        'email' => 'officina@example.test',
        'website' => 'https://officina.example.test',
    ]);
    $this->other = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Circolo Termarium']);

    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(null);
});

/** Un evento pubblicato con una data fra `$days` giorni. */
function wizardEvent(Venue $venue, string $title, int $days = 3, array $event = []): Event
{
    $model = Event::factory()->create([
        'city_id' => $venue->city_id,
        'venue_id' => $venue->getKey(),
        'category_id' => testCategory()->getKey(),
        'status' => EventStatus::Published,
        'published_at' => CarbonImmutable::now('UTC'),
        'title' => $title,
        ...$event,
    ]);

    EventOccurrence::factory()->create([
        'event_id' => $model->getKey(),
        'starts_at' => CarbonImmutable::now('UTC')->addDays($days)->setTime(20, 0),
        'ends_at' => CarbonImmutable::now('UTC')->addDays($days)->setTime(23, 0),
        'doors_at' => null,
    ]);

    return $model;
}

/** Un evento con l'unica data già passata. */
function pastWizardEvent(Venue $venue, string $title): Event
{
    $model = Event::factory()->create([
        'city_id' => $venue->city_id,
        'venue_id' => $venue->getKey(),
        'category_id' => testCategory()->getKey(),
        'status' => EventStatus::Published,
        'title' => $title,
    ]);

    EventOccurrence::factory()->create([
        'event_id' => $model->getKey(),
        'starts_at' => CarbonImmutable::now('UTC')->subDays(10)->setTime(20, 0),
        'ends_at' => CarbonImmutable::now('UTC')->subDays(10)->setTime(23, 0),
        'doors_at' => null,
    ]);

    return $model;
}

/** I dati completi di una campagna singola, passo per passo. */
function singleCampaignData(Venue $venue, Event $event, array $overrides = []): array
{
    return [
        'sponsor_type' => SponsorshipWizard::SPONSOR_VENUE,
        'venue_id' => $venue->getKey(),
        'advertiser_name' => $venue->name,
        'scope' => SponsorshipWizard::SCOPE_EVENT,
        'event_id' => $event->getKey(),
        'city_id' => $event->city_id,
        'placement' => SponsorshipPlacement::ListTop->value,
        'starts_at' => '2026-11-21 08:00:00',
        'ends_at' => '2026-12-21 08:00:00',
        'product' => SponsorshipWizard::PRODUCT_PERIOD,
        'amount_cents' => '150.00',
        'status' => SponsorshipStatus::Active->value,
        'priority' => 0,
        'weight' => 1,
        ...$overrides,
    ];
}

function openWizard(): Testable
{
    test()->get(SponsorshipResource::getUrl('create'))->assertOk();

    return Livewire::test(CreateSponsorship::class);
}

/** Le opzioni del selettore dell'evento, come le vede chi apre la tendina. */
function pickerOptions(Testable $page, ?string $search = null): array
{
    $options = [];

    $page->assertFormFieldExists('event_id', function (Select $field) use (&$options, $search): bool {
        $options = $search === null ? $field->getOptions() : $field->getSearchResults($search);

        return true;
    });

    return $options;
}

it('ha quattro passi, nell ordine committente, cosa, dove e quanto, pagamento', function (): void {
    openWizard()
        ->assertWizardStepExists(1)
        ->assertWizardStepExists(4)
        ->assertSee(__('sponsorships.wizard.steps.sponsor'))
        ->assertSee(__('sponsorships.wizard.steps.what'))
        ->assertSee(__('sponsorships.wizard.steps.where'))
        ->assertSee(__('sponsorships.wizard.steps.payment'))
        ->assertWizardCurrentStep(1);
});

it('non va avanti dal primo passo senza il locale, e lo dice in italiano', function (): void {
    $page = openWizard()
        ->fillForm(['sponsor_type' => SponsorshipWizard::SPONSOR_VENUE, 'venue_id' => null, 'advertiser_name' => ''])
        ->goToWizardStep(2)
        ->assertHasFormErrors(['venue_id' => 'required', 'advertiser_name' => 'required'])
        ->assertWizardCurrentStep(1);

    $messages = collect($page->errors()->all())->implode(' ');

    expect($messages)->toContain('obbligatorio')
        ->and($messages)->not->toContain('field is required')
        ->and($messages)->not->toContain('Please fill');
});

it('compila nome e contatti del committente dal locale scelto, lasciandoli modificabili', function (): void {
    openWizard()
        ->fillForm(['sponsor_type' => SponsorshipWizard::SPONSOR_VENUE])
        ->set('data.venue_id', $this->venue->getKey())
        ->assertSet('data.advertiser_name', 'Officina dei Portici')
        ->assertSet('data.advertiser_email', 'officina@example.test')
        ->assertSet('data.advertiser_url', 'https://officina.example.test')
        ->set('data.advertiser_name', 'Birrificio del Piave')
        ->assertSet('data.advertiser_name', 'Birrificio del Piave');
});

it('mostra nel selettore solo gli eventi sponsorizzabili del locale scelto, con locandina, data e locale', function (): void {
    $mio = wizardEvent($this->venue, 'Jazz in cortile', 2);
    $altro = wizardEvent($this->other, 'Serata al Termarium', 2);
    $passato = pastWizardEvent($this->venue, 'Concerto di ieri');
    $bozza = wizardEvent($this->venue, 'Bozza segreta', 4, ['status' => EventStatus::Draft]);

    $page = openWizard()->fillForm(['sponsor_type' => SponsorshipWizard::SPONSOR_VENUE])->set('data.venue_id', $this->venue->getKey());
    $options = pickerOptions($page);

    expect(array_keys($options))->toBe([$mio->getKey()])
        ->and($options[$mio->getKey()])->toContain('Jazz in cortile')
        ->and($options[$mio->getKey()])->toContain('Officina dei Portici')
        ->and($options[$mio->getKey()])->toContain('<img')
        ->and($options[$mio->getKey()])->toContain('22 nov');

    /* Nemmeno la ricerca propone eventi passati, bozze o di altri locali. */
    expect(array_keys(pickerOptions($page, 'Concerto')))->toBe([])
        ->and(array_keys(pickerOptions($page, 'Bozza')))->toBe([])
        ->and(array_keys(pickerOptions($page, 'Termarium')))->toBe([]);

    expect($altro->exists && $passato->exists && $bozza->exists)->toBeTrue();
});

it('rifiuta un evento di un altro locale anche se arriva scritto a mano', function (): void {
    $altro = wizardEvent($this->other, 'Serata al Termarium', 2);

    openWizard()
        ->fillForm(['sponsor_type' => SponsorshipWizard::SPONSOR_VENUE, 'venue_id' => $this->venue->getKey(), 'advertiser_name' => 'Officina'])
        ->goToWizardStep(2)
        ->assertHasNoFormErrors()
        ->fillForm(['scope' => SponsorshipWizard::SCOPE_EVENT, 'event_id' => $altro->getKey()])
        ->goToWizardStep(3)
        ->assertHasFormErrors(['event_id'])
        ->assertWizardCurrentStep(2);
});

it('trova gli eventi scrivendo il nome del locale, per un committente esterno', function (): void {
    $mio = wizardEvent($this->venue, 'Jazz in cortile', 2);
    $secondo = wizardEvent($this->venue, 'Quiz musicale', 5);
    wizardEvent($this->other, 'Serata al Termarium', 2);

    $page = openWizard()->fillForm(['sponsor_type' => SponsorshipWizard::SPONSOR_EXTERNAL]);

    expect(array_keys(pickerOptions($page, 'Officina')))->toBe([$mio->getKey(), $secondo->getKey()])
        ->and(array_keys(pickerOptions($page, 'officina dei')))->toHaveCount(2);

    /* Il filtro per locale in testa restringe anche l'elenco iniziale. */
    $page->set('data.venue_filter', $this->other->getKey());

    expect(pickerOptions($page))->toHaveCount(1)
        ->and(implode(' ', pickerOptions($page)))->toContain('Serata al Termarium');
});

it('distingue quattro eventi con lo stesso titolo', function (): void {
    $terzo = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Sagunto']);
    $eventi = [
        wizardEvent($this->venue, 'Degustazione: dei', 2),
        wizardEvent($this->other, 'Degustazione: dei', 2),
        wizardEvent($terzo, 'Degustazione: dei', 6),
        wizardEvent($terzo, 'Degustazione: dei', 9),
    ];

    $options = pickerOptions(openWizard()->fillForm(['sponsor_type' => SponsorshipWizard::SPONSOR_EXTERNAL]), 'Degustazione');

    expect($options)->toHaveCount(4)
        ->and(array_unique(array_values($options)))->toHaveCount(4);

    foreach ($eventi as $event) {
        expect($options[$event->getKey()])->toContain(e($event->venue->name));
    }
});

it('segna nel selettore gli eventi già sponsorizzati', function (): void {
    $event = wizardEvent($this->venue, 'Jazz in cortile', 2);
    Sponsorship::factory()->create(['event_id' => $event->getKey(), 'city_id' => $this->city->getKey(), 'status' => SponsorshipStatus::Active, 'ends_at' => now()->addWeek()]);

    $options = pickerOptions(openWizard()->fillForm(['sponsor_type' => SponsorshipWizard::SPONSOR_VENUE])->set('data.venue_id', $this->venue->getKey()));

    expect($options[$event->getKey()])->toContain(__('sponsorships.picker.already_sponsored'));
});

it('crea una campagna singola su un evento passando dai quattro passi', function (): void {
    $event = wizardEvent($this->venue, 'Jazz in cortile', 2);

    openWizard()
        ->fillForm(['sponsor_type' => SponsorshipWizard::SPONSOR_VENUE])
        ->set('data.venue_id', $this->venue->getKey())
        ->goToWizardStep(2)->assertHasNoFormErrors()
        ->goToWizardStep(3)->assertHasFormErrors(['event_id' => 'required'])
        ->set('data.event_id', $event->getKey())
        ->assertSet('data.city_id', $this->city->getKey())
        ->goToWizardStep(3)->assertHasNoFormErrors()
        ->goToWizardStep(4)->assertHasFormErrors(['placement' => 'required'])
        ->set('data.placement', SponsorshipPlacement::HomeCard->value)
        ->goToWizardStep(4)->assertHasNoFormErrors()
        ->fillForm(['amount_cents' => '120.50', 'invoice_reference' => 'FT-12'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $campagna = Sponsorship::query()->latest('id')->firstOrFail();

    expect($campagna->event_id)->toBe($event->getKey())
        ->and($campagna->city_id)->toBe($this->city->getKey())
        ->and($campagna->placement)->toBe(SponsorshipPlacement::HomeCard)
        ->and($campagna->status)->toBe(SponsorshipStatus::Active)
        ->and($campagna->advertiser_name)->toBe('Officina dei Portici')
        ->and($campagna->advertiser_email)->toBe('officina@example.test')
        ->and($campagna->amount_cents)->toBe(12050)
        ->and($campagna->invoice_reference)->toBe('FT-12')
        ->and($campagna->impressions_goal)->toBeNull()
        ->and($campagna->sponsorship_grant_id)->toBeNull()
        ->and($campagna->created_by)->toBe($this->admin->getKey())
        ->and($campagna->ends_at->greaterThan($campagna->starts_at))->toBeTrue();
});

it('legge le date nel fuso della città dell evento', function (): void {
    $event = wizardEvent($this->venue, 'Jazz in cortile', 2);

    openWizard()
        ->fillForm(singleCampaignData($this->venue, $event))
        ->set('data.event_id', $event->getKey())
        ->fillForm(['starts_at' => '2026-11-21 21:30:00', 'ends_at' => '2026-12-21 21:30:00'])
        ->call('create')
        ->assertHasNoFormErrors();

    /* 21:30 a Padova in inverno sono le 20:30 UTC. */
    expect($this->city->timezone)->toBe('Europe/Rome')
        ->and(Sponsorship::query()->latest('id')->firstOrFail()->starts_at->utc()->format('H:i'))->toBe('20:30');
});

it('crea «tutti gli eventi» come abilitazione automatica con le sue righe', function (): void {
    $uno = wizardEvent($this->venue, 'Jazz in cortile', 2);
    $due = wizardEvent($this->venue, 'Quiz musicale', 6);
    pastWizardEvent($this->venue, 'Concerto di ieri');
    wizardEvent($this->other, 'Serata al Termarium', 2);

    $page = openWizard()
        ->fillForm(['sponsor_type' => SponsorshipWizard::SPONSOR_VENUE])
        ->set('data.venue_id', $this->venue->getKey())
        ->goToWizardStep(2)
        ->set('data.scope', SponsorshipWizard::SCOPE_ALL)
        ->assertSet('data.city_id', $this->city->getKey())
        ->assertSee(trans_choice('sponsorships.wizard.all_events.count', 2, ['count' => 2, 'sponsor' => 'Officina dei Portici']))
        ->assertSee(__('sponsorships.wizard.all_events.lead'))
        ->goToWizardStep(3)->assertHasNoFormErrors()
        ->set('data.placement', SponsorshipPlacement::ListTop->value)
        ->set('data.product', SponsorshipWizard::PRODUCT_VIEWS)
        ->goToWizardStep(4)->assertHasFormErrors(['impressions_goal' => 'required'])
        ->set('data.impressions_goal', 1000)
        ->set('data.pacing', SponsorshipPacing::Even->value)
        ->goToWizardStep(4)->assertHasNoFormErrors()
        ->fillForm(['amount_cents' => '300', 'paid_at' => '2026-11-20 09:00:00', 'payment_method' => 'Bonifico', 'payment_reference' => 'TUTTI-1'])
        ->assertSee(__('sponsorships.wizard.summary.lead_grant'))
        ->call('create')
        ->assertHasNoFormErrors();

    $grant = SponsorshipGrant::query()->latest('id')->firstOrFail();

    $page->assertRedirect(SponsorshipGrantResource::getUrl('edit', ['record' => $grant]));

    expect($grant->mode)->toBe(PromotionMode::Automatic)
        ->and($grant->venue_id)->toBe($this->venue->getKey())
        ->and($grant->placement)->toBe(SponsorshipPlacement::ListTop)
        ->and($grant->impressions_goal)->toBe(1000)
        ->and($grant->pacing)->toBe(SponsorshipPacing::Even)
        ->and($grant->amount_cents)->toBe(30000)
        ->and($grant->payment_reference)->toBe('TUTTI-1')
        ->and($grant->enabled)->toBeTrue()
        ->and($grant->created_by)->toBe($this->admin->getKey())
        ->and($grant->sponsorships()->pluck('event_id')->sort()->values()->all())->toBe([$uno->getKey(), $due->getKey()]);

    /* Nessuna campagna singola in più: le righe sono dell'abilitazione. */
    expect(Sponsorship::query()->whereNull('sponsorship_grant_id')->count())->toBe(0);
});

it('crea «tutti gli eventi» di un organizzatore, gratuita con il motivo', function (): void {
    $organizer = Organizer::query()->create(['city_id' => $this->city->getKey(), 'name' => 'Associazione Suoni', 'is_active' => true, 'email' => 'suoni@example.test']);
    $event = wizardEvent($this->other, 'Suoni al Termarium', 3, ['organizer_id' => $organizer->getKey()]);

    openWizard()
        ->fillForm(['sponsor_type' => SponsorshipWizard::SPONSOR_ORGANIZER])
        ->set('data.organizer_id', $organizer->getKey())
        ->assertSet('data.advertiser_name', 'Associazione Suoni')
        ->goToWizardStep(2)
        ->set('data.scope', SponsorshipWizard::SCOPE_ALL)
        ->goToWizardStep(3)
        ->set('data.placement', SponsorshipPlacement::HomeCard->value)
        ->goToWizardStep(4)
        ->set('data.complimentary', true)
        ->call('create')
        ->assertHasFormErrors(['notes' => 'required'])
        ->fillForm(['notes' => 'Patrocinio del Comune'])
        ->call('create')
        ->assertHasNoFormErrors();

    $grant = SponsorshipGrant::query()->latest('id')->firstOrFail();

    expect($grant->organizer_id)->toBe($organizer->getKey())
        ->and($grant->venue_id)->toBeNull()
        ->and($grant->complimentary)->toBeTrue()
        ->and($grant->amount_cents)->toBeNull()
        ->and($grant->notes)->toBe('Patrocinio del Comune')
        ->and($grant->sponsorships()->pluck('event_id')->all())->toBe([$event->getKey()]);
});

it('stima quanto può dare lo spazio e blocca un obiettivo oltre la stima', function (): void {
    $event = wizardEvent($this->venue, 'Jazz in cortile', 20);

    /* Dieci giorni di elenco senza dati: 200 al giorno dal valore di partenza. */
    $stima = app(SponsorshipCapacity::class)->estimate(
        $this->city,
        SponsorshipPlacement::ListTop,
        CarbonImmutable::parse('2026-11-21 00:00:00', 'Europe/Rome'),
        CarbonImmutable::parse('2026-12-01 00:00:00', 'Europe/Rome'),
    );

    expect($stima['days'])->toBe(0)
        ->and($stima['provisional'])->toBeTrue()
        ->and($stima['views'])->toBeGreaterThan(1900)->toBeLessThan(2100);

    $page = openWizard()
        ->fillForm(singleCampaignData($this->venue, $event, [
            /* Lo stato dei selettori di data è l'ora mostrata, cioè quella della città. */
            'starts_at' => '2026-11-21 00:00:00',
            'ends_at' => '2026-12-01 00:00:00',
            'product' => SponsorshipWizard::PRODUCT_VIEWS,
            'pacing' => SponsorshipPacing::Even->value,
            'impressions_goal' => 5000,
        ]))
        ->set('data.event_id', $event->getKey())
        ->assertSee(__('sponsorships.wizard.estimate.heading', ['views' => number_format($stima['views'], 0, ',', '.')]))
        ->assertSee(__('sponsorships.wizard.estimate.provisional'))
        ->assertSee(__('sponsorships.wizard.estimate.blocked'))
        ->call('create')
        ->assertHasFormErrors(['impressions_goal']);

    expect(Sponsorship::query()->count())->toBe(0);

    /* Sopra il 60% avvisa ma salva. */
    $page->set('data.impressions_goal', 1500)
        ->assertSee(__('sponsorships.wizard.estimate.warning', ['share' => number_format(1500 / $stima['views'] * 100, 0, ',', '.')]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Sponsorship::query()->latest('id')->firstOrFail()->impressions_goal)->toBe(1500);
});

it('non permette di vendere l apertura della home a visualizzazioni', function (): void {
    $event = wizardEvent($this->venue, 'Jazz in cortile', 2);

    $page = openWizard()
        ->fillForm(singleCampaignData($this->venue, $event, [
            'placement' => SponsorshipPlacement::HomeHero->value,
            'product' => SponsorshipWizard::PRODUCT_VIEWS,
            'impressions_goal' => 100,
            'pacing' => SponsorshipPacing::Even->value,
        ]))
        ->set('data.event_id', $event->getKey())
        ->assertSee(__('sponsorships.wizard.help.hero_period_only'))
        ->call('create')
        ->assertHasFormErrors(['product']);

    expect(Sponsorship::query()->count())->toBe(0);

    /* Scegliere l'apertura dopo aver scelto le visualizzazioni riporta a periodo. */
    $page->set('data.placement', SponsorshipPlacement::ListTop->value)
        ->set('data.product', SponsorshipWizard::PRODUCT_VIEWS)
        ->set('data.placement', SponsorshipPlacement::HomeHero->value)
        ->assertSet('data.product', SponsorshipWizard::PRODUCT_PERIOD);
});

it('non permette di vendere il foglio della mappa a visualizzazioni', function (): void {
    $event = wizardEvent($this->venue, 'Jazz in cortile', 2);

    $page = openWizard()
        ->fillForm(singleCampaignData($this->venue, $event, [
            'placement' => SponsorshipPlacement::MapSheet->value,
            'product' => SponsorshipWizard::PRODUCT_VIEWS,
            'impressions_goal' => 100,
            'pacing' => SponsorshipPacing::Even->value,
        ]))
        ->set('data.event_id', $event->getKey())
        ->assertSee(__('sponsorships.wizard.help.map_period_only'))
        ->call('create')
        ->assertHasFormErrors(['product']);

    expect(Sponsorship::query()->count())->toBe(0)
        ->and(SponsorshipPlacement::periodOnlyValues())->toBe(['home_hero', 'map_sheet'])
        ->and(SponsorshipWizard::periodOnlyMessage('list_top'))->toBeNull();

    $page->set('data.placement', SponsorshipPlacement::ListTop->value)
        ->set('data.product', SponsorshipWizard::PRODUCT_VIEWS)
        ->set('data.placement', SponsorshipPlacement::MapSheet->value)
        ->assertSet('data.product', SponsorshipWizard::PRODUCT_PERIOD);
});

it('dice chi occupa già la collocazione nello stesso periodo', function (): void {
    $event = wizardEvent($this->venue, 'Jazz in cortile', 2);
    $altro = wizardEvent($this->other, 'Serata al Termarium', 3);
    Sponsorship::factory()->create([
        'event_id' => $altro->getKey(),
        'city_id' => $this->city->getKey(),
        'placement' => SponsorshipPlacement::ListTop,
        'status' => SponsorshipStatus::Active,
        'advertiser_name' => 'Birrificio del Piave',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(10),
    ]);

    openWizard()
        ->fillForm(singleCampaignData($this->venue, $event))
        ->set('data.event_id', $event->getKey())
        ->assertSee('Birrificio del Piave')
        ->assertSee(trans_choice('sponsorships.wizard.occupants.lead', 1))
        ->set('data.placement', SponsorshipPlacement::MapSheet->value)
        ->assertDontSee('Birrificio del Piave')
        ->assertSee(__('sponsorships.wizard.occupants.empty'));
});

it('propone l importo dal listino quando cambiano collocazione o durata', function (): void {
    SponsorshipPrice::query()->create(['placement' => SponsorshipPlacement::HomeCard, 'months' => 1, 'amount_cents' => 9000, 'currency' => 'EUR', 'is_active' => true]);
    SponsorshipPrice::query()->create(['placement' => SponsorshipPlacement::HomeCard, 'months' => 3, 'amount_cents' => 24000, 'currency' => 'EUR', 'is_active' => true]);

    openWizard()
        ->set('data.placement', SponsorshipPlacement::HomeCard->value)
        ->assertSet('data.amount_cents', '90.00')
        ->set('data.months', 3)
        ->assertSet('data.amount_cents', '240.00');
});

it('arriva dalla scheda dell evento con committente ed evento già scelti', function (): void {
    $event = wizardEvent($this->venue, 'Jazz in cortile', 2);

    $this->get(SponsorshipResource::getUrl('create', ['event' => $event->getKey()]))->assertOk();

    Livewire::withQueryParams(['event' => $event->getKey()])
        ->test(CreateSponsorship::class)
        ->assertSet('data.sponsor_type', SponsorshipWizard::SPONSOR_VENUE)
        ->assertSet('data.venue_id', $this->venue->getKey())
        ->assertSet('data.event_id', $event->getKey())
        ->assertSet('data.city_id', $this->city->getKey())
        ->assertSet('data.advertiser_name', 'Officina dei Portici');
});

it('mostra il riepilogo in chiaro prima di salvare', function (): void {
    $event = wizardEvent($this->venue, 'Jazz in cortile', 2);

    openWizard()
        ->fillForm(singleCampaignData($this->venue, $event))
        ->set('data.event_id', $event->getKey())
        ->assertSee(__('sponsorships.wizard.summary.heading'))
        ->assertSee(__('sponsorships.wizard.summary.lead_single'))
        ->assertSee('Jazz in cortile')
        ->assertSee(SponsorshipPlacement::ListTop->label())
        ->assertSee(__('sponsorships.wizard.summary.period'));
});

it('tiene fuori moderatore e referente di un locale', function (UserRole $ruolo): void {
    $user = User::factory()->create();
    $user->assignRole($ruolo->value);

    $this->actingAs($user)->get(SponsorshipResource::getUrl('create'))->assertForbidden();
})->with([UserRole::Moderator, UserRole::VenueOwner]);

it('conta e mostra gli eventi di «tutti gli eventi» con la stessa regola del selettore', function (): void {
    wizardEvent($this->venue, 'Jazz in cortile', 2);
    wizardEvent($this->venue, 'Quiz musicale', 6);
    pastWizardEvent($this->venue, 'Concerto di ieri');

    $query = Event::query()->where('events.venue_id', $this->venue->getKey());

    expect(EventPicker::count($query))->toBe(2)
        ->and(array_map(fn (Event $e): string => $e->title, EventPicker::preview($query)))->toBe(['Jazz in cortile', 'Quiz musicale']);
});

it('giudica l obiettivo rispetto alla stima con le soglie del 60 e dell 80 per cento', function (int $goal, string $verdict): void {
    /* Oltre l'80% il motore non può consegnare (`quota_max`): l'unità sarebbe
       «a rischio» dal primo piano. Prima il blocco era al 100%. */
    expect(SponsorshipCapacity::verdict($goal, 1000))->toBe($verdict);
})->with([
    [600, 'ok'],
    [601, 'warning'],
    [800, 'warning'],
    [801, 'blocked'],
    [1000, 'blocked'],
]);

it('ferma la stima all ultima data dell evento scelto, come il motore', function (): void {
    $event = wizardEvent($this->venue, 'Jazz in cortile', 3);
    $inizio = CarbonImmutable::parse('2026-11-21 00:00:00', 'Europe/Rome');
    $fine = CarbonImmutable::parse('2026-12-11 00:00:00', 'Europe/Rome');
    $ultima = SponsorshipCapacity::lastCoveredFor($event->getKey(), SponsorshipPlacement::ListTop);
    $capacita = app(SponsorshipCapacity::class);

    $periodo = $capacita->estimate($this->city, SponsorshipPlacement::ListTop, $inizio, $fine);
    $evento = $capacita->estimate($this->city, SponsorshipPlacement::ListTop, $inizio, $fine, lastCovered: $ultima);

    expect($ultima)->not->toBeNull()
        ->and($evento['views'])->toBeLessThan($periodo['views'])
        ->and($evento['views'])->toBe((int) floor(app(SponsorshipProgress::class)
        ->forecast($this->city, SponsorshipPlacement::ListTop, CarbonImmutable::now('UTC'))
        ->between((CarbonImmutable::now('UTC')->greaterThan($inizio) ? CarbonImmutable::now('UTC') : $inizio)->setTimezone('Europe/Rome'), $ultima->setTimezone('Europe/Rome'))));
});

it('elenca i committenti per nome, solo i locali approvati', function (): void {
    Venue::factory()->create(['city_id' => $this->city->getKey(), 'name' => 'Officina sospesa', 'status' => 'suspended']);

    expect(SponsorshipWizard::venueOptions('Officina'))->toBe([$this->venue->getKey() => 'Officina dei Portici'])
        ->and(SponsorshipWizard::venueOptions('Officina', null, approvedOnly: false))->toHaveCount(2)
        ->and(City::query()->count())->toBe(1);
});

it('tiene lo stesso istante di inizio e fine quando la scelta dell evento porta il fuso della città', function (): void {
    $event = wizardEvent($this->venue, 'Jazz in cortile', 2);

    /* All'apertura la città non c'è ancora: le date proposte sono «adesso» e
       «fra un mese», alle 10:00 UTC. Scelto l'evento il fuso diventa quello di
       Padova, e gli istanti non devono spostarsi di un'ora. */
    openWizard()
        ->set('data.venue_id', $this->venue->getKey())
        ->set('data.event_id', $event->getKey())
        ->set('data.placement', SponsorshipPlacement::ListTop->value)
        ->fillForm(['amount_cents' => '50'])
        ->call('create')
        ->assertHasNoFormErrors();

    $campagna = Sponsorship::query()->latest('id')->firstOrFail();

    expect($campagna->starts_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-20 10:00')
        ->and($campagna->ends_at->utc()->format('Y-m-d H:i'))->toBe('2026-12-20 10:00');
});
