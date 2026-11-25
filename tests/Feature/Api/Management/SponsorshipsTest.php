<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipStatus;
use App\Filament\Venue\Pages\Sponsorships as VenueSponsorships;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Organizer;
use App\Models\Sponsorship;
use App\Models\SponsorshipClick;
use App\Models\SponsorshipDailyStat;
use App\Models\SponsorshipGrant;
use App\Models\SponsorshipOrder;
use App\Models\SponsorshipPrice;
use App\Models\User;
use App\Services\Sponsorship\Payment\PaymentGateway;
use App\Services\Sponsorship\SponsorshipDesk;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\FakePaymentGateway;
use Tests\Support\VenueIsolationScenario;

/*
 * Le sponsorizzazioni nell'app di gestione (P6): la pagina
 * «Sponsorizzazioni» e le sue statistiche, con le regole del pannello perché
 * passano dallo stesso servizio (`SponsorshipDesk`, `SponsorshipAnalyticsReport`).
 *
 * La griglia dei permessi: il referente e il proprietario
 * dell'organizzazione comprano; il collaboratore e il membro guardano,
 * mettono in evidenza un evento su un periodo già pagato e fermano una
 * campagna (decisione 7), ma non spendono; il personale d'ingresso,
 * l'estraneo e il locale sospeso ricevono 404, come un id di un altro
 * committente. Stripe è il gateway finto dei test.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->s = VenueIsolationScenario::make();
    config(['services.stripe.secret' => 'sk_test_fake']);
    $this->gateway = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $this->gateway);

    $this->url = '/api/v1/management/venues/'.$this->s->venueA->id.'/sponsorships';
    $this->future = EventOccurrence::factory()->create(['event_id' => $this->s->publishedEventA->id,
        'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHours(2)]);
    EventOccurrence::factory()->create(['event_id' => $this->s->publishedEventB->id, 'starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(4)->addHours(2)]);
    $this->price = SponsorshipPrice::create(['placement' => 'list_top', 'months' => 3, 'amount_cents' => 16200, 'currency' => 'EUR', 'is_active' => true]);

    $this->organizerOwner = User::factory()->create();
    $this->organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->s->city->id, 'owner_id' => $this->organizerOwner->id, 'is_active' => true]);
    $this->organizerMember = User::factory()->create();
    $this->organizer->users()->attach($this->organizerMember);
    $this->organizerUrl = '/api/v1/management/organizers/'.$this->organizer->id.'/sponsorships';
    $this->organizerEvent = Event::factory()->create(['city_id' => $this->s->city->id, 'category_id' => $this->s->category->id,
        'organizer_id' => $this->organizer->id, 'venue_id' => $this->s->venueB->id, 'status' => EventStatus::Published, 'published_at' => now(), 'title' => 'Festa dei Portici']);
    EventOccurrence::factory()->create(['event_id' => $this->organizerEvent->id, 'starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHours(2)]);
});

function sponsorshipGrantFor(array $attributes): SponsorshipGrant
{
    return SponsorshipGrant::create([
        'created_by' => test()->s->admin->id, 'mode' => 'selected', 'placement' => 'list_top', 'enabled' => true,
        'complimentary' => true, 'notes' => 'Prova', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), ...$attributes,
    ]);
}

it('apre la pagina a ogni membro: compra solo il titolare, gli altri ricevono 404', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.can_purchase', true)
        ->assertJsonPath('data.pays_online', true)
        ->assertJsonPath('data.not_owner', null)
        ->assertJsonPath('data.limits.requests_per_hour', 5)
        ->assertJsonPath('data.limits.max_open_requests', 3)
        ->assertJsonPath('data.sponsorable_count', 1)
        ->assertJsonPath('data.how.steps.2', __('promotions.page.how_step_3_online'))
        ->assertJsonStructure(['data' => ['subject', 'how', 'limits', 'selectable_grants', 'orders', 'totals' => ['impressions', 'clicks'], 'grants', 'campaigns', 'campaigns_next_page', 'measures']]);

    Sanctum::actingAs($this->s->editorA);
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.can_purchase', false)
        ->assertJsonPath('data.not_owner.body', __('promotions.page.not_owner_body_venue'));
    $this->postJson($this->url.'/orders', ['scope' => 'all', 'placement' => 'list_top', 'duration' => 'price:'.$this->price->id])->assertForbidden();

    Sanctum::actingAs($this->organizerOwner);
    $this->getJson($this->organizerUrl)->assertOk()->assertJsonPath('data.can_purchase', true)->assertJsonPath('data.subject.type', 'organizer');
    Sanctum::actingAs($this->organizerMember);
    $this->getJson($this->organizerUrl)->assertOk()->assertJsonPath('data.can_purchase', false)
        ->assertJsonPath('data.not_owner.body', __('promotions.page.not_owner_body_organizer'));
    $this->postJson($this->organizerUrl.'/orders', ['scope' => 'all', 'placement' => 'list_top', 'duration' => 'price:'.$this->price->id])->assertForbidden();

    $staff = User::factory()->create();
    $this->future->checkinStaff()->attach($staff);
    foreach ([$this->s->ownerB, $this->s->plainUser, $staff, $this->organizerOwner] as $stranger) {
        Sanctum::actingAs($stranger);
        $this->getJson($this->url)->assertNotFound();
        $this->getJson($this->url.'/analytics')->assertNotFound();
    }

    $this->s->venueA->forceFill(['status' => 'suspended'])->saveQuietly();
    Sanctum::actingAs($this->s->ownerA);
    $this->getJson($this->url)->assertNotFound();
    $this->postJson($this->url.'/orders', ['scope' => 'all', 'placement' => 'list_top', 'duration' => 'price:'.$this->price->id])->assertNotFound();
});

it('propone solo gli eventi sponsorizzabili del committente, con il listino', function (): void {
    Sponsorship::factory()->create(['event_id' => $this->s->publishedEventA->id]);
    Sanctum::actingAs($this->s->editorA);

    $this->getJson($this->url.'/events')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->s->publishedEventA->id)
        ->assertJsonPath('data.0.already_sponsored', true)
        ->assertJsonPath('data.0.date', $this->future->starts_at->copy()->setTimezone('Europe/Rome')->toIso8601String())
        ->assertJsonPath('meta.has_more', false);
    $this->getJson($this->url.'/events?q=nessuno')->assertOk()->assertJsonCount(0, 'data');

    $catalog = $this->getJson($this->url.'/catalog')->assertOk()->assertJsonPath('data.pays_online', true)->json('data.placements');
    $list = collect($catalog)->firstWhere('value', 'list_top');
    expect($list['durations'])->toHaveCount(1)
        ->and($list['durations'][0]['key'])->toBe('price:'.$this->price->id)
        ->and($list['durations'][0]['pays_online'])->toBeTrue()
        ->and($list['durations'][0]['amount_label'])->toContain('162,00')
        ->and($list['from_label'])->toContain('162,00');
    $hero = collect($catalog)->firstWhere('value', 'home_hero');
    expect(array_column($hero['durations'], 'months'))->toBe([1, 3, 6, 12])
        ->and($hero['durations'][0]['amount_label'])->toBe(__('promotions.request.price_to_agree'))
        ->and($hero['durations'][0]['pays_online'])->toBeFalse();
});

it('apre il pagamento con un ritorno che riporta nell\'app, e lo riconcilia', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $response = $this->postJson($this->url.'/orders', ['scope' => 'event', 'event_id' => $this->s->publishedEventA->id, 'placement' => 'list_top', 'duration' => 'price:'.$this->price->id])
        ->assertCreated()->assertJsonPath('data.mode', 'checkout');
    $order = SponsorshipOrder::sole();
    $response->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_'.$order->id.'_1')
        ->assertJsonPath('data.order.status.value', 'pending_payment')
        ->assertJsonPath('data.order.can_retry', true);
    expect($order->event_id)->toBe($this->s->publishedEventA->id)
        ->and($this->gateway->checkouts[0]['success'])->toBe(route('app.sponsorship-return').'?subject=venue&id='.$this->s->venueA->id.'&order='.$order->id)
        ->and($this->gateway->checkouts[0]['cancel'])->toEndWith('&order='.$order->id.'&cancelled=1');

    // Chiusa la pagina di Stripe: l'ordine resta in attesa e si può riprovare.
    $this->postJson($this->url.'/orders/'.$order->id.'/reconcile', ['cancelled' => true])->assertOk()
        ->assertJsonPath('data.mode', 'returned_cancelled')->assertJsonPath('message', __('promotions.orders.returned_cancelled'));
    $this->postJson($this->url.'/orders/'.$order->id.'/reconcile')->assertOk()->assertJsonPath('data.mode', 'returned_pending');

    // Pagato su Stripe: il ritorno lo segna pagato anche senza webhook. Lo può chiedere ogni membro.
    $this->gateway->paidSessions[$order->checkout_session_id] = 'pi_test_app';
    Sanctum::actingAs($this->s->editorA);
    $this->postJson($this->url.'/orders/'.$order->id.'/reconcile')->assertOk()
        ->assertJsonPath('data.mode', 'paid')->assertJsonPath('data.order.status.value', 'paid')->assertJsonPath('data.order.can_retry', false);
    expect($order->fresh()->status)->toBe(SponsorshipOrderStatus::Paid);

    // Un ordine di un altro committente non esiste.
    $other = SponsorshipOrder::create(['venue_id' => $this->s->venueB->id, 'user_id' => $this->s->ownerB->id, 'placement' => 'list_top', 'mode' => 'automatic',
        'months' => 3, 'amount_cents' => 16200, 'currency' => 'EUR', 'status' => SponsorshipOrderStatus::PendingPayment]);
    Sanctum::actingAs($this->s->ownerA);
    $this->postJson($this->url.'/orders/'.$other->id.'/reconcile')->assertNotFound();
    $this->postJson($this->url.'/orders/'.$other->id.'/retry')->assertNotFound();
    $this->postJson($this->url.'/orders/'.$other->id.'/cancel')->assertNotFound();
});

it('riprova e annulla un ordine in attesa; uno già pagato non si annulla', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $this->postJson($this->url.'/orders', ['scope' => 'all', 'placement' => 'list_top', 'duration' => 'price:'.$this->price->id])->assertCreated();
    $order = SponsorshipOrder::sole();

    $this->postJson($this->url.'/orders/'.$order->id.'/retry')->assertOk()
        ->assertJsonPath('data.mode', 'checkout')
        ->assertJsonPath('data.checkout_url', 'https://checkout.stripe.test/pay/cs_test_'.$order->id.'_2');
    expect($this->gateway->expired)->toBe(['cs_test_'.$order->id.'_1']);

    Sanctum::actingAs($this->s->editorA);
    $this->postJson($this->url.'/orders/'.$order->id.'/retry')->assertForbidden();
    $this->postJson($this->url.'/orders/'.$order->id.'/cancel')->assertForbidden();

    Sanctum::actingAs($this->s->ownerA);
    $this->postJson($this->url.'/orders/'.$order->id.'/cancel')->assertOk()
        ->assertJsonPath('data.mode', 'cancelled')->assertJsonPath('data.order.status.value', 'cancelled');
    $this->postJson($this->url.'/orders/'.$order->id.'/cancel')->assertNotFound();

    // La tariffa cambiata annulla l'ordine invece di farlo pagare al prezzo di ieri.
    $this->postJson($this->url.'/orders', ['scope' => 'all', 'placement' => 'list_top', 'duration' => 'price:'.$this->price->id])->assertCreated();
    $second = SponsorshipOrder::query()->latest('id')->firstOrFail();
    $this->price->update(['amount_cents' => 19900]);
    $this->postJson($this->url.'/orders/'.$second->id.'/retry')->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT')->assertJsonPath('error.message', __('promotions.orders.no_longer_payable'));
    expect($second->fresh()->status)->toBe(SponsorshipOrderStatus::Cancelled);
});

it('senza Stripe manda una richiesta alla redazione, con il tetto delle richieste aperte', function (): void {
    $this->gateway->configured = false;
    Sanctum::actingAs($this->s->ownerA);
    $this->getJson($this->url)->assertJsonPath('data.pays_online', false)->assertJsonPath('data.how.steps.2', __('promotions.page.how_step_3_request'));

    foreach (range(1, 3) as $n) {
        $this->postJson($this->url.'/orders', ['scope' => 'all', 'placement' => 'home_hero', 'duration' => 'months:3', 'note' => 'Richiesta '.$n])
            ->assertCreated()->assertJsonPath('data.mode', 'request')->assertJsonPath('data.checkout_url', null)
            ->assertJsonPath('data.order.status.value', 'requested')->assertJsonPath('data.order.can_withdraw', true);
    }
    $this->postJson($this->url.'/orders', ['scope' => 'all', 'placement' => 'home_hero', 'duration' => 'months:3'])
        ->assertStatus(429)->assertJsonPath('error.fields.order.0', __('promotions.request.too_many', ['max' => 3]));
    expect(SponsorshipOrder::query()->where('status', SponsorshipOrderStatus::Requested)->count())->toBe(3)
        ->and(SponsorshipOrder::query()->first()->request_note)->toBe('Richiesta 1');

    // Ritirare: solo il titolare, solo una richiesta.
    $order = SponsorshipOrder::query()->first();
    Sanctum::actingAs($this->s->editorA);
    $this->deleteJson($this->url.'/orders/'.$order->id)->assertForbidden();
    Sanctum::actingAs($this->s->ownerA);
    $this->deleteJson($this->url.'/orders/'.$order->id)->assertOk()->assertJsonPath('data.order.status.value', 'cancelled')
        ->assertJsonPath('message', __('promotions.request.withdrawn'));
    $this->deleteJson($this->url.'/orders/'.$order->id)->assertNotFound();
});

it('controlla la scelta: evento altrui, durata inventata, campi sotto il campo', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->postJson($this->url.'/orders', ['scope' => 'event', 'placement' => 'list_top', 'duration' => 'price:'.$this->price->id])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['event_id']]]);
    $this->postJson($this->url.'/orders', ['scope' => 'event', 'event_id' => $this->s->publishedEventB->id, 'placement' => 'list_top', 'duration' => 'price:'.$this->price->id])
        ->assertUnprocessable()->assertJsonPath('error.fields.event_id.0', __('promotions.request.event_not_available'));
    $this->postJson($this->url.'/orders', ['scope' => 'event', 'event_id' => $this->s->draftEventA->id, 'placement' => 'list_top', 'duration' => 'price:'.$this->price->id])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['event_id']]]);
    $this->postJson($this->url.'/orders', ['scope' => 'all', 'placement' => 'list_top', 'duration' => 'months:3'])
        ->assertUnprocessable()->assertJsonPath('error.fields.duration.0', __('promotions.request.incomplete'));
    $this->postJson($this->url.'/orders', ['scope' => 'all', 'placement' => 'billboard', 'duration' => 'months:3'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['placement']]]);
    $this->postJson($this->url.'/orders', ['scope' => 'all', 'placement' => 'home_hero', 'duration' => 'months:3', 'note' => Str::repeat('x', 1001)])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['note']]]);

    expect(SponsorshipOrder::count())->toBe(0);
});

it('consuma lo stesso secchio del pannello: cinque invii l\'ora per persona', function (): void {
    $this->gateway->configured = false;
    Sanctum::actingAs($this->s->ownerA);
    foreach (range(1, 5) as $ignored) {
        RateLimiter::hit(SponsorshipDesk::limitKey($this->s->ownerA), 3600);
    }

    $this->postJson($this->url.'/orders', ['scope' => 'all', 'placement' => 'home_hero', 'duration' => 'months:3'])
        ->assertStatus(429)->assertJsonPath('error.fields.order.0', __('promotions.request.slow_down'));

    RateLimiter::clear(SponsorshipDesk::limitKey($this->s->ownerA));
    $this->postJson($this->url.'/orders', ['scope' => 'all', 'placement' => 'home_hero', 'duration' => 'months:3'])->assertCreated();
    expect(RateLimiter::attempts('sponsorship-request:'.$this->s->ownerA->id))->toBe(1);

    // Il pannello legge lo stesso contatore.
    foreach (range(1, 4) as $ignored) {
        RateLimiter::hit(SponsorshipDesk::limitKey($this->s->ownerA), 3600);
    }
    $this->actingAs($this->s->ownerA, 'web');
    auth()->shouldUse('web');
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->s->venueA);
    $this->get(VenueSponsorships::getUrl(tenant: $this->s->venueA))->assertOk();
    Livewire::test(VenueSponsorships::class)->set('scope', 'all')->call('selectPlacement', 'home_hero')->call('selectDuration', 'months:3')->call('submitOrder')
        ->assertNotified(__('promotions.request.slow_down'));
    expect(SponsorshipOrder::count())->toBe(1);
});

it('mette in evidenza un evento su un periodo attivo e ferma una campagna, anche il collaboratore', function (): void {
    $grant = sponsorshipGrantFor(['venue_id' => $this->s->venueA->id]);
    Sanctum::actingAs($this->s->editorA);

    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('data.selectable_grants.0.id', $grant->id)
        ->assertJsonPath('data.selectable_grants.0.active_event_ids', []);
    $this->postJson($this->url.'/grants/'.$grant->id.'/events', ['event_id' => $this->s->publishedEventA->id])->assertOk()
        ->assertJsonPath('message', __('promotions.success'));
    $campaign = Sponsorship::query()->where('sponsorship_grant_id', $grant->id)->sole();
    expect($campaign->status)->toBe(SponsorshipStatus::Active);
    $this->getJson($this->url.'/events?grant='.$grant->id)->assertJsonPath('data.0.active_on_grant', true);

    $index = $this->getJson($this->url)->assertOk();
    $index->assertJsonPath('data.grants.0.id', $grant->id)
        ->assertJsonPath('data.grants.0.events.0.id', $campaign->id)
        ->assertJsonPath('data.grants.0.events.0.can_stop', true)
        ->assertJsonPath('data.grants.0.mode_label', __('sponsorship-panel.units.scope_selected'));
    expect($index->json('data.grants.0.progress.phrase'))->toBeString()->not->toBeEmpty();

    // Un evento di un altro locale, o un'abilitazione altrui, non passano.
    $this->postJson($this->url.'/grants/'.$grant->id.'/events', ['event_id' => $this->s->publishedEventB->id])->assertUnprocessable();
    $grantB = sponsorshipGrantFor(['venue_id' => $this->s->venueB->id]);
    $this->postJson($this->url.'/grants/'.$grantB->id.'/events', ['event_id' => $this->s->publishedEventA->id])->assertUnprocessable();

    $this->postJson($this->url.'/campaigns/'.$campaign->id.'/stop')->assertOk()->assertJsonPath('data.status', 'paused');
    expect($campaign->fresh()->status)->toBe(SponsorshipStatus::Paused);
    $this->getJson($this->url)->assertJsonPath('data.grants.0.events.0.can_stop', false)
        ->assertJsonPath('data.grants.0.events.0.stopped_label', __('sponsorship-panel.units.event_stopped'));

    $other = Sponsorship::factory()->create(['event_id' => $this->s->publishedEventB->id, 'sponsorship_grant_id' => $grantB->id]);
    $this->postJson($this->url.'/campaigns/'.$other->id.'/stop')->assertNotFound();
    expect($other->fresh()->status)->toBe(SponsorshipStatus::Active);
});

it('mostra all\'organizzazione le sue campagne, e al locale ospite solo quelle della redazione', function (): void {
    $editorial = Sponsorship::factory()->create(['event_id' => $this->organizerEvent->id, 'impressions' => 40, 'clicks' => 3]);
    // Una campagna comprata dall'organizzazione su un evento ospitato dal locale B resta dell'organizzazione.
    $grant = sponsorshipGrantFor(['organizer_id' => $this->organizer->id]);
    $bought = Sponsorship::factory()->create(['event_id' => $this->organizerEvent->id, 'sponsorship_grant_id' => $grant->id, 'impressions' => 10]);

    Sanctum::actingAs($this->organizerMember);
    $this->getJson($this->organizerUrl)->assertOk()
        ->assertJsonPath('data.campaigns.0.id', $editorial->id)
        ->assertJsonPath('data.campaigns.0.phase.value', 'running')
        ->assertJsonPath('data.grants.0.events.0.id', $bought->id)
        ->assertJsonPath('data.totals.impressions', 50)
        ->assertJsonPath('data.totals.clicks', 3);
    $this->getJson($this->organizerUrl.'/campaigns/'.$editorial->id)->assertOk()
        ->assertJsonPath('data.event.title', 'Festa dei Portici')
        ->assertJsonPath('data.grant_unit', false)
        ->assertJsonStructure(['data' => ['progress' => ['phrase', 'percent'], 'economics' => ['spend_cents', 'bookings'], 'phase']]);

    // Il locale che ospita l'evento vede la campagna della redazione (come nel pannello), non quella pagata dall'organizzazione.
    Sanctum::actingAs($this->s->ownerB);
    $urlB = '/api/v1/management/venues/'.$this->s->venueB->id.'/sponsorships';
    $this->getJson($urlB)->assertOk()->assertJsonCount(1, 'data.campaigns')->assertJsonPath('data.campaigns.0.id', $editorial->id)
        ->assertJsonCount(0, 'data.grants')->assertJsonPath('data.totals.impressions', 40);
    $this->getJson($urlB.'/campaigns/'.$bought->id)->assertNotFound();
    $this->getJson($urlB.'/analytics?campaign='.$bought->id)->assertNotFound();
    $this->postJson($urlB.'/campaigns/'.$bought->id.'/stop')->assertNotFound();
});

it('legge le statistiche del periodo, della campagna e il registro dei clic', function (): void {
    $tz = 'Europe/Rome';
    $campaign = Sponsorship::factory()->create(['event_id' => $this->s->publishedEventA->id, 'impressions' => 300, 'clicks' => 4]);
    $foreign = Sponsorship::factory()->create(['event_id' => $this->s->publishedEventB->id]);
    $today = CarbonImmutable::now($tz)->startOfDay();
    SponsorshipDailyStat::query()->insert([
        ['sponsorship_id' => $campaign->id, 'day' => $today->toDateString(), 'impressions' => 200, 'clicks' => 3],
        ['sponsorship_id' => $campaign->id, 'day' => $today->subDays(2)->toDateString(), 'impressions' => 100, 'clicks' => 1],
        ['sponsorship_id' => $foreign->id, 'day' => $today->toDateString(), 'impressions' => 999, 'clicks' => 99],
    ]);
    foreach (range(1, 27) as $n) {
        SponsorshipClick::query()->create(['sponsorship_id' => $campaign->id, 'request_key' => 'k'.$n, 'channel' => $n % 2 ? 'android' : 'web',
            'placement' => 'list_top', 'page' => 'home', 'clicked_at' => now()->subMinutes($n)]);
    }
    SponsorshipClick::query()->create(['sponsorship_id' => $foreign->id, 'request_key' => 'other', 'channel' => 'web', 'placement' => 'list_top', 'page' => 'home', 'clicked_at' => now()]);

    Sanctum::actingAs($this->s->editorA);
    $report = $this->getJson($this->url.'/analytics?days=7')->assertOk()
        ->assertJsonPath('data.totals.impressions', 300)
        ->assertJsonPath('data.totals.clicks', 4)
        ->assertJsonPath('data.totals.rate_label', '1,33%')
        ->assertJsonPath('data.window.to', $today->toDateString())
        ->assertJsonPath('data.window.from', $today->subDays(6)->toDateString())
        ->assertJsonPath('data.progress', null)
        ->assertJsonCount(7, 'data.series')
        ->assertJsonCount(6, 'data.periods');
    expect($report->json('data.series.6'))->toBe(['date' => $today->toDateString(), 'impressions' => 200, 'clicks' => 3])
        ->and($report->json('data.totals.explain'))->toContain('300');

    $this->getJson($this->url.'/analytics?days=custom&from='.$today->subDays(1)->toDateString().'&to='.$today->toDateString().'&campaign='.$campaign->id)->assertOk()
        ->assertJsonPath('data.totals.impressions', 200)
        ->assertJsonPath('data.campaign.id', $campaign->id)
        ->assertJsonStructure(['data' => ['progress' => ['phrase', 'percent', 'badge'], 'economics', 'progress_lead']]);
    $this->getJson($this->url.'/analytics?days=custom')->assertUnprocessable();
    $this->getJson($this->url.'/analytics?days=12')->assertUnprocessable();
    $this->getJson($this->url.'/analytics?campaign='.$foreign->id)->assertNotFound();

    $this->getJson($this->url.'/analytics/clicks?days=7')->assertOk()->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.next_page', 2)->assertJsonPath('meta.total', 27)
        ->assertJsonPath('data.0.title', 'Concerto di musica popolare')
        ->assertJsonPath('data.0.channel', __('sponsorships.analytics.channels.android'))
        ->assertJsonPath('data.0.page', __('sponsorships.analytics.pages.home'));
    $this->getJson($this->url.'/analytics/clicks?days=7&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.next_page', null);

    $this->getJson($this->url.'/analytics/campaigns')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $campaign->id);
    $this->getJson($this->url.'/analytics/campaigns?q=nessuno')->assertOk()->assertJsonCount(0, 'data');
});

it('la pagina di ritorno da Stripe riporta nell\'app solo con un indirizzo valido', function (): void {
    $page = $this->get('/app/sponsorizzazioni/ritorno?subject=venue&id='.$this->s->venueA->id.'&order=12')->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee(__('promotions.app_return.heading_paid'));
    expect($page->getContent())->toContain('intent://')
        ->toContain('/app/sponsorizzazioni/ritorno?subject=venue&amp;id='.$this->s->venueA->id.'&amp;order=12#Intent;scheme=')
        ->toContain('package='.config('account.android_package'));

    $this->get('/app/sponsorizzazioni/ritorno?subject=organizer&id=3&order=9&cancelled=1')->assertOk()
        ->assertSee(__('promotions.app_return.heading_cancelled'))->assertSee('cancelled=1', false);

    $bad = $this->get('/app/sponsorizzazioni/ritorno?subject=admin&id=1&order=<script>')->assertOk();
    expect($bad->getContent())->not->toContain('intent://')->not->toContain('<script>');
});
