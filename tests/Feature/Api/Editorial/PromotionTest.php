<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\SponsorshipStatus;
use App\Enums\UserRole;
use App\Models\City;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipOrder;
use App\Models\SponsorshipPrice;
use App\Models\User;
use App\Models\Venue;
use App\Notifications\SponsorshipOrderDecided;
use App\Services\Sponsorship\Payment\PaymentGateway;
use App\Settings\SponsorshipBannerSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\FakePaymentGateway;
use Tests\Support\VenueIsolationScenario;

/*
 * R8 — Promozione nell'app della redazione (R-SP-01…15). La griglia di ogni
 * area: moderatore, admin, super admin, referente di un locale, utente
 * comune, token non sbloccato, password non recente sulle azioni
 * pericolose. Le regole sono quelle del pannello: la promozione è solo di
 * admin e super admin (il moderatore riceve 403, nel pannello la voce non
 * c'è), le campagne si creano con i quattro passi e le loro regole, gli
 * ordini si approvano o si rifiutano con `SponsorshipOrders`.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));
    $this->gateway = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $this->gateway);

    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->eventA = r8Event($this->s->venueA, 'Jazz in cortile', 3);
    $this->eventB = r8Event($this->s->venueB, 'Teatro di sera', 5);
});

/** Un evento pubblicato con una data fra `$days` giorni: sponsorizzabile. */
function r8Event(Venue $venue, string $title, int $days, array $attributes = []): Event
{
    $event = Event::factory()->create([
        'city_id' => $venue->city_id,
        'venue_id' => $venue->getKey(),
        'category_id' => test()->s->category->getKey(),
        'status' => EventStatus::Published,
        'published_at' => CarbonImmutable::now('UTC'),
        'title' => $title,
        ...$attributes,
    ]);
    EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => CarbonImmutable::now('UTC')->addDays($days)->setTime(20, 0),
        'ends_at' => CarbonImmutable::now('UTC')->addDays($days)->setTime(23, 0),
        'doors_at' => null,
    ]);

    return $event;
}

/** Le richieste con il token di una persona. */
function r8(User $user, bool $unlocked = true, int $passwordMinutesAgo = 0): mixed
{
    return EditorialSession::as(test(), EditorialSession::token($user, unlocked: $unlocked, passwordMinutesAgo: $passwordMinutesAgo));
}

/** Un ordine nello stato indicato, del locale A. */
function r8Order(SponsorshipOrderStatus $status, array $attributes = []): SponsorshipOrder
{
    return SponsorshipOrder::query()->forceCreate([
        'venue_id' => test()->s->venueA->getKey(),
        'user_id' => test()->s->ownerA->getKey(),
        'placement' => SponsorshipPlacement::ListTop->value,
        'mode' => PromotionMode::Automatic->value,
        'months' => 1,
        'amount_cents' => 15000,
        'currency' => 'EUR',
        'status' => $status->value,
        ...$attributes,
    ]);
}

/** I dati completi di «Nuova sponsorizzazione» per un evento del locale A. */
function r8Wizard(array $overrides = []): array
{
    return [
        'sponsor_type' => 'venue',
        'venue_id' => test()->s->venueA->getKey(),
        'advertiser_name' => 'Circolo Aurora',
        'advertiser_email' => 'aurora@example.test',
        'scope' => 'event',
        'event_id' => test()->eventA->getKey(),
        'placement' => SponsorshipPlacement::ListTop->value,
        'starts_at' => '2026-11-21T09:00:00+01:00',
        'ends_at' => '2026-12-21T09:00:00+01:00',
        'product' => 'period',
        'amount' => '150.00',
        'status' => 'active',
        'priority' => 0,
        'weight' => 1,
        ...$overrides,
    ];
}

