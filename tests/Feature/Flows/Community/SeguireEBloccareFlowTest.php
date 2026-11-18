<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoFlussiCommunity.php';

use App\Models\CommunityComment;
use App\Models\User;
use App\Models\UserBlock;
use App\Notifications\CommunityNotification;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 * Le relazioni fra persone: seguire e smettere, chi può essere seguito, la
 * pagina di chi mi segue, e il blocco che nasconde i contenuti in entrambe le
 * direzioni senza ricreare niente quando lo si toglie.
 */

beforeEach(function (): void {
    fcomPrepara();
    $this->city = testCity(['is_active' => true]);
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
    $this->data = fcomData($this->city, $this->category, '2026-09-15 21:00', ['title' => 'Jazz nel chiostro']);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('segue una persona dal suo profilo, la ritrova nelle liste e smette senza notificarla due volte', function (): void {
    $elena = fcomPersona('elena_concerti', 'public', 'Elena Concerti');
    $marco = fcomPersona('marco_teatro', 'public', 'Marco Teatro');
    $profilo = '/persone/elena_concerti';

    $this->actingAs($marco, 'web')->get($profilo)->assertOk()->assertSee(__('community.follow_person', ['name' => 'Elena Concerti']));
    $this->from($profilo)->post('/persone/'.$elena->id.'/segui')
        ->assertRedirect($profilo)->assertSessionHas('status', __('community.followed'));

    $relazione = DB::table('followables')->where('user_id', $marco->id)->sole();
    expect((int) $relazione->followable_id)->toBe($elena->id)
        ->and($relazione->followable_type)->toBe($elena->getMorphClass())
        // Niente richieste da approvare: seguire è accettato subito.
        ->and($relazione->accepted_at)->not->toBeNull();

    $avviso = $elena->notifications()->sole();
    expect($avviso->type)->toBe(CommunityNotification::class)
        ->and($avviso->data['kind'])->toBe('follow')
        ->and($avviso->data['actor_id'])->toBe($marco->id)
        ->and($avviso->data['path'])->toBe('/persone-che-mi-seguono');

    $this->get('/persone-che-mi-seguono')->assertOk()->assertSee('Elena Concerti');
    $this->get($profilo)->assertSee(__('community.unfollow_person', ['name' => 'Elena Concerti']));
    $this->actingAs($elena, 'web')->get('/persone-che-mi-seguono?tab=followers')->assertOk()->assertSee('Marco Teatro');
    $this->getJson('/api/v1/community/people/elena_concerti')->assertJsonPath('data.profile.followers_count', 1);

    $this->actingAs($marco, 'web')->from($profilo)->delete('/persone/'.$elena->id.'/segui')
        ->assertRedirect($profilo)->assertSessionHas('status', __('community.unfollowed'));
    expect(DB::table('followables')->count())->toBe(0);

    // Ripensarci entro il giorno non riempie gli avvisi di Elena.
    $this->post('/persone/'.$elena->id.'/segui')->assertSessionHas('status', __('community.followed'));
    expect(DB::table('followables')->count())->toBe(1)->and($elena->notifications()->count())->toBe(1);
});

it('rifiuta di seguire un profilo privato o se stessi e accetta quello riservato agli iscritti', function (): void {
    $privata = fcomPersona('giada_privata', 'private');
    $iscritti = fcomPersona('ivo_iscritti', 'members');
    $fan = User::factory()->create();

    Sanctum::actingAs($fan);
    // Un profilo «solo io» non si segue: per chi guarda non esiste.
    $this->postJson('/api/v1/community/people/'.$privata->id.'/follow')->assertNotFound();
    $this->postJson('/api/v1/community/people/99999/follow')->assertNotFound();
    $this->postJson('/api/v1/community/people/'.$iscritti->id.'/follow')->assertOk()->assertExactJson(['data' => ['ok' => true]]);
    $this->getJson('/api/v1/community/people/ivo_iscritti')->assertOk()->assertJsonPath('data.profile.is_following', true);

    Sanctum::actingAs($iscritti);
    $this->postJson('/api/v1/community/people/'.$iscritti->id.'/follow')->assertUnprocessable();

    // Se il profilo diventa privato sparisce, ma smettere di seguirlo resta possibile.
    $iscritti->communityProfile->update(['visibility' => 'private']);
    Sanctum::actingAs($fan);
    $this->getJson('/api/v1/community/people/ivo_iscritti')->assertNotFound();
    $this->deleteJson('/api/v1/community/people/'.$iscritti->id.'/follow')->assertOk();

    expect(DB::table('followables')->count())->toBe(0)
        ->and($privata->notifications()->count())->toBe(0)
        ->and($iscritti->notifications()->count())->toBe(1);
});

it('blocca una persona dal sito: spariscono i follow e i contenuti in entrambe le direzioni, e lo sblocco non li ricrea', function (): void {
    $anna = fcomPersona('anna_blocca', 'public', 'Anna Blocca');
    $bea = fcomPersona('bea_bloccata', 'public', 'Bea Bloccata');
    $post = fcomConsiglio($anna, $this->data, 'Serata imperdibile al chiostro');

    $this->actingAs($bea, 'web')->post('/persone/'.$anna->id.'/segui')->assertRedirect();
    $this->get('/bacheca')->assertOk()->assertSee('Serata imperdibile al chiostro');
    $this->actingAs($anna, 'web')->post('/persone/'.$bea->id.'/segui')->assertRedirect();
    expect(DB::table('followables')->count())->toBe(2);

    $this->from('/persone/bea_bloccata')->post('/persone/'.$bea->id.'/blocca')
        ->assertRedirect(route('community.followers'))->assertSessionHas('status', __('community.blocked'));
    expect(UserBlock::query()->where('user_id', $anna->id)->where('blocked_user_id', $bea->id)->exists())->toBeTrue()
        ->and(DB::table('followables')->count())->toBe(0);
    $this->get('/persone-che-mi-seguono?tab=blocks')->assertOk()->assertSee('Bea Bloccata');

    // Dalla parte di Bea, Anna e il suo consiglio non esistono più.
    $this->actingAs($bea, 'web')->get('/persone/anna_blocca')->assertNotFound();
    $this->get('/bacheca/post/'.$post->id)->assertNotFound();
    $this->get('/bacheca?scope=discover')->assertOk()->assertDontSee('Serata imperdibile al chiostro');
    $this->get('/persone')->assertOk()->assertDontSee('Anna Blocca');
    $this->post('/persone/'.$anna->id.'/segui')->assertNotFound();

    $this->actingAs($anna, 'web')->delete('/persone/'.$bea->id.'/blocca')
        ->assertRedirect(route('community.followers'))->assertSessionHas('status', __('community.unblocked'));
    expect(UserBlock::query()->count())->toBe(0)->and(DB::table('followables')->count())->toBe(0);

    $this->actingAs($bea, 'web')->get('/bacheca/post/'.$post->id)->assertOk()->assertSee('Serata imperdibile al chiostro');
    // Il follow non torna da solo: la bacheca di Bea resta vuota finché non segue di nuovo.
    $this->get('/bacheca')->assertOk()->assertDontSee('Serata imperdibile al chiostro');
});

it('nasconde i commenti di chi ho bloccato dall app e impedisce di rispondermi, finché non lo sblocco', function (): void {
    $autore = fcomPersona('ugo_autore', 'public', 'Ugo Autore');
    $alba = fcomPersona('alba_lettrice', 'public', 'Alba Lettrice');
    $bruno = fcomPersona('bruno_molesto', 'public', 'Bruno Molesto');
    $post = fcomConsiglio($autore, $this->data, 'Jazz al chiostro, posti limitati');
    $commenti = '/api/v1/community/posts/'.$post->id.'/comments';

    Sanctum::actingAs($bruno);
    $this->postJson($commenti, ['body' => 'Commento di Bruno'])->assertCreated();
    Sanctum::actingAs($alba);
    $diAlba = $this->postJson($commenti, ['body' => 'Commento di Alba'])->assertCreated()->json('data.id');
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertOk()->assertJsonCount(2, 'data.comments');

    $this->postJson('/api/v1/community/people/'.$bruno->id.'/block')->assertOk()->assertExactJson(['data' => ['ok' => true]]);
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertOk()
        ->assertJsonCount(1, 'data.comments')->assertJsonPath('data.comments.0.body', 'Commento di Alba');

    // Bruno non vede più il commento di Alba, quindi non può nemmeno rispondergli.
    Sanctum::actingAs($bruno);
    $this->postJson($commenti, ['body' => 'Risposta indesiderata', 'parent_id' => $diAlba])->assertNotFound();
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertOk()
        ->assertJsonCount(1, 'data.comments')->assertJsonPath('data.comments.0.body', 'Commento di Bruno');

    Sanctum::actingAs($alba);
    $this->deleteJson('/api/v1/community/people/'.$bruno->id.'/block')->assertOk();
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertOk()->assertJsonCount(2, 'data.comments');

    expect(CommunityComment::query()->count())->toBe(2)
        ->and($autore->notifications()->where('data->kind', 'comment')->count())->toBe(2)
        ->and($alba->notifications()->count())->toBe(0);
});

it('elenca nell app chi mi segue, chi seguo e chi ho bloccato, senza svelare i profili privati', function (): void {
    $io = fcomPersona('io_centrale', 'public', 'Io Centrale');
    $primo = fcomPersona('primo_fan', 'public', 'Primo Fan');
    $seconda = fcomPersona('seconda_fan', 'private', 'Seconda Fan');
    $seguito = fcomPersona('seguito_mio', 'public', 'Seguito Mio');
    $molesto = fcomPersona('molesto_x', 'public', 'Molesto X');

    foreach ([$primo, $seconda] as $fan) {
        Sanctum::actingAs($fan);
        $this->postJson('/api/v1/community/people/'.$io->id.'/follow')->assertOk();
    }
    Sanctum::actingAs($io);
    $this->postJson('/api/v1/community/people/'.$seguito->id.'/follow')->assertOk();
    $this->postJson('/api/v1/community/people/'.$molesto->id.'/block')->assertOk();

    $risposta = $this->getJson('/api/v1/community/followers')->assertOk();
    // I più recenti prima; chi ha il profilo privato resta «Utente», senza nome utente.
    expect($risposta->json('data.followers'))->toHaveCount(2)
        ->and($risposta->json('data.followers.0.user_id'))->toBe($seconda->id)
        ->and($risposta->json('data.followers.0.display_name'))->toBe(__('community.member'))
        ->and($risposta->json('data.followers.0.handle'))->toBeNull()
        ->and($risposta->json('data.followers.1.display_name'))->toBe('Primo Fan')
        ->and($risposta->json('data.followers.1.handle'))->toBe('primo_fan')
        ->and($risposta->json('data.following'))->toHaveCount(1)
        ->and($risposta->json('data.following.0.handle'))->toBe('seguito_mio')
        ->and($risposta->json('data.blocks'))->toBe([['user_id' => $molesto->id, 'display_name' => 'Molesto X']])
        ->and($risposta->json('data.has_more'))->toBeFalse();

    $this->actingAs($io, 'web')->get('/persone-che-mi-seguono?tab=followers')->assertOk()
        ->assertSee('Primo Fan')->assertDontSee('Seconda Fan');
    expect($io->notifications()->where('data->kind', 'follow')->count())->toBe(2);
});
