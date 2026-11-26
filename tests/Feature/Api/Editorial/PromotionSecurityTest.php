<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Enums\PromotionMode;
use App\Enums\SponsorshipOrderStatus;
use App\Enums\SponsorshipPlacement;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\SponsorshipBanners;
use App\Filament\Admin\Resources\SponsorshipOrders\Pages\ListOrders;
use App\Filament\Admin\Resources\SponsorshipPrices\Pages\EditPrice;
use App\Filament\Admin\Resources\Sponsorships\Pages\EditSponsorship;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Sponsorship;
use App\Models\SponsorshipOrder;
use App\Models\SponsorshipPrice;
use App\Models\User;
use App\Models\Venue;
use App\Services\Sponsorship\Payment\PaymentGateway;
use Carbon\CarbonImmutable;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\EditorialSession;
use Tests\Support\FakePaymentGateway;
use Tests\Support\VenueIsolationScenario;

/*
 * La revisione di sicurezza della seconda ondata, area promozione: le
 * campagne si convalidano sui valori uniti a quelli salvati, importi e tetti
 * hanno un massimo, una decisione su un ordine già deciso nel frattempo dà
 * 409 (nell'app) o una notifica (nel pannello), si elimina solo una campagna
 * mai partita, e ordini, listino e banner lasciano una riga nel registro di
 * sicurezza, dall'app e dal pannello.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));
    $this->gateway = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $this->gateway);

    $this->s = VenueIsolationScenario::make();
    $this->superAdmin = EditorialSession::user(UserRole::SuperAdmin);
    $this->eventA = promoSecEvent($this->s->venueA, 'Jazz in cortile', 3);
});

function promoSecEvent(Venue $venue, string $title, int $days): Event
{
    $event = Event::factory()->create([
        'city_id' => $venue->city_id,
        'venue_id' => $venue->getKey(),
        'category_id' => test()->s->category->getKey(),
        'status' => EventStatus::Published,
        'published_at' => CarbonImmutable::now('UTC'),
        'title' => $title,
    ]);
    EventOccurrence::factory()->create([
        'event_id' => $event->getKey(),
        'starts_at' => CarbonImmutable::now('UTC')->addDays($days)->setTime(20, 0),
        'ends_at' => CarbonImmutable::now('UTC')->addDays($days)->setTime(23, 0),
        'doors_at' => null,
    ]);

    return $event;
}

function promoSec(User $user, int $passwordMinutesAgo = 0): mixed
{
    return EditorialSession::as(test(), EditorialSession::token($user, passwordMinutesAgo: $passwordMinutesAgo));
}

function promoSecOrder(SponsorshipOrderStatus $status, array $attributes = []): SponsorshipOrder
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

/**
 * Un'altra persona decide l'ordine proprio mentre questa richiesta lo sta
 * decidendo: lo stato cambia subito dopo che il controller ha letto
 * l'ordine, prima che il servizio lo rilegga con il blocco.
 */
function promoSecDecidedAfterRead(SponsorshipOrder $order, SponsorshipOrderStatus $status): void
{
    $done = false;
    SponsorshipOrder::retrieved(function (SponsorshipOrder $read) use (&$done, $order, $status): void {
        if ($done || $read->getKey() !== $order->getKey()) {
            return;
        }
        $done = true;
        DB::table('sponsorship_orders')->where('id', $order->getKey())->update(['status' => $status->value, 'decided_at' => now()]);
    });
}

/**
 * Lo stesso nel pannello, dove la riga si rilegge a ogni passo dell'azione:
 * lo stato cambia un attimo prima che il servizio rilegga la riga con il
 * blocco (dentro la sua transazione, che il rifiuto poi annulla).
 */
function promoSecDecidedBeforeLock(SponsorshipOrder $order, SponsorshipOrderStatus $status): void
{
    $done = false;
    DB::connection()->beforeExecuting(function (string $query) use (&$done, $order, $status): void {
        if ($done || ! str_contains($query, 'sponsorship_orders') || ! str_contains(strtolower($query), 'for update')) {
            return;
        }
        $done = true;
        DB::table('sponsorship_orders')->where('id', $order->getKey())->update(['status' => $status->value, 'decided_at' => now()]);
    });
}