it('apre la promozione solo ad admin e super admin, come il pannello', function (): void {
    $paths = ['/sponsorships', '/sponsorship-orders', '/sponsorship-grants', '/sponsorship-prices', '/sponsorship-banners', '/sponsorships/analytics'];

    foreach ($paths as $path) {
        foreach ([$this->s->ownerA, $this->s->editorA, $this->s->plainUser] as $outsider) {
            r8($outsider)->getJson('/api/v1/editorial'.$path)->assertNotFound();
        }
        r8($this->s->moderator, unlocked: false)->getJson('/api/v1/editorial'.$path)
            ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
        r8($this->s->moderator)->getJson('/api/v1/editorial'.$path)
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        foreach ([$this->s->admin, $this->superAdmin] as $staff) {
            r8($staff)->getJson('/api/v1/editorial'.$path)->assertOk();
        }
    }

    r8($this->s->moderator)->postJson('/api/v1/editorial/sponsorships', r8Wizard())->assertForbidden();
    expect(Sponsorship::count())->toBe(0);
});

it('elenca le campagne con fase, avanzamento, abilità e i filtri del pannello', function (): void {
    $running = Sponsorship::factory()->create(['event_id' => $this->eventA->id, 'advertiser_name' => 'Birrificio del Piave', 'impressions' => 200, 'clicks' => 3]);
    $draft = Sponsorship::factory()->draft()->placement(SponsorshipPlacement::HomeCard)->create(['event_id' => $this->eventB->id, 'advertiser_name' => 'Teatro Belzoni']);

    $page = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships')->assertOk();
    $row = collect($page->json('data'))->firstWhere('id', $running->id);
    expect($page->json('meta.total'))->toBe(2)
        ->and($row['event']['title'])->toBe('Jazz in cortile')
        ->and($row['phase']['value'])->toBe('running')
        ->and($row['progress']['state'])->toBe('period')
        ->and($row['click_rate'])->toBe('1.5%')
        ->and($row['toggle_label'])->toBe(__('sponsorships.admin.actions.pause'))
        // Ha già raccolto visualizzazioni: non si elimina, si sospende (`SponsorshipPolicy::delete`).
        ->and($row['abilities'])->toBe(['update' => true, 'toggle' => true, 'destroy' => false])
        ->and($page->json('choices.placements'))->toHaveCount(count(SponsorshipPlacement::cases()));

    $byPlacement = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships?filter[placement]=home_card')->assertOk();
    expect(collect($byPlacement->json('data'))->pluck('id')->all())->toBe([$draft->id]);
    $byStatus = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships?filter[status]=active')->assertOk();
    expect(collect($byStatus->json('data'))->pluck('id')->all())->toBe([$running->id]);
    $runningOnly = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships?filter[running]=1')->assertOk();
    expect(collect($runningOnly->json('data'))->pluck('id')->all())->toBe([$running->id]);
    $search = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships?q=Piave')->assertOk();
    expect(collect($search->json('data'))->pluck('id')->all())->toBe([$running->id]);
    $period = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships?filter[pacing_state]=period')->assertOk();
    expect($period->json('meta.total'))->toBe(2);
});

it('esporta l’elenco in Excel con gli stessi criteri', function (): void {
    Sponsorship::factory()->create(['event_id' => $this->eventA->id, 'advertiser_name' => 'Birrificio del Piave']);

    $response = r8($this->superAdmin)->get('/api/v1/editorial/sponsorships/export?q=Piave')->assertOk();
    expect($response->headers->get('content-type'))->toContain('spreadsheetml')
        ->and($response->headers->get('content-disposition'))->toContain('sponsorizzazioni-');
    r8($this->s->moderator)->getJson('/api/v1/editorial/sponsorships/export')->assertForbidden();
});

