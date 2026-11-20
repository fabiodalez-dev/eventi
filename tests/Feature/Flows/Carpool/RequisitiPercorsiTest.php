<?php

declare(strict_types=1);

use App\Enums\RideRequestStatus;
use App\Enums\RideStatus;
use App\Models\CarpoolAudit;
use App\Models\CarpoolProfile;
use App\Models\RideOffer;
use App\Models\RideRequest;
use App\Models\RideSearch;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/*
 * I requisiti dei passaggi lungo il tempo: chi si iscrive e li completa uno alla volta,
 * chi li perde (email, WhatsApp, maggiore età) con un accordo in corso, le regole che
 * cambiano versione, il servizio spento. Protegge che a ogni passo la persona sappia cosa
 * manca e che gli accordi futuri si chiudano avvisando l'altra parte.
 */
require_once __DIR__.'/percorsi-carpool.php';

/** Una nuova versione delle regole, con il suo testo, come quando vengono aggiornate. */
function percorsoCpNewTerms($test): string
{
    $version = 'percorsi-'.Str::lower(Str::random(8));
    File::copy(resource_path('legal/carpool/'.config('carpool.terms_version').'.it.md'), resource_path('legal/carpool/'.$version.'.it.md'));
    config(['carpool.terms_version' => $version]);
    $test->newTerms = $version;

    return $version;
}

beforeEach(fn () => cpSetup($this));

afterEach(function (): void {
    if (isset($this->newTerms)) {
        File::delete(resource_path('legal/carpool/'.$this->newTerms.'.it.md'));
    }
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('una persona appena iscritta completa email, WhatsApp e dichiarazione, e a ogni passo la pagina dei requisiti dice cosa manca', function (): void {
    $offer = cpOffer($this);
    $user = User::factory()->unverified()->create();
    $reason = function () use ($user): ?string {
        flussoCpWeb($this, $user);

        return $this->getJson(route('carpool.requirements'))->assertOk()->json('data.access.reason');
    };
    $declare = fn () => cpAction($this, $user, 'declare', ['adult' => true, 'terms' => true, 'version' => config('carpool.terms_version')]);

    expect($reason())->toBe('email');
    $this->get(route('carpool.requirements'))->assertOk()->assertSee(__('carpool.email'));
    $declare()->assertForbidden();

    $user->forceFill(['email_verified_at' => now()])->save();
    expect($reason())->toBe('whatsapp');
    $declare()->assertForbidden();
    expect(CarpoolProfile::where('user_id', $user->id)->exists())->toBeFalse();

    $user->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'nuovo-'.$user->id)])->save();
    expect($reason())->toBe('adult');
    $this->getJson(route('carpool.dates', $this->date))->assertJsonPath('data.offers', []);

    $this->post(route('carpool.action', 'declare'), ['request_key' => (string) Str::uuid(), 'adult' => '1', 'terms' => '1', 'version' => config('carpool.terms_version')])
        ->assertRedirect(route('carpool.requirements'))->assertSessionHas('status');
    expect($reason())->toBeNull();
    $this->getJson(route('carpool.dates', $this->date))->assertJsonCount(1, 'data.offers');
    expect(cpRequest($this, $offer, $user)->status)->toBe(RideRequestStatus::Pending)
        ->and(CarpoolAudit::where('action', 'adult_and_terms_accepted')->where('actor_id', $user->id)->count())->toBe(1);
});

it('cambiate le regole, il passeggero confermato resta in viaggio e in chat, ma per chiedere altro le riaccetta dal sito', function (): void {
    $ride = cpAccepted($this);
    $back = cpOffer($this, carpoolPerson(), ['leg' => 'return', 'zone' => 'Centro', 'departure_at' => '2026-10-10T23:00:00Z']);
    $version = percorsoCpNewTerms($this);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.requirements'))->assertJsonPath('data.access.reason', 'terms')->assertJsonPath('data.access.terms_version', $version);
    $this->get(route('carpool.requirements'))->assertOk();
    cpAction($this, $this->passenger, 'request', ['offer_id' => $back->id, 'revision' => 1, 'seats' => 1])->assertForbidden();
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Accepted);
    percorsoCpSend($this, $this->passenger, percorsoCpChat($ride), 'Ci vediamo comunque alle 20')->assertOk();

    percorsoCpPost($this, $this->passenger, 'declare', ['adult' => '1', 'terms' => '1', 'version' => $version])->assertRedirect(route('carpool.requirements'));
    expect(CarpoolProfile::where('user_id', $this->passenger->id)->value('terms_version'))->toBe($version);
    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.requirements'))->assertJsonPath('data.access.reason', null);
    expect(cpRequest($this, $back)->status)->toBe(RideRequestStatus::Pending)->and($ride->fresh()->status)->toBe(RideRequestStatus::Accepted);
});