function promoSecPanel(mixed $test, User $staff): void
{
    $test->withoutToken();
    app('auth')->forgetGuards();
    auth()->shouldUse('web');
    $test->actingAs($staff, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function promoSecLog(string $event): ?Activity
{
    return Activity::query()->where('log_name', 'sicurezza')->where('description', $event)->latest('id')->first();
}

it('convalida la modifica di una campagna sui valori uniti a quelli salvati', function (): void {
    $campaign = Sponsorship::factory()->create(['event_id' => $this->eventA->id, 'impressions_goal' => 500, 'placement' => SponsorshipPlacement::ListTop]);
    $endsAt = $campaign->ends_at->toIso8601String();

    // Il solo inizio, dopo la fine salvata.
    promoSec($this->s->admin)->patchJson('/api/v1/editorial/sponsorships/'.$campaign->id, ['starts_at' => $campaign->ends_at->addDay()->toIso8601String()])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['ends_at']]]);
    expect($campaign->refresh()->ends_at->toIso8601String())->toBe($endsAt);

    // A una collocazione «solo a periodo» senza dire niente dell'obiettivo: l'obiettivo salvato non resta.
    promoSec($this->s->admin)->patchJson('/api/v1/editorial/sponsorships/'.$campaign->id, ['placement' => SponsorshipPlacement::HomeHero->value])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['impressions_goal']]]);
    expect($campaign->refresh()->placement)->toBe(SponsorshipPlacement::ListTop)->and($campaign->impressions_goal)->toBe(500);

    // Come nel pannello: si svuota l'obiettivo insieme.
    promoSec($this->s->admin)->patchJson('/api/v1/editorial/sponsorships/'.$campaign->id, ['placement' => SponsorshipPlacement::HomeHero->value, 'impressions_goal' => null])
        ->assertOk();
    expect($campaign->refresh()->placement)->toBe(SponsorshipPlacement::HomeHero)->and($campaign->impressions_goal)->toBeNull();
});

it('rifiuta importi e tetti enormi o con troppi decimali, nell’app e nel pannello', function (): void {
    $campaign = Sponsorship::factory()->create(['event_id' => $this->eventA->id, 'amount_cents' => 1000]);
    $url = '/api/v1/editorial/sponsorships/'.$campaign->id;

    foreach ([['amount' => '1e19'], ['amount' => '10.555'], ['amount' => '1000000.01'], ['impressions_cap' => '1e19'], ['clicks_cap' => 2_000_000_000], ['impressions_cap' => '12.5']] as $body) {
        promoSec($this->s->admin)->patchJson($url, $body)->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => [array_key_first($body)]]]);
    }
    promoSec($this->s->admin)->patchJson($url, ['amount' => '1000000', 'impressions_cap' => 1_000_000_000])->assertOk();
    expect($campaign->refresh()->amount_cents)->toBe(100_000_000)->and($campaign->impressions_cap)->toBe(1_000_000_000);

    $campaign->update(['amount_cents' => 1000, 'impressions_cap' => null]);
    promoSecPanel($this, $this->s->admin);
    $this->get('/admin/sponsorships/'.$campaign->id.'/edit')->assertOk();
    Livewire::test(EditSponsorship::class, ['record' => $campaign->id])
        ->fillForm(['amount_cents' => '10000000000000000000', 'impressions_cap' => '10000000000000000000', 'clicks_cap' => '3.5'])
        ->call('save')
        ->assertHasFormErrors(['amount_cents', 'impressions_cap', 'clicks_cap']);
    Livewire::test(EditSponsorship::class, ['record' => $campaign->id])
        ->fillForm(['amount_cents' => '12.345'])
        ->call('save')
        ->assertHasFormErrors(['amount_cents']);
    expect($campaign->refresh()->amount_cents)->toBe(1000)->and($campaign->impressions_cap)->toBeNull();
});

it('risponde 409 a chi decide un ordine che un altro ha appena deciso', function (): void {
    $paid = promoSecOrder(SponsorshipOrderStatus::Paid, ['payment_intent_id' => 'pi_race', 'paid_at' => now()->subHour()]);
    promoSecDecidedAfterRead($paid, SponsorshipOrderStatus::Rejected);
    promoSec($this->s->admin)->postJson('/api/v1/editorial/sponsorship-orders/'.$paid->id.'/approve')
        ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT')
        ->assertJsonPath('error.message', __('promotions.orders.decided_meanwhile', ['status' => SponsorshipOrderStatus::Rejected->label()]));
    expect($paid->refresh()->grant)->toBeNull();

    $requested = promoSecOrder(SponsorshipOrderStatus::Requested);
    promoSecDecidedAfterRead($requested, SponsorshipOrderStatus::Approved);
    promoSec($this->s->admin)->postJson('/api/v1/editorial/sponsorship-orders/'.$requested->id.'/reject', ['reason' => 'Fuori tema'])
        ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    expect($this->gateway->refunds)->toBe([]);
});