it('propone il primo passo dall’evento e gli eventi sponsorizzabili del committente', function (): void {
    $prefill = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships/prefill?event='.$this->eventA->id)->assertOk();
    expect($prefill->json('data.sponsor_type'))->toBe('venue')
        ->and($prefill->json('data.venue_id'))->toBe($this->s->venueA->id)
        ->and($prefill->json('data.advertiser_name'))->toBe('Circolo Aurora')
        ->and($prefill->json('data.event.title'))->toBe('Jazz in cortile')
        ->and($prefill->json('data.choices.sponsor_types'))->toHaveCount(3);

    $sponsors = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships/sponsors?type=venue&q=Aurora')->assertOk();
    expect(collect($sponsors->json('data'))->pluck('name')->all())->toBe(['Circolo Aurora']);

    /* Solo gli eventi sponsorizzabili del locale: non quelli di un altro, non le bozze. */
    $events = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships/events?sponsor_type=venue&venue_id='.$this->s->venueA->id)->assertOk();
    expect(collect($events->json('data'))->pluck('title')->all())->toBe(['Jazz in cortile'])
        ->and($events->json('meta.ready'))->toBeTrue();
    $external = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships/events?sponsor_type=external&q=Teatro')->assertOk();
    expect(collect($external->json('data'))->pluck('title')->all())->toBe(['Teatro di sera']);
});

it('ferma ogni passo con i suoi campi e calcola fuso, prezzo e stima', function (): void {
    SponsorshipPrice::query()->create(['placement' => 'list_top', 'months' => 1, 'amount_cents' => 12000, 'currency' => 'EUR', 'is_active' => true]);

    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorships/estimate', ['step' => 1, 'sponsor_type' => 'venue', 'advertiser_name' => ''])
        ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['venue_id', 'advertiser_name']]]);

    $preview = r8($this->s->admin)->postJson('/api/v1/editorial/sponsorships/estimate', [...r8Wizard(), 'months' => 1, 'step' => 3])->assertOk();
    expect($preview->json('data.timezone'))->toBe('Europe/Rome')
        ->and($preview->json('data.city_id'))->toBe($this->s->city->id)
        ->and($preview->json('data.price.amount'))->toBe('120.00')
        ->and($preview->json('data.estimate.views'))->toBeGreaterThan(0)
        ->and($preview->json('data.ends_at'))->toStartWith('2026-12-21T09:00:00');

    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorships/estimate', [...r8Wizard(['placement' => 'home_hero', 'product' => 'views', 'impressions_goal' => 100, 'pacing' => 'even']), 'step' => 3])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['product']]]);
});

it('crea una campagna singola e un’abilitazione per tutti gli eventi, come il pannello', function (): void {
    $single = r8($this->s->admin)->postJson('/api/v1/editorial/sponsorships', r8Wizard())->assertCreated();
    $campaign = Sponsorship::query()->findOrFail($single->json('data.id'));
    expect($single->json('data.kind'))->toBe('sponsorship')
        ->and($campaign->event_id)->toBe($this->eventA->id)
        ->and($campaign->city_id)->toBe($this->s->city->id)
        ->and($campaign->status)->toBe(SponsorshipStatus::Active)
        ->and($campaign->amount_cents)->toBe(15000)
        ->and($campaign->created_by)->toBe($this->s->admin->id)
        ->and($campaign->starts_at->toIso8601String())->toBe('2026-11-21T08:00:00+00:00');

    /* Già cominciata: la sincronizzazione crea le righe solo di un'abilitazione in corso. */
    $grant = r8($this->superAdmin)->postJson('/api/v1/editorial/sponsorships', r8Wizard(['scope' => 'all', 'event_id' => null, 'complimentary' => true, 'amount' => null,
        'notes' => 'Ospite della rassegna', 'starts_at' => '2026-11-20T09:00:00+01:00']))->assertCreated();
    $record = SponsorshipGrant::query()->findOrFail($grant->json('data.id'));
    expect($grant->json('data.kind'))->toBe('grant')
        ->and($record->mode)->toBe(PromotionMode::Automatic)
        ->and($record->complimentary)->toBeTrue()
        ->and($record->amount_cents)->toBeNull()
        ->and($record->sponsorships()->pluck('event_id')->all())->toBe([$this->eventA->id]);
});

it('rifiuta un evento di un altro committente e un obiettivo oltre lo spazio', function (): void {
    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorships', r8Wizard(['event_id' => $this->eventB->id]))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['event_id']]]);

    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorships', r8Wizard(['product' => 'views', 'impressions_goal' => 10_000_000, 'pacing' => 'even']))
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['impressions_goal']]]);

    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorships', r8Wizard(['ends_at' => '2026-11-20T09:00:00+01:00']))
        ->assertUnprocessable()->assertJsonPath('error.fields.ends_at.0', __('sponsorships.admin.validation.ends_after_starts'));

    expect(Sponsorship::count())->toBe(0);
});