it('il conducente che perde la verifica WhatsApp vede annullata l\'offerta, i passeggeri sono avvisati e, riverificato, ne pubblica una nuova', function (): void {
    $ride = cpAccepted($this);
    $waiting = carpoolPerson();
    $pending = cpRequest($this, $ride->offer, $waiting);

    $this->driver->forceFill(['whatsapp_verified_at' => null])->save();
    $offer = $ride->offer->fresh();
    expect($offer->status)->toBe(RideStatus::Cancelled)->and($offer->close_reason)->toBe('requirements_or_event_changed')
        ->and($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($pending->fresh()->status)->toBe(RideRequestStatus::Cancelled)
        ->and($this->passenger->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1)
        ->and($waiting->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1);

    flussoCpWeb($this, $this->driver);
    $this->get(route('carpool.index'))->assertOk()->assertSee(__('carpool.profile_blocked.title'))->assertSee(__('carpool.profile_blocked.whatsapp'));
    $this->getJson(route('carpool.index').'?tab=history')->assertJsonPath('data.offers.0.status', 'cancelled');
    cpAction($this, $this->driver, 'offer', ['occurrence_id' => $this->date->id, 'leg' => 'outbound', 'zone' => 'Arcella', 'departure_at' => '2026-10-10T18:00:00Z',
        'capacity' => 3, 'accessibility' => 'not_specified', 'driver_declaration' => true])->assertForbidden()->assertJsonPath('error.message', __('carpool.errors.eligibility'));

    $this->driver->forceFill(['whatsapp_verified_at' => now()])->save();
    $new = cpOffer($this, data: ['zone' => 'Arcella']);
    expect($new->status)->toBe(RideStatus::Open)->and($offer->fresh()->status)->toBe(RideStatus::Cancelled)
        ->and(RideOffer::where('status', 'open')->count())->toBe(1);
});

it('il passeggero che perde la conferma dell\'email perde il posto, la chat si chiude e il conducente ritrova il posto libero', function (): void {
    $ride = cpAccepted($this);
    $chat = percorsoCpChat($ride);

    $this->passenger->forceFill(['email_verified_at' => null])->save();
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)->and($ride->fresh()->close_reason)->toBe('requirements_or_block_changed')
        ->and($chat->fresh()->read_only_at)->not->toBeNull()
        ->and($this->driver->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1);

    flussoCpWeb($this, $this->passenger);
    $this->getJson(route('carpool.requirements'))->assertJsonPath('data.access.reason', 'email');
    percorsoCpSend($this, $this->passenger, $chat, 'Ci sono ancora?')->assertForbidden();
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.offer', $ride->offer))->assertJsonPath('data.offer.available', 3)->assertJsonPath('data.offer.status', 'open');
    $this->getJson(route('carpool.chat', $chat))->assertOk()->assertJsonPath('data.writable', false);
});

it('con il servizio spento le pagine e l\'app rispondono 404 senza toccare gli accordi, e riaccese tornano come prima', function (): void {
    $ride = cpAccepted($this);
    config(['carpool.enabled' => false]);

    flussoCpWeb($this, $this->passenger);
    foreach ([route('carpool.index'), route('carpool.requirements'), route('carpool.dates', $this->date), route('carpool.request', $ride), route('carpool.chats')] as $url) {
        $this->get($url)->assertNotFound();
    }
    Sanctum::actingAs($this->passenger->fresh());
    $this->getJson('/api/v1/carpool/me')->assertNotFound();
    cpAction($this, $this->passenger, 'withdraw', ['request_id' => $ride->id])->assertNotFound();
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Accepted);

    config(['carpool.enabled' => true]);
    $this->getJson('/api/v1/carpool/me')->assertOk()->assertJsonPath('data.requests.0.status', 'accepted');
    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.request', $ride))->assertOk();
});