it('nel pannello avvisa con una notifica, invece di un errore, se l’ordine è stato deciso nel frattempo', function (): void {
    $paid = promoSecOrder(SponsorshipOrderStatus::Paid, ['payment_intent_id' => 'pi_race', 'paid_at' => now()->subHour()]);
    promoSecPanel($this, $this->s->admin);
    $this->get('/admin/sponsorship-orders')->assertOk();
    $page = Livewire::test(ListOrders::class);
    promoSecDecidedBeforeLock($paid, SponsorshipOrderStatus::Rejected);

    // Lo stato letto dopo l'errore qui è quello di prima: la prova cambia la riga dentro la transazione che il servizio annulla.
    $page->callTableAction('approve', $paid)->assertHasNoTableActionErrors()
        ->assertNotified(__('promotions.orders.decided_meanwhile', ['status' => $paid->refresh()->status->label()]))
        ->assertNotNotified(__('promotions.orders.approved'));
    expect($paid->grant)->toBeNull();
});

it('elimina solo una campagna mai partita, come dice la regola', function (): void {
    $started = Sponsorship::factory()->create(['event_id' => $this->eventA->id, 'impressions' => 10]);
    $clicked = Sponsorship::factory()->create(['event_id' => $this->eventA->id, 'clicks' => 1]);
    $fresh = Sponsorship::factory()->create(['event_id' => $this->eventA->id]);

    expect($this->s->admin->can('delete', $started))->toBeFalse()
        ->and($this->s->admin->can('delete', $clicked))->toBeFalse()
        ->and($this->superAdmin->can('delete', $fresh))->toBeTrue();

    promoSec($this->s->admin)->getJson('/api/v1/editorial/sponsorships/'.$started->id)->assertOk()->assertJsonPath('data.abilities.destroy', false);
    promoSec($this->s->admin)->deleteJson('/api/v1/editorial/sponsorships/'.$started->id)->assertForbidden();
    promoSec($this->s->admin)->postJson('/api/v1/editorial/sponsorships/bulk-destroy', ['ids' => [$started->id, $clicked->id, $fresh->id]])->assertOk()
        ->assertJsonPath('data.deleted', [$fresh->id]);
    expect(Sponsorship::query()->pluck('id')->sort()->values()->all())->toBe([$started->id, $clicked->id]);

    promoSecPanel($this, $this->s->admin);
    $this->get('/admin/sponsorships/'.$started->id.'/edit')->assertOk();
    Livewire::test(EditSponsorship::class, ['record' => $started->id])->assertActionHidden(DeleteAction::class);
});

it('scrive nel registro di sicurezza l’approvazione e il rifiuto degli ordini', function (): void {
    $paid = promoSecOrder(SponsorshipOrderStatus::Paid, ['payment_intent_id' => 'pi_ok', 'paid_at' => now()->subHour()]);
    promoSec($this->s->admin)->postJson('/api/v1/editorial/sponsorship-orders/'.$paid->id.'/approve')->assertOk();
    $log = promoSecLog('ordine_sponsorizzazione_approvato');
    expect($log)->not->toBeNull()
        ->and($log->causer_id)->toBe($this->s->admin->id)
        ->and($log->subject_id)->toBe($this->s->ownerA->id)
        ->and($log->getProperty('ordine_id'))->toBe($paid->id)
        ->and($log->getProperty('importo_centesimi'))->toBe(15000)
        ->and($log->getProperty('rimborso'))->toBeFalse()
        ->and($log->getProperty('canale'))->toBe('app');

    $refund = promoSecOrder(SponsorshipOrderStatus::Paid, ['payment_intent_id' => 'pi_back', 'paid_at' => now()->subHour()]);
    promoSec($this->s->admin)->postJson('/api/v1/editorial/sponsorship-orders/'.$refund->id.'/reject', ['reason' => 'Fuori tema'])->assertOk();
    $log = promoSecLog('ordine_sponsorizzazione_rifiutato');
    expect($log?->getProperty('ordine_id'))->toBe($refund->id)
        ->and($log?->getProperty('rimborso'))->toBeTrue()
        ->and($log?->getProperty('canale'))->toBe('app');

    // Dal pannello, lo stesso servizio e la stessa riga.
    $requested = promoSecOrder(SponsorshipOrderStatus::Requested);
    promoSecPanel($this, $this->s->admin);
    $this->get('/admin/sponsorship-orders')->assertOk();
    Livewire::test(ListOrders::class)
        ->callTableAction('reject', $requested, ['reason' => 'Non adatto'])->assertHasNoTableActionErrors();
    $log = promoSecLog('ordine_sponsorizzazione_rifiutato');
    expect($log?->getProperty('ordine_id'))->toBe($requested->id)
        ->and($log?->getProperty('rimborso'))->toBeFalse()
        ->and($log?->getProperty('canale'))->toBeNull();
});