it('mostra la scheda e modifica con le regole del modulo; la città segue l’evento', function (): void {
    $otherCityVenue = Venue::factory()->approved()->create(['city_id' => City::factory()->create(['timezone' => 'Europe/Rome'])->id]);
    $elsewhere = r8Event($otherCityVenue, 'Concerto altrove', 4);
    $campaign = Sponsorship::factory()->create(['event_id' => $this->eventA->id]);

    $detail = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships/'.$campaign->id)->assertOk();
    expect($detail->json('data.form.event_id'))->toBe($this->eventA->id)
        ->and($detail->json('data.on_grant'))->toBeFalse()
        ->and($detail->json('data.progress_detail.progress.state'))->toBe('period')
        ->and($detail->json('data.progress_detail.cards'))->toHaveCount(2);

    $saved = r8($this->s->admin)->patchJson('/api/v1/editorial/sponsorships/'.$campaign->id, [
        'event_id' => $elsewhere->id, 'advertiser_name' => 'Nuovo committente', 'amount' => '99.50', 'impressions_cap' => 5000,
    ])->assertOk();
    $campaign->refresh();
    expect($saved->json('data.form.advertiser_name'))->toBe('Nuovo committente')
        ->and($campaign->city_id)->toBe($otherCityVenue->city_id)
        ->and($campaign->amount_cents)->toBe(9950)
        ->and($campaign->impressions_cap)->toBe(5000);

    r8($this->s->admin)->patchJson('/api/v1/editorial/sponsorships/'.$campaign->id, ['ends_at' => '2026-11-01T10:00:00+01:00'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['ends_at']]]);
    r8($this->s->admin)->patchJson('/api/v1/editorial/sponsorships/'.$campaign->id, ['placement' => 'home_hero', 'impressions_goal' => 100])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['impressions_goal']]]);
});

