<?php

declare(strict_types=1);

use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Sponsorships\Pages\CreateSponsorship;
use App\Filament\Admin\Resources\Sponsorships\Pages\EditSponsorship;
use App\Filament\Admin\Resources\Sponsorships\Pages\ListSponsorships;
use App\Filament\Admin\Resources\Sponsorships\Support\SponsorshipProgress;
use App\Filament\Admin\Resources\Sponsorships\Support\SponsorshipWizard;
use App\Models\Category;
use App\Models\City;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipGrant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

/**
 * Chi puo' toccare le campagne, e cosa succede quando le crea.
 *
 * La domanda che questi test tengono ferma non e' «funziona il modulo»: e'
 * **chi decide cosa compare a pagamento**. Un moderatore modera i contenuti
 * altrui; un referente di locale cura i propri eventi. Nessuno dei due compra
 * il posto in cima, ed e' una separazione che a schermo non si vede — un
 * evento sponsorizzato e uno in evidenza occupano lo stesso spazio.
 */
beforeEach(function (): void {
    /* I ruoli e i permessi non ci sono finche' non li si semina: il pannello
       li interroga a ogni pagina. */
    (new RolesAndPermissionsSeeder)->run();

    $this->city = testCity();
    $this->category = testCategory();
});

function persona(UserRole $ruolo): User
{
    $user = User::factory()->create();
    $user->assignRole($ruolo->value);

    return $user;
}

it('apre le campagne a un amministratore', function (): void {
    $this->actingAs(persona(UserRole::Admin))
        ->get('/admin/sponsorships')
        ->assertOk();
});

it('le tiene chiuse a un moderatore', function (): void {
    /* Non e' una svista: moderare i contenuti e decidere cosa si vende sono
       due mestieri, e §3 assegna al moderatore solo il primo. */
    $this->actingAs(persona(UserRole::Moderator))
        ->get('/admin/sponsorships')
        ->assertForbidden();
});

it('le tiene chiuse al referente di un locale', function (): void {
    $this->actingAs(persona(UserRole::VenueOwner))
        ->get('/admin/sponsorships')
        ->assertForbidden();
});

/*
 * Dal 20 novembre 2026 la creazione è un percorso in quattro passi
 * (`SponsorshipCreateWizardTest` lo prova passo per passo). Queste tre prove
 * tengono le promesse di prima sul percorso nuovo: la città viene dall'evento,
 * una finestra rovesciata non passa, il nome di chi paga è obbligatorio.
 * L'evento ha una data futura: il selettore propone solo eventi
 * sponsorizzabili, e una data di settembre oggi non lo è più.
 */
function campagnaEsterna(int $eventId, array $overrides = []): array
{
    return [
        'sponsor_type' => SponsorshipWizard::SPONSOR_EXTERNAL,
        'event_id' => $eventId,
        'placement' => SponsorshipPlacement::ListTop->value,
        'status' => SponsorshipStatus::Active->value,
        'starts_at' => CarbonImmutable::now()->subDay()->format('Y-m-d H:i:s'),
        'ends_at' => CarbonImmutable::now()->addWeek()->format('Y-m-d H:i:s'),
        'product' => SponsorshipWizard::PRODUCT_PERIOD,
        'advertiser_name' => 'Consorzio di prova',
        'amount_cents' => '100',
        'priority' => 0,
        'weight' => 1,
        ...$overrides,
    ];
}

function prossimaSerata(City $city, Category $category): EventOccurrence
{
    return occurrenceAtLocal($city, $category, CarbonImmutable::now($city->timezone)->addDays(5)->format('Y-m-d').' 21:00:00');
}

it('ricava la città dall evento, senza chiederla', function (): void {
    $occorrenza = prossimaSerata($this->city, $this->category);
    $admin = persona(UserRole::Admin);

    $this->actingAs($admin)->get('/admin/sponsorships/create')->assertOk();

    Livewire::actingAs($admin)
        ->test(CreateSponsorship::class)
        ->fillForm(campagnaEsterna($occorrenza->event_id))
        ->set('data.event_id', $occorrenza->event_id)
        ->assertSet('data.city_id', $this->city->getKey())
        ->call('create')
        ->assertHasNoFormErrors();

    $campagna = Sponsorship::query()->latest('id')->first();

    /* La citta' non e' un campo da scegliere: una campagna in una citta'
       diversa da quella del proprio evento e' un dato incoerente, non un caso
       da coprire. */
    expect($campagna->city_id)->toBe($this->city->getKey())
        ->and($campagna->created_by)->not->toBeNull();
});

