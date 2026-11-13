<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoFlussiCommunity.php';

use App\Enums\WhatsappChallengeStatus;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Models\SavedEvent;
use App\Models\User;
use App\Models\WhatsappChallenge;
use App\Services\Carpool\UnifiedNotifications;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/*
 * Gli avvisi che la community produce e la verifica del numero WhatsApp che
 * apre la partecipazione: invio del codice (Kapso finto), conferma, rinvio a
 * più tardi e revoca, sul sito e nell'app.
 */

beforeEach(function (): void {
    fcomPrepara();
    $this->city = testCity(['is_active' => true]);
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
    $this->data = fcomData($this->city, $this->category, '2026-09-15 21:00', ['title' => 'Festival in piazza']);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('raccoglie negli avvisi follow e commenti, li mostra nella tendina e segna letti solo quelli già visti', function (): void {
    $lia = fcomPersona('lia_avvisi', 'public', 'Lia Avvisi');
    $max = fcomPersona('max_fan');
    $post = fcomConsiglio($lia, $this->data, 'Musica fino a tardi');

    $this->actingAs($max, 'web')->post('/persone/'.$lia->id.'/segui')->assertRedirect();
    $this->post('/bacheca/post/'.$post->id.'/commenti', ['body' => 'Che bello!'])->assertRedirect();
    expect($lia->unreadNotifications()->count())->toBe(2);

    $this->actingAs($lia, 'web')->get('/avvisi')->assertOk()
        ->assertSee(__('community.notifications.follow'))->assertSee(__('community.notifications.comment'))
        ->assertSee(__('community.new'))->assertSee(route('community.inbox.read'));

    $tendina = $this->get('/avvisi/ultimi')->assertOk()
        ->assertSee(url('/bacheca/post/'.$post->id))->assertSee(url('/persone-che-mi-seguono'));
    expect($tendina->headers->get('Cache-Control'))->toContain('no-store');
    $soglia = (int) $tendina->headers->get('X-Inbox-Watermark');
    expect($soglia)->toBe(app(UnifiedNotifications::class)->watermark($lia))->and($soglia)->toBeGreaterThan(0);

    // Un avviso arrivato dopo l'apertura della tendina resta nuovo.
    $this->actingAs(fcomPersona('nuovo_fan'), 'web')->post('/persone/'.$lia->id.'/segui')->assertRedirect();
    $this->actingAs($lia, 'web')->from('/avvisi')->post('/avvisi/letti', ['through' => $soglia])->assertRedirect('/avvisi');
    expect($lia->unreadNotifications()->count())->toBe(1)->and($lia->notifications()->count())->toBe(3);

    $this->post('/avvisi/letti', ['through' => 'tanti'])->assertSessionHasErrors('through');
    $this->from('/avvisi')->post('/avvisi/letti')->assertRedirect('/avvisi');
    expect($lia->unreadNotifications()->count())->toBe(0);
    $this->get('/avvisi')->assertOk()->assertDontSee('<span class="text-xs text-brand">'.__('community.new').'</span>', false);
});

it('verifica il numero dal sito: riceve il codice, sbaglia una volta, conferma e crea il profilo', function (): void {
    $nora = User::factory()->create(['first_name' => 'Nora']);

    $this->actingAs($nora, 'web')->get('/verifica-whatsapp')->assertOk()->assertSee(__('community.whatsapp.send'));
    $this->get('/profilo-social')->assertOk()->assertDontSee('action="'.route('community.settings.update').'"', false);

    $this->from('/verifica-whatsapp')->post('/verifica-whatsapp', ['phone' => '+39 333 765 4321'])
        ->assertRedirect('/verifica-whatsapp')
        ->assertSessionHas('status', __('community.whatsapp.sent'))
        ->assertSessionHas('whatsapp_challenge');

    $sfida = WhatsappChallenge::query()->sole();
    expect($sfida->status)->toBe(WhatsappChallengeStatus::Sent)->and($sfida->user_id)->toBe($nora->id);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $richiesta): bool => str_contains($richiesta->url(), 'api.kapso.ai')
        && $richiesta['to'] === '393337654321' && $richiesta->hasHeader('X-API-Key', 'chiave-di-prova'));
    $codice = fcomCodiceInviato();
    expect($codice)->toMatch('/^[0-9]{6}$/');

    $sbagliato = $codice === '000000' ? '111111' : '000000';
    $this->from('/verifica-whatsapp')->post('/verifica-whatsapp/conferma', ['challenge_id' => $sfida->id, 'code' => $sbagliato])
        ->assertRedirect('/verifica-whatsapp')->assertSessionHasErrors('code');
    expect($sfida->fresh()->attempts)->toBe(1)->and($nora->fresh()->whatsapp_verified_at)->toBeNull();

    $this->post('/verifica-whatsapp/conferma', ['challenge_id' => $sfida->id, 'code' => $codice])
        ->assertRedirect(route('community.settings'))
        ->assertSessionHas('status', __('community.whatsapp.done'))
        ->assertSessionMissing('whatsapp_challenge');
    expect($nora->fresh()->isWhatsappVerified())->toBeTrue()
        ->and($nora->fresh()->whatsapp_prompted_at)->not->toBeNull()
        ->and($sfida->fresh()->consumed_at)->not->toBeNull();
    $this->assertDatabaseHas('activity_log', ['log_name' => 'community', 'event' => 'whatsapp_verified', 'causer_id' => $nora->id]);

    $this->actingAs($nora->fresh(), 'web')->get('/verifica-whatsapp')->assertOk()->assertSee(__('community.whatsapp.active'));
    $this->post('/profilo-social', ['handle' => 'nora_verificata', 'display_name' => 'Nora', 'visibility' => 'public'])
        ->assertSessionHasNoErrors();
    expect(CommunityProfile::query()->where('user_id', $nora->id)->value('handle'))->toBe('nora_verificata');
});