it('scrive nel registro di sicurezza ogni cambio del listino, con il prezzo prima e dopo', function (): void {
    $id = promoSec($this->s->admin)->postJson('/api/v1/editorial/sponsorship-prices', ['placement' => 'home_hero', 'months' => 3, 'amount' => '450.00'])
        ->assertCreated()->json('data.id');
    $log = promoSecLog('listino_sponsorizzazioni_modificato');
    expect($log?->getProperty('operazione'))->toBe('creata')
        ->and($log?->getProperty('prezzo_dopo_centesimi'))->toBe(45000)
        ->and($log?->causer_id)->toBe($this->s->admin->id)
        ->and($log?->getProperty('canale'))->toBe('app');

    promoSec($this->s->admin)->patchJson('/api/v1/editorial/sponsorship-prices/'.$id, ['amount' => '500'])->assertOk();
    $log = promoSecLog('listino_sponsorizzazioni_modificato');
    expect($log?->getProperty('operazione'))->toBe('modificata')
        ->and($log?->getProperty('prezzo_prima_centesimi'))->toBe(45000)
        ->and($log?->getProperty('prezzo_dopo_centesimi'))->toBe(50000)
        ->and($log?->getProperty('campi'))->toBe(['amount_cents']);

    // Dal pannello: modifica ed eliminazione.
    promoSecPanel($this, $this->s->admin);
    $this->get('/admin/sponsorship-prices/'.$id.'/edit')->assertOk();
    Livewire::test(EditPrice::class, ['record' => $id])
        ->fillForm(['amount_cents' => '520.00'])->call('save')->assertHasNoFormErrors();
    $log = promoSecLog('listino_sponsorizzazioni_modificato');
    expect($log?->getProperty('prezzo_prima_centesimi'))->toBe(50000)
        ->and($log?->getProperty('prezzo_dopo_centesimi'))->toBe(52000)
        ->and($log?->getProperty('canale'))->toBeNull();

    Livewire::test(EditPrice::class, ['record' => $id])->callAction(DeleteAction::class);
    expect(SponsorshipPrice::query()->count())->toBe(0);
    $log = promoSecLog('listino_sponsorizzazioni_modificato');
    expect($log?->getProperty('operazione'))->toBe('eliminata')
        ->and($log?->getProperty('prezzo_prima_centesimi'))->toBe(52000);
});

it('scrive nel registro di sicurezza i cambi dei banner, con le sole chiavi cambiate', function (): void {
    promoSec($this->s->admin)->putJson('/api/v1/editorial/sponsorship-banners', ['web_enabled' => true, 'android_enabled' => true])->assertOk();
    promoSec($this->s->admin)->putJson('/api/v1/editorial/sponsorship-banners', ['web_enabled' => false, 'android_enabled' => true])->assertOk();
    $log = promoSecLog('banner_sponsorizzazioni_modificato');
    expect($log?->getProperty('modifiche'))->toBe(['web_enabled' => ['prima' => true, 'dopo' => false]])
        ->and($log?->causer_id)->toBe($this->s->admin->id)
        ->and($log?->getProperty('canale'))->toBe('app');
    $count = Activity::query()->where('description', 'banner_sponsorizzazioni_modificato')->count();

    // Salvare senza cambiare niente non scrive.
    promoSec($this->s->admin)->putJson('/api/v1/editorial/sponsorship-banners', ['web_enabled' => false, 'android_enabled' => true])->assertOk();
    expect(Activity::query()->where('description', 'banner_sponsorizzazioni_modificato')->count())->toBe($count);

    promoSecPanel($this, $this->s->admin);
    $this->get('/admin/sponsorship-banners')->assertOk();
    Livewire::test(SponsorshipBanners::class)->fillForm(['web_enabled' => false, 'android_enabled' => false])
        ->call('save')->assertHasNoFormErrors();
    $log = promoSecLog('banner_sponsorizzazioni_modificato');
    expect($log?->getProperty('modifiche'))->toBe(['android_enabled' => ['prima' => true, 'dopo' => false]])
        ->and($log?->causer_id)->toBe($this->s->admin->id)
        ->and($log?->getProperty('canale'))->toBeNull();
});