it('rifiuta una finestra che finisce prima di cominciare', function (): void {
    $occorrenza = prossimaSerata($this->city, $this->category);
    $admin = persona(UserRole::Admin);

    $this->actingAs($admin)->get('/admin/sponsorships/create')->assertOk();

    Livewire::actingAs($admin)
        ->test(CreateSponsorship::class)
        ->fillForm(campagnaEsterna($occorrenza->event_id, [
            'starts_at' => CarbonImmutable::now()->addWeek()->format('Y-m-d H:i:s'),
            'ends_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
        ]))
        ->call('create')
        ->assertHasFormErrors(['ends_at' => 'after'])
        ->assertSee(__('sponsorships.admin.validation.ends_after_starts'));

    expect(Sponsorship::query()->count())->toBe(0);
});

it('pretende il nome di chi paga', function (): void {
    $occorrenza = prossimaSerata($this->city, $this->category);
    $admin = persona(UserRole::Admin);

    $this->actingAs($admin)->get('/admin/sponsorships/create')->assertOk();

    /* Senza committente l'etichetta pubblica direbbe «sponsorizzato» e basta,
       che e' mezza informazione: il nome e' spesso la parte che conta. */
    Livewire::actingAs($admin)
        ->test(CreateSponsorship::class)
        ->fillForm(campagnaEsterna($occorrenza->event_id, ['advertiser_name' => '']))
        ->call('create')
        ->assertHasFormErrors(['advertiser_name' => 'required']);

    expect(Sponsorship::query()->count())->toBe(0);
});

it('elenca le campagne con le loro misure', function (): void {
    $occorrenza = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');

    $campagna = Sponsorship::factory()->create([
        'city_id' => $this->city->getKey(),
        'event_id' => $occorrenza->event_id,
        'advertiser_name' => 'Birrificio del Piave',
    ]);

    $campagna->forceFill(['impressions' => 1000, 'clicks' => 25])->save();

    Livewire::actingAs(persona(UserRole::Admin))
        ->test(ListSponsorships::class)
        ->assertCanSeeTableRecords([$campagna])
        ->assertSee('Birrificio del Piave');
});

it('mostra nell elenco l avanzamento delle campagne e le filtra per stato', function (): void {
    $occorrenza = prossimaSerata($this->city, $this->category);
    $admin = persona(UserRole::Admin);

    $obiettivo = Sponsorship::factory()->goal(1000)->create([
        'event_id' => $occorrenza->event_id,
        'city_id' => $this->city->getKey(),
        'advertiser_name' => 'Con obiettivo',
        'starts_at' => CarbonImmutable::now('UTC')->subDays(2),
        'ends_at' => CarbonImmutable::now('UTC')->addDays(8),
    ]);
    $obiettivo->forceFill(['impressions' => 250])->save();

    $periodo = Sponsorship::factory()->create([
        'event_id' => $occorrenza->event_id,
        'city_id' => $this->city->getKey(),
        'placement' => SponsorshipPlacement::HomeCard,
        'advertiser_name' => 'A tempo',
    ]);

    $stato = app(SponsorshipProgress::class)->forSponsorship($obiettivo->fresh());

    expect($stato)->not->toBeNull()
        ->and($stato->goal)->toBe(1000)
        ->and($stato->delivered)->toBe(250)
        ->and($stato->state)->not->toBeNull();

    $this->actingAs($admin)->get('/admin/sponsorships')
        ->assertOk()
        ->assertSee(__('sponsorships.progress.column'))
        ->assertSee('250 di 1.000')
        ->assertSee($stato->stateLabel())
        ->assertSee(__('sponsorships.pacing_state.period'));

    Livewire::actingAs($admin)
        ->test(ListSponsorships::class)
        ->filterTable('pacing_state', 'period')
        ->assertCanSeeTableRecords([$periodo])
        ->assertCanNotSeeTableRecords([$obiettivo])
        ->filterTable('pacing_state', $stato->stateKey())
        ->assertCanSeeTableRecords([$obiettivo])
        ->assertCanNotSeeTableRecords([$periodo]);
});