it('non cambia la consegna di una riga di un’abilitazione', function (): void {
    $grant = SponsorshipGrant::factory()->create(['venue_id' => $this->s->venueA->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);
    $row = Sponsorship::factory()->forGrant($grant)->create(['event_id' => $this->eventA->id]);

    r8($this->s->admin)->patchJson('/api/v1/editorial/sponsorships/'.$row->id, ['priority' => 900, 'weight' => 50, 'notes' => 'Solo la nota'])->assertOk()
        ->assertJsonPath('data.on_grant', true);
    $row->refresh();
    expect($row->priority)->toBe(0)->and($row->notes)->toBe('Solo la nota');
});

it('sospende e riattiva una campagna', function (): void {
    $campaign = Sponsorship::factory()->create(['event_id' => $this->eventA->id]);

    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorships/'.$campaign->id.'/toggle')->assertOk()
        ->assertJsonPath('message', __('sponsorships.admin.actions.paused'))->assertJsonPath('data.status.value', 'paused');
    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorships/'.$campaign->id.'/toggle')->assertOk()
        ->assertJsonPath('message', __('sponsorships.admin.actions.activated'));
    expect($campaign->refresh()->status)->toBe(SponsorshipStatus::Active);
    r8($this->s->moderator)->postJson('/api/v1/editorial/sponsorships/'.$campaign->id.'/toggle')->assertForbidden();
});

it('elimina, anche in gruppo, solo con la password recente', function (): void {
    $one = Sponsorship::factory()->create(['event_id' => $this->eventA->id]);
    $two = Sponsorship::factory()->create(['event_id' => $this->eventB->id]);
    $three = Sponsorship::factory()->create(['event_id' => $this->eventB->id]);

    r8($this->s->admin, passwordMinutesAgo: 16)->deleteJson('/api/v1/editorial/sponsorships/'.$one->id)
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    r8($this->s->admin, passwordMinutesAgo: 16)->postJson('/api/v1/editorial/sponsorships/bulk-destroy', ['ids' => [$two->id]])
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');

    r8($this->s->admin)->deleteJson('/api/v1/editorial/sponsorships/'.$one->id)->assertOk();
    r8($this->superAdmin)->postJson('/api/v1/editorial/sponsorships/bulk-destroy', ['ids' => [$two->id, $three->id]])->assertOk()
        ->assertJsonPath('data.deleted', [$two->id, $three->id]);

    expect(Sponsorship::count())->toBe(0)->and(Sponsorship::withTrashed()->count())->toBe(3);
    r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships/'.$one->id)->assertNotFound();
});

it('approva un ordine pagato e una richiesta pagata a mano o gratuita', function (): void {
    $paid = r8Order(SponsorshipOrderStatus::Paid, ['payment_intent_id' => 'pi_test', 'paid_at' => now()->subHour()]);
    $request = r8Order(SponsorshipOrderStatus::Requested);
    $free = r8Order(SponsorshipOrderStatus::Requested, ['amount_cents' => 0]);

    $list = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorship-orders?filter[status]=requested')->assertOk();
    expect(collect($list->json('data'))->pluck('id')->sort()->values()->all())->toBe([$request->id, $free->id])
        ->and(collect($list->json('data'))->firstWhere('id', $request->id)['abilities'])->toBe(['approve' => false, 'approve_request' => true, 'reject' => true]);

    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorship-orders/'.$paid->id.'/approve')->assertOk()
        ->assertJsonPath('data.status.value', 'approved')->assertJsonPath('message', __('promotions.orders.approved'));
    expect($paid->refresh()->grant)->not->toBeNull()
        ->and($paid->grant->payment_method)->toBe('stripe')
        ->and($paid->decided_by)->toBe($this->s->admin->id);

    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorship-orders/'.$request->id.'/approve', ['settlement' => 'paid'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['amount', 'payment_method']]]);
    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorship-orders/'.$request->id.'/approve', ['settlement' => 'paid', 'amount' => '140', 'payment_method' => 'bank_transfer', 'payment_reference' => 'CRO 123'])->assertOk();
    $grant = $request->refresh()->grant;
    expect($grant->amount_cents)->toBe(14000)->and($grant->payment_method)->toBe('bank_transfer')->and($grant->payment_reference)->toBe('CRO 123');

    r8($this->superAdmin)->postJson('/api/v1/editorial/sponsorship-orders/'.$free->id.'/approve', ['settlement' => 'free', 'reason' => 'Partner della rassegna'])->assertOk();
    expect($free->refresh()->grant->complimentary)->toBeTrue();

    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorship-orders/'.$paid->id.'/approve')->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    Notification::assertSentTo($this->s->ownerA, SponsorshipOrderDecided::class);
});

it('rifiuta con il motivo e rimborsa un ordine pagato online', function (): void {
    $paid = r8Order(SponsorshipOrderStatus::Paid, ['payment_intent_id' => 'pi_refund', 'paid_at' => now()->subHour()]);

    r8($this->s->moderator)->postJson('/api/v1/editorial/sponsorship-orders/'.$paid->id.'/reject', ['reason' => 'Fuori tema'])->assertForbidden();
    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorship-orders/'.$paid->id.'/reject', [])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['reason']]]);
    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorship-orders/'.$paid->id.'/reject', ['reason' => 'Fuori tema'])->assertOk()
        ->assertJsonPath('data.status.value', 'rejected');

    expect($this->gateway->refunds)->toBe(['pi_refund'])
        ->and($paid->refresh()->decision_note)->toStartWith('Fuori tema');
});

it('chiede la password recente per rifiutare un ordine pagato online, perché il rimborso non si annulla', function (): void {
    $paid = r8Order(SponsorshipOrderStatus::Paid, ['payment_intent_id' => 'pi_refund', 'paid_at' => now()->subHour()]);
    $request = r8Order(SponsorshipOrderStatus::Requested);
    $url = fn (SponsorshipOrder $order): string => '/api/v1/editorial/sponsorship-orders/'.$order->id.'/reject';

    /* Il token non sbloccato non entra nemmeno nell’area; la password di più di 15 minuti fa ferma il rifiuto con rimborso. */
    r8($this->s->admin, unlocked: false)->postJson($url($paid), ['reason' => 'Fuori tema'])
        ->assertStatus(423)->assertJsonPath('error.code', 'EDITORIAL_LOCKED');
    foreach ([$this->s->admin, $this->superAdmin] as $staff) {
        r8($staff, passwordMinutesAgo: 16)->postJson($url($paid), ['reason' => 'Fuori tema'])
            ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    }
    expect($this->gateway->refunds)->toBe([])
        ->and($paid->refresh()->status)->toBe(SponsorshipOrderStatus::Paid);

    /* Una richiesta senza pagamento online non rimborsa niente: basta lo sblocco. */
    r8($this->s->admin, passwordMinutesAgo: 16)->postJson($url($request), ['reason' => 'Fuori tema'])->assertOk()
        ->assertJsonPath('data.status.value', 'rejected');

    /* Chi non decide gli ordini resta fuori prima della password, e chi non è della redazione non vede l'area. */
    r8($this->s->moderator, passwordMinutesAgo: 16)->postJson($url($paid), ['reason' => 'Fuori tema'])->assertForbidden();
    foreach ([$this->s->ownerA, $this->s->plainUser] as $outsider) {
        r8($outsider)->postJson($url($paid), ['reason' => 'Fuori tema'])->assertNotFound();
    }

    r8($this->s->admin)->postJson($url($paid), ['reason' => 'Fuori tema'])->assertOk()->assertJsonPath('data.status.value', 'rejected');
    expect($this->gateway->refunds)->toBe(['pi_refund']);
});

it('crea e modifica un’abilitazione con le regole del modulo, senza eliminarla', function (): void {
    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorship-grants', ['mode' => 'selected', 'placement' => 'list_top', 'starts_at' => '2026-11-21T09:00:00+01:00', 'ends_at' => '2026-12-21T09:00:00+01:00', 'amount' => '100'])
        ->assertUnprocessable()->assertJsonPath('error.fields.venue_id.0', __('promotions.sponsor_required'));

    $created = r8($this->s->admin)->postJson('/api/v1/editorial/sponsorship-grants', [
        'venue_id' => $this->s->venueA->id, 'mode' => 'automatic', 'placement' => 'home_card',
        'starts_at' => '2026-11-20T09:00:00+01:00', 'ends_at' => '2026-12-21T09:00:00+01:00', 'amount' => '100.00', 'paid_at' => '2026-11-19T10:00:00+01:00', 'payment_method' => 'bonifico',
    ])->assertCreated();
    $grant = SponsorshipGrant::query()->findOrFail($created->json('data.id'));
    expect($grant->created_by)->toBe($this->s->admin->id)
        ->and($grant->amount_cents)->toBe(10000)
        ->and($grant->sponsorships()->pluck('event_id')->all())->toBe([$this->eventA->id])
        ->and($created->json('data.form.placement'))->toBe('home_card');

    $updated = r8($this->superAdmin)->patchJson('/api/v1/editorial/sponsorship-grants/'.$grant->id, ['complimentary' => true, 'notes' => 'Ospite', 'placement' => 'home_hero', 'weight' => 3])->assertOk();
    $grant->refresh();
    expect($grant->complimentary)->toBeTrue()
        ->and($grant->amount_cents)->toBeNull()
        ->and($grant->paid_at)->toBeNull()
        ->and($grant->placement)->toBe(SponsorshipPlacement::HomeCard)
        ->and($grant->weight)->toBe(3)
        ->and($updated->json('data.abilities.destroy'))->toBeFalse();

    $log = Activity::query()->where('subject_type', $grant->getMorphClass())->where('subject_id', $grant->id)->latest('id')->first();
    expect($log?->getProperty('canale'))->toBe('app');

    r8($this->s->admin)->getJson('/api/v1/editorial/sponsorship-grants?filter[venue]='.$this->s->venueA->id)->assertOk()->assertJsonPath('meta.total', 1);
    r8($this->s->admin)->deleteJson('/api/v1/editorial/sponsorship-grants/'.$grant->id)->assertStatus(405);
});

it('gestisce il listino: una voce per collocazione e durata, eliminazione con la password', function (): void {
    $created = r8($this->s->admin)->postJson('/api/v1/editorial/sponsorship-prices', ['placement' => 'home_hero', 'months' => 3, 'amount' => '450.00'])->assertCreated();
    expect($created->json('data.amount_label'))->toContain('450')->and($created->json('data.is_active'))->toBeTrue();

    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorship-prices', ['placement' => 'home_hero', 'months' => 3, 'amount' => '10'])
        ->assertUnprocessable()->assertJsonPath('error.fields.months.0', __('promotions.prices.duplicate'));
    r8($this->s->admin)->postJson('/api/v1/editorial/sponsorship-prices', ['placement' => 'home_hero', 'months' => 4, 'amount' => '10'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['months']]]);

    $id = $created->json('data.id');
    r8($this->s->admin)->patchJson('/api/v1/editorial/sponsorship-prices/'.$id, ['amount' => '500', 'is_active' => false])->assertOk()
        ->assertJsonPath('data.amount', '500.00')->assertJsonPath('data.is_active', false);
    r8($this->s->admin)->getJson('/api/v1/editorial/sponsorship-prices?filter[is_active]=1')->assertOk()->assertJsonPath('meta.total', 0);

    r8($this->s->admin, passwordMinutesAgo: 16)->deleteJson('/api/v1/editorial/sponsorship-prices/'.$id)
        ->assertStatus(423)->assertJsonPath('error.code', 'PASSWORD_REQUIRED');
    r8($this->s->admin)->deleteJson('/api/v1/editorial/sponsorship-prices/'.$id)->assertOk();
    expect(SponsorshipPrice::count())->toBe(0);
});