it('rimanda la verifica a più tardi e poi la revoca: profilo e consigli spariscono, i dati restano', function (): void {
    $enzo = fcomPersona('enzo_revoca', 'public', 'Enzo Revoca');
    $post = fcomConsiglio($enzo, $this->data, 'Concerto gratuito in piazza');

    $this->actingAs($enzo, 'web')->post('/verifica-whatsapp/dopo')->assertRedirect(route('account.feed'));
    expect($enzo->fresh()->whatsapp_prompted_at)->not->toBeNull();
    $this->withSession(['url.intended' => url('/bacheca')])->post('/verifica-whatsapp/dopo')->assertRedirect(url('/bacheca'));

    $this->from('/verifica-whatsapp')->delete('/verifica-whatsapp')
        ->assertRedirect('/verifica-whatsapp')->assertSessionHas('status', __('community.whatsapp.revoked'));
    $enzo = $enzo->fresh();
    expect($enzo->whatsapp_verified_at)->toBeNull()
        ->and($enzo->whatsapp_phone)->toBeNull()
        ->and($enzo->whatsapp_phone_hash)->toBeNull();
    $this->assertDatabaseHas('activity_log', ['log_name' => 'community', 'event' => 'whatsapp_revoked', 'causer_id' => $enzo->id]);

    fcomEsci();
    $this->get('/persone')->assertOk()->assertDontSee('Enzo Revoca');
    $this->get('/persone/enzo_revoca')->assertNotFound();
    $this->get('/bacheca/post/'.$post->id)->assertNotFound();

    // Niente è cancellato: profilo, consiglio e salvataggio tornano visibili con una nuova verifica.
    expect(CommunityProfile::query()->where('user_id', $enzo->id)->exists())->toBeTrue()
        ->and(CommunityPost::query()->whereKey($post->id)->exists())->toBeTrue()
        ->and(SavedEvent::query()->where('user_id', $enzo->id)->count())->toBe(1);

    // Senza numero non si ripubblica, ma ritirare il consiglio resta possibile.
    $composizione = '/salvataggi/'.$this->data->id.'/visibilita';
    $this->actingAs($enzo, 'web')->put($composizione, ['visibility' => 'public', 'body' => 'Di nuovo'])->assertForbidden();
    $this->from($composizione)->put($composizione, ['visibility' => 'private'])->assertRedirect($composizione);
    expect(CommunityPost::query()->whereKey($post->id)->exists())->toBeFalse();
});

it('verifica il numero dall app, lo protegge da un secondo account e lo revoca', function (): void {
    $utente = User::factory()->create();
    $altro = User::factory()->create();
    $verifica = '/api/v1/community/whatsapp';

    Sanctum::actingAs($utente);
    $this->getJson($verifica)->assertOk()
        ->assertJsonPath('data.available', true)->assertJsonPath('data.verified', false)
        ->assertJsonPath('data.challenge_id', null)->assertJsonPath('data.access.reason', 'whatsapp');

    $sfida = $this->postJson($verifica, ['phone' => '+393401112233'])->assertOk()
        ->assertJsonPath('data.delivery', 'sent')->json('data.challenge_id');
    $this->getJson($verifica)->assertOk()->assertJsonPath('data.challenge_id', $sfida);
    $codice = fcomCodiceInviato();

    // Lo stesso numero chiesto da un altro account non riceve un secondo messaggio.
    Sanctum::actingAs($altro);
    $this->postJson($verifica, ['phone' => '+39 340 111 2233'])->assertUnprocessable();
    Http::assertSentCount(1);

    Sanctum::actingAs($utente);
    $this->postJson($verifica.'/confirm', ['challenge_id' => $sfida, 'code' => 'abc'])->assertUnprocessable();
    $this->postJson($verifica.'/confirm', ['challenge_id' => $sfida, 'code' => $codice === '999999' ? '888888' : '999999'])
        ->assertUnprocessable()->assertJsonPath('error.fields.code.0', __('community.whatsapp.invalid_code'));
    $this->postJson($verifica.'/confirm', ['challenge_id' => $sfida, 'code' => $codice])->assertOk()->assertJsonPath('data.verified', true);
    $this->getJson($verifica)->assertOk()->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.access.eligible', true)->assertJsonPath('data.challenge_id', null);
    $this->getJson('/api/v1/community/profile')->assertOk()->assertJsonPath('data.verified', true);

    $this->postJson($verifica.'/skip')->assertOk()->assertJsonPath('data.prompted', true);
    $this->deleteJson($verifica)->assertOk()->assertJsonPath('data.verified', false);
    expect($utente->fresh()->whatsapp_verified_at)->toBeNull()->and($utente->fresh()->whatsapp_phone_hash)->toBeNull();
    $this->getJson($verifica)->assertOk()->assertJsonPath('data.verified', false)->assertJsonPath('data.access.reason', 'whatsapp');
    // Le sfide restano come storico consumato: tengono vivi i limiti d'invio.
    expect(WhatsappChallenge::query()->whereNull('consumed_at')->count())->toBe(0)
        ->and(WhatsappChallenge::query()->count())->toBe(1);
});