it('la dichiarazione senza spuntare maggiore età o regole torna al modulo con gli errori e non abilita nulla', function (): void {
    $user = carpoolPerson(false);
    flussoCpWeb($this, $user);

    $this->from(route('carpool.requirements'))->post(route('carpool.action', 'declare'), ['request_key' => (string) Str::uuid(), 'version' => config('carpool.terms_version')])
        ->assertRedirect(route('carpool.requirements'))->assertSessionHasErrors(['adult', 'terms']);
    $this->from(route('carpool.requirements'))->post(route('carpool.action', 'declare'), ['request_key' => (string) Str::uuid(), 'adult' => '1', 'version' => config('carpool.terms_version')])
        ->assertSessionHasErrors('terms')->assertSessionDoesntHaveErrors('adult');
    expect(CarpoolProfile::where('user_id', $user->id)->whereNotNull('adult_declared_at')->exists())->toBeFalse();
    $this->getJson(route('carpool.requirements'))->assertJsonPath('data.access.reason', 'adult');

    $this->post(route('carpool.action', 'declare'), ['request_key' => (string) Str::uuid(), 'adult' => '1', 'terms' => '1', 'version' => config('carpool.terms_version')])
        ->assertSessionHasNoErrors();
    // La pagina successiva ricarica l'utente, come farebbe il browser.
    flussoCpWeb($this, $user);
    $this->getJson(route('carpool.requirements'))->assertJsonPath('data.access.reason', null);
});

it('la rettifica dell\'età dal sito chiede la conferma, poi annulla l\'offerta futura, spegne le ricerche e avvisa il passeggero', function (): void {
    $ride = cpAccepted($this);
    $search = cpDiscovery($this, $this->driver, 'search', percorsoCpSearch($this, ['leg' => 'return', 'earliest_at' => '2026-10-10T22:30:00Z', 'latest_at' => '2026-10-10T23:30:00Z']))
        ->assertOk()->json('data.id');
    flussoCpWeb($this, $this->driver);
    $this->get(route('carpool.requirements'))->assertOk()->assertSee('data-carpool-revoke', false);

    $this->from(route('carpool.requirements'))->post(route('carpool.action', 'revoke-adult'), ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('confirm');
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Accepted);

    $this->post(route('carpool.action', 'revoke-adult'), ['request_key' => (string) Str::uuid(), 'confirm' => '1'])->assertRedirect(route('carpool.requirements'));
    expect($ride->offer->fresh()->status)->toBe(RideStatus::Cancelled)->and($ride->fresh()->status)->toBe(RideRequestStatus::Cancelled)
        ->and(RideSearch::findOrFail($search)->active)->toBeFalse()
        ->and($this->passenger->notifications()->where('type', 'carpool.cancelled')->count())->toBe(1)
        ->and(CarpoolAudit::where('action', 'adult_declaration_revoked')->where('actor_id', $this->driver->id)->count())->toBe(1);
    flussoCpWeb($this, $this->driver);
    $this->getJson(route('carpool.requirements'))->assertJsonPath('data.access.reason', 'adult');
});

it('chi non ha più i requisiti ritrova l\'offerta che aveva chiesto senza il modulo e può comunque rinunciare', function (): void {
    $offer = cpOffer($this);
    $ride = cpRequest($this, $offer);
    percorsoCpNewTerms($this);

    flussoCpWeb($this, $this->passenger);
    $this->get(route('carpool.offer', $offer))->assertOk()->assertSee(__('carpool.web.blocked_title'))->assertDontSee('data-offer-request-form', false);
    $this->get(route('carpool.request', $ride))->assertOk()->assertSee('data-request-withdraw', false);
    $this->post(route('carpool.action', 'withdraw'), ['request_key' => (string) Str::uuid(), 'request_id' => $ride->id])->assertRedirect(route('carpool.request', $ride));
    expect($ride->fresh()->status)->toBe(RideRequestStatus::Withdrawn)->and(RideRequest::count())->toBe(1)
        ->and($this->driver->notifications()->where('type', 'carpool.withdrawn')->count())->toBe(1);
});