it('accende e spegne i banner del sito e dell’app', function (): void {
    r8($this->s->admin)->putJson('/api/v1/editorial/sponsorship-banners', ['web_enabled' => false, 'android_enabled' => true])->assertOk()
        ->assertJsonPath('data.web_enabled', false)->assertJsonPath('data.android_enabled', true);
    $settings = app(SponsorshipBannerSettings::class);
    $settings->refresh();
    expect($settings->web_enabled)->toBeFalse()->and($settings->android_enabled)->toBeTrue();

    r8($this->s->moderator)->putJson('/api/v1/editorial/sponsorship-banners', ['web_enabled' => true, 'android_enabled' => true])->assertForbidden();
    r8($this->s->admin)->putJson('/api/v1/editorial/sponsorship-banners', ['web_enabled' => 'forse'])->assertUnprocessable();
});

it('dà le statistiche di tutte le campagne, con il registro e la scheda di una', function (): void {
    $campaign = Sponsorship::factory()->create(['event_id' => $this->eventA->id, 'impressions' => 30, 'clicks' => 2]);
    $other = Sponsorship::factory()->create(['event_id' => $this->eventB->id]);

    $report = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships/analytics?days=30')->assertOk();
    expect($report->json('data.filters.days'))->toBe('30')->and($report->json('data.series'))->toHaveCount(30);

    $options = r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships/analytics/campaigns')->assertOk();
    expect(collect($options->json('data'))->pluck('id')->sort()->values()->all())->toBe([$campaign->id, $other->id]);

    r8($this->superAdmin)->getJson('/api/v1/editorial/sponsorships/analytics/campaigns/'.$campaign->id)->assertOk()
        ->assertJsonPath('data.id', $campaign->id)->assertJsonPath('data.impressions', 30);
    r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships/analytics/clicks')->assertOk()->assertJsonPath('meta.total', 0);
    r8($this->s->admin)->getJson('/api/v1/editorial/sponsorships/analytics?days=custom')->assertUnprocessable();
    r8($this->s->moderator)->getJson('/api/v1/editorial/sponsorships/analytics/campaigns/'.$campaign->id)->assertForbidden();
});