it('mostra nella scheda consegnate di oggi, quota richiesta, giorni rimasti e giorno per giorno', function (): void {
    $occorrenza = prossimaSerata($this->city, $this->category);
    $admin = persona(UserRole::Admin);

    $campagna = Sponsorship::factory()->goal(600)->create([
        'event_id' => $occorrenza->event_id,
        'city_id' => $this->city->getKey(),
        'starts_at' => CarbonImmutable::now('UTC')->subDays(2),
        'ends_at' => CarbonImmutable::now('UTC')->addDays(20),
    ]);
    $campagna->forceFill(['impressions' => 40])->save();
    SponsorshipDailyStat::query()->create([
        'sponsorship_id' => $campagna->getKey(),
        'day' => CarbonImmutable::now($this->city->timezone)->toDateString(),
        'impressions' => 17,
        'clicks' => 1,
    ]);

    $this->actingAs($admin)->get('/admin/sponsorships/'.$campagna->getKey().'/edit')
        ->assertOk()
        ->assertSee(__('sponsorships.progress.heading'))
        ->assertSee(__('sponsorships.progress.delivered_today'))
        ->assertSee(__('sponsorships.progress.required_quota'))
        ->assertSee(__('sponsorships.progress.days_left'))
        ->assertSee(__('sponsorships.progress.daily'))
        ->assertSee(__('sponsorships.progress.sentence', ['delivered' => '40', 'goal' => '600', 'state' => mb_strtolower(app(SponsorshipProgress::class)->forSponsorship($campagna)->stateLabel())]))
        /* La fine vera è l'ultima data dell'evento, non la fine della campagna. */
        ->assertSee(__('sponsorships.progress.days_left_hint'));

    $unita = app(SponsorshipProgress::class)->forSponsorship($campagna);

    /* Giorno per giorno: l'atteso arriva fino a adesso, quindi la somma è
       l'atteso a oggi della barra (a meno degli arrotondamenti dei giorni). */
    $service = app(SponsorshipProgress::class);
    $righe = $service->daily($unita, [$campagna->getKey()], $service->forecast($this->city, $campagna->placement));

    expect(abs(array_sum(array_column($righe, 'expected')) - $unita->expected))->toBeLessThanOrEqual(count($righe))
        ->and(end($righe)['delivered'])->toBe(17);

    expect($unita->deliveredToday)->toBe(17)
        ->and($unita->daysLeft)->toBeLessThanOrEqual(6)
        ->and($unita->end->lessThan($campagna->ends_at))->toBeTrue();
});

it('nella scheda di una riga di abilitazione rimanda all abilitazione per obiettivo, peso e priorità', function (): void {
    $occorrenza = prossimaSerata($this->city, $this->category);
    $admin = persona(UserRole::Admin);
    $grant = SponsorshipGrant::factory()->automatic()->goal(900)->create(['venue_id' => $occorrenza->event->venue_id]);
    $riga = Sponsorship::factory()->forGrant($grant)->create(['event_id' => $occorrenza->event_id, 'city_id' => $this->city->getKey()]);

    $this->actingAs($admin)->get('/admin/sponsorships/'.$riga->getKey().'/edit')
        ->assertOk()
        ->assertSee(__('sponsorships.admin.help.delivery_on_grant'))
        ->assertSee(__('sponsorships.progress.events'));

    Livewire::actingAs($admin)
        ->test(EditSponsorship::class, ['record' => $riga->getKey()])
        ->assertFormFieldIsDisabled('impressions_goal')
        ->assertFormFieldIsDisabled('weight')
        ->assertFormFieldIsDisabled('priority')
        ->assertFormFieldIsDisabled('event_id');
});

it('lascia salvare una campagna il cui evento non è più sponsorizzabile', function (): void {
    $passata = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00');
    $admin = persona(UserRole::Admin);
    $campagna = Sponsorship::factory()->expired()->create([
        'event_id' => $passata->event_id,
        'city_id' => $this->city->getKey(),
        'advertiser_name' => 'Archivio',
    ]);

    $this->actingAs($admin)->get('/admin/sponsorships/'.$campagna->getKey().'/edit')->assertOk();

    Livewire::actingAs($admin)
        ->test(EditSponsorship::class, ['record' => $campagna->getKey()])
        ->fillForm(['notes' => 'Chiusa a settembre'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($campagna->fresh()->notes)->toBe('Chiusa a settembre');
});

it('non accetta un obiettivo di visualizzazioni sull apertura della home', function (): void {
    $occorrenza = prossimaSerata($this->city, $this->category);
    $admin = persona(UserRole::Admin);
    $campagna = Sponsorship::factory()->placement(SponsorshipPlacement::HomeHero)->create([
        'event_id' => $occorrenza->event_id,
        'city_id' => $this->city->getKey(),
    ]);

    $this->actingAs($admin)->get('/admin/sponsorships/'.$campagna->getKey().'/edit')->assertOk();

    Livewire::actingAs($admin)
        ->test(EditSponsorship::class, ['record' => $campagna->getKey()])
        ->fillForm(['impressions_goal' => 500])
        ->call('save')
        ->assertHasFormErrors(['impressions_goal' => 'prohibited_if']);

    expect($campagna->fresh()->impressions_goal)->toBeNull();
});
