<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoFlussiCommunity.php';

use App\Actions\Account\SaveOccurrences;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\Report;
use App\Models\SavedEvent;
use App\Models\User;
use App\Services\Community\Community;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
 * La bacheca: dal salvataggio privato al consiglio pubblico che arriva a chi
 * segue, la partecipazione dichiarata, i commenti con le loro regole di
 * cancellazione e le segnalazioni.
 */

beforeEach(function (): void {
    fcomPrepara();
    $this->city = testCity(['is_active' => true]);
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
    $this->data = fcomData($this->city, $this->category, '2026-09-15 21:00', ['title' => 'Notte di jazz al chiostro']);
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('salva una data, la rende un consiglio pubblico e lo fa arrivare nella bacheca di chi segue', function (): void {
    $autrice = fcomPersona('irene_consiglia', 'public', 'Irene Consiglia');
    $follower = fcomPersona('paolo_segue', 'public', 'Paolo Segue');
    $estraneo = fcomPersona('quinto_estraneo', 'public', 'Quinto Estraneo');
    $composizione = '/salvataggi/'.$this->data->id.'/visibilita';
    $testo = 'Il trio migliore della stagione';

    $this->actingAs($follower, 'web')->post('/persone/'.$autrice->id.'/segui')->assertRedirect();

    $this->actingAs($autrice, 'web')->post('/salvataggi', ['occurrence_id' => $this->data->id])->assertRedirect()->assertSessionHasNoErrors();
    $salvato = SavedEvent::query()->where('user_id', $autrice->id)->sole();
    expect($salvato->visibility->value)->toBe('private');

    $this->get($composizione)->assertOk()->assertSee('Notte di jazz al chiostro')->assertSee(__('community.public'));
    $this->from($composizione)->put($composizione, ['visibility' => 'public', 'intent' => 'recommend', 'body' => $testo])
        ->assertRedirect($composizione)->assertSessionHas('status', __('community.updated'));

    $post = CommunityPost::query()->where('user_id', $autrice->id)->sole();
    expect($post->occurrence_id)->toBe($this->data->id)
        ->and($post->saved_event_id)->toBe($salvato->id)
        ->and($post->body)->toBe($testo)
        ->and($post->intent->value)->toBe('recommend')
        ->and($post->published_at)->not->toBeNull()
        ->and($salvato->fresh()->visibility->value)->toBe('public');
    $this->assertDatabaseHas('activity_log', ['log_name' => 'community', 'event' => 'post_saved', 'subject_id' => $post->id, 'causer_id' => $autrice->id]);

    $this->actingAs($follower, 'web')->get('/bacheca')->assertOk()->assertSee($testo)->assertSee('Irene Consiglia');
    $this->get('/bacheca/post/'.$post->id)->assertOk()->assertSee($testo)->assertSee('Notte di jazz al chiostro');
    Sanctum::actingAs($follower);
    $this->getJson('/api/v1/community/feed')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $post->id)->assertJsonPath('data.0.author.handle', 'irene_consiglia');

    // Chi non la segue non lo trova nella sua bacheca, ma sì fra i consigli da scoprire.
    $this->actingAs($estraneo, 'web')->get('/bacheca')->assertOk()->assertDontSee($testo);
    $this->get('/bacheca?scope=discover')->assertOk()->assertSee($testo);

    // Il collegamento al consiglio di un profilo pubblico si apre anche senza account.
    fcomEsci();
    $this->get('/bacheca/post/'.$post->id)->assertOk()->assertSee($testo);
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertOk()->assertJsonPath('data.post.can_comment', false);
});

it('modifica e ritira il consiglio dall app: spariscono post e commenti, il salvataggio resta', function (): void {
    $autore = fcomPersona('rocco_autore', 'public', 'Rocco Autore');
    $lettore = fcomPersona('lia_lettrice');
    $lettore->follow($autore);
    app(SaveOccurrences::class)->one($autore, $this->data);
    $indirizzo = '/api/v1/community/saved/'.$this->data->id;

    Sanctum::actingAs($autore);
    $this->getJson($indirizzo)->assertOk()
        ->assertJsonPath('data.visibility', 'private')->assertJsonPath('data.post_id', null)
        ->assertJsonPath('data.intent', 'recommend')->assertJsonPath('data.access.can_publish', true);

    $postId = $this->putJson($indirizzo, ['visibility' => 'public', 'intent' => 'attend', 'body' => 'Ci vediamo sotto il palco'])
        ->assertOk()->assertJsonPath('data.visibility', 'public')->json('data.post_id');
    $this->putJson($indirizzo, ['visibility' => 'public', 'intent' => 'attend', 'body' => 'Ci vediamo in prima fila'])
        ->assertOk()->assertJsonPath('data.post_id', $postId);
    $this->getJson($indirizzo)->assertOk()->assertJsonPath('data.visibility', 'public')
        ->assertJsonPath('data.body', 'Ci vediamo in prima fila')->assertJsonPath('data.intent', 'attend');
    expect(CommunityPost::query()->count())->toBe(1);

    Sanctum::actingAs($lettore);
    $this->getJson('/api/v1/community/feed')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/community/posts/'.$postId.'/comments', ['body' => 'Vengo anche io'])->assertCreated();

    Sanctum::actingAs($autore);
    $this->putJson($indirizzo, ['visibility' => 'private'])->assertOk()->assertJsonPath('data.post_id', null);
    expect(CommunityPost::query()->count())->toBe(0)
        ->and(CommunityComment::query()->count())->toBe(0)
        ->and(SavedEvent::query()->where('user_id', $autore->id)->sole()->visibility->value)->toBe('private');

    Sanctum::actingAs($lettore);
    $this->getJson('/api/v1/community/posts/'.$postId)->assertNotFound();
    $this->getJson('/api/v1/community/feed')->assertOk()->assertJsonCount(0, 'data');
    // Un'altra persona non apre la composizione di una data che non ha salvato.
    $this->getJson($indirizzo)->assertNotFound();
});

it('dice ci vado dal sito, compare fra chi ci va e ritira la partecipazione senza perdere il salvataggio', function (): void {
    $giulia = fcomPersona('giulia_va', 'public', 'Giulia Va');
    $osservatore = fcomPersona('oscar_guarda');
    $partecipazione = '/salvataggi/'.$this->data->id.'/partecipazione';
    $stato = '/api/v1/community/occurrences/'.$this->data->id.'/attendance';

    $this->actingAs($giulia, 'web')->from('/persone/giulia_va')->post($partecipazione, ['going' => '1'])
        ->assertRedirect('/persone/giulia_va')->assertSessionHas('status', __('community.updated'));
    $this->assertDatabaseHas('community_attendances', ['user_id' => $giulia->id, 'occurrence_id' => $this->data->id]);
    // Dire «ci vado» salva la data, ma in privato: il consiglio resta un'altra cosa.
    expect(SavedEvent::query()->where('user_id', $giulia->id)->sole()->visibility->value)->toBe('private')
        ->and(CommunityPost::query()->count())->toBe(0);
    $this->assertDatabaseHas('activity_log', ['log_name' => 'community', 'event' => 'attendance_public', 'causer_id' => $giulia->id]);

    // Una richiesta senza la scelta non ritira niente in silenzio.
    $this->post($partecipazione, [])->assertSessionHasErrors('going');
    $this->assertDatabaseCount('community_attendances', 1);

    Sanctum::actingAs($osservatore);
    $this->getJson($stato)->assertOk()->assertJsonPath('data.going', false)->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.attendees.0.handle', 'giulia_va');
    $this->getJson('/api/v1/community/people/giulia_va')->assertOk()->assertJsonCount(1, 'data.participations');

    // Chi non ha un account vede il numero, non i nomi.
    fcomEsci();
    $this->getJson($stato)->assertOk()->assertJsonPath('data.count', 1)->assertJsonPath('data.attendees', [])
        ->assertJsonPath('data.access.reason', 'login');

    $this->actingAs($giulia, 'web')->post($partecipazione, ['going' => '0'])->assertRedirect();
    $this->assertDatabaseCount('community_attendances', 0);
    expect(SavedEvent::query()->where('user_id', $giulia->id)->count())->toBe(1);
    Sanctum::actingAs($osservatore);
    $this->getJson($stato)->assertOk()->assertJsonPath('data.count', 0)->assertJsonPath('data.attendees', []);
});

it('rifiuta dall app la partecipazione a chi non ha profilo, ha il profilo privato, non ha il numero o sceglie una data passata', function (): void {
    $passata = fcomData($this->city, $this->category, '2026-09-05 21:00');
    $senzaProfilo = fcomVerificato();
    $privata = fcomPersona('pia_privata', 'private');
    $pronta = fcomPersona('olga_pubblica');
    $soloEmail = User::factory()->create();
    $verso = fn ($data): string => '/api/v1/community/saved/'.$data->id.'/attendance';

    Sanctum::actingAs($senzaProfilo);
    $this->postJson($verso($this->data), ['going' => true])->assertUnprocessable()
        ->assertJsonPath('error.fields.attendance.0', __('community.profile_required'));
    Sanctum::actingAs($privata);
    $this->postJson($verso($this->data), ['going' => true])->assertUnprocessable()
        ->assertJsonPath('error.fields.attendance.0', __('community.attendance.private_profile'));
    Sanctum::actingAs($soloEmail);
    $this->postJson($verso($this->data), ['going' => true])->assertForbidden();
    // Tirarsi indietro invece è sempre permesso: non espone nessuno.
    $this->postJson($verso($this->data), ['going' => false])->assertOk()->assertJsonPath('data.going', false);

    Sanctum::actingAs($pronta);
    $this->postJson($verso($passata), ['going' => true])->assertUnprocessable()
        ->assertJsonPath('error.fields.attendance.0', __('community.attendance_unavailable'));
    $this->postJson($verso($this->data), ['going' => true])->assertOk()
        ->assertJsonPath('data.going', true)->assertJsonPath('data.count', 1);

    expect(DB::table('community_attendances')->pluck('user_id')->all())->toBe([$pronta->id])
        ->and(SavedEvent::query()->pluck('user_id')->all())->toBe([$pronta->id]);
});

it('commenta un consiglio, risponde a un commento, cancella il proprio ma non quello altrui', function (): void {
    $autrice = fcomPersona('rita_autrice', 'public', 'Rita Autrice');
    $sandro = fcomPersona('sandro_commenta', 'public', 'Sandro Commenta');
    $tina = fcomPersona('tina_risponde', 'public', 'Tina Risponde');
    $post = fcomConsiglio($autrice, $this->data, 'Mostra fotografica e concerto');
    $pagina = '/bacheca/post/'.$post->id;

    $this->actingAs($sandro, 'web')->get($pagina)->assertOk()->assertSee('action="'.route('community.comment', $post).'"', false);
    $this->from($pagina)->post($pagina.'/commenti', ['body' => 'Ci sarò anche io'])
        ->assertRedirect($pagina)->assertSessionHas('status', __('community.updated'));
    $commento = CommunityComment::query()->sole();
    $avviso = $autrice->notifications()->sole();
    expect($avviso->data['kind'])->toBe('comment')->and($avviso->data['path'])->toBe($pagina);

    $this->actingAs($tina, 'web')->from($pagina)->post($pagina.'/commenti', ['body' => 'Andiamo insieme?', 'parent_id' => $commento->id])
        ->assertRedirect($pagina)->assertSessionHasNoErrors();
    $risposta = CommunityComment::query()->where('user_id', $tina->id)->sole();
    expect($risposta->parent_id)->toBe($commento->id)
        // La risposta avvisa sia chi ha scritto il consiglio sia chi ha scritto il commento.
        ->and($autrice->notifications()->count())->toBe(2)
        ->and($sandro->notifications()->where('data->kind', 'comment')->count())->toBe(1);
    $this->get($pagina)->assertOk()->assertSee('Ci sarò anche io')->assertSee('Andiamo insieme?');

    // Il commento di un altro non si tocca.
    $this->delete('/bacheca/commenti/'.$commento->id)->assertForbidden();
    expect($commento->fresh())->not->toBeNull();

    $this->from($pagina)->delete('/bacheca/commenti/'.$risposta->id)
        ->assertRedirect($pagina)->assertSessionHas('status', __('community.comment_deleted'));
    expect(CommunityComment::query()->whereKey($risposta->id)->exists())->toBeFalse();

    // Chi ha scritto il consiglio modera la propria conversazione.
    $this->actingAs($autrice, 'web')->from($pagina)->delete('/bacheca/commenti/'.$commento->id)
        ->assertSessionHas('status', __('community.comment_deleted'));
    expect(CommunityComment::query()->count())->toBe(0);
    $this->get($pagina)->assertOk()->assertSee(__('community.no_comments'));
});

it('commenta dall app e lascia leggere ma non scrivere a chi non ha il numero verificato', function (): void {
    $autore = fcomPersona('nico_autore');
    $commentatore = fcomPersona('emma_commenta', 'public', 'Emma Commenta');
    $lettore = User::factory()->create();
    $post = fcomConsiglio($autore, $this->data, 'Ingresso libero fino alle 22');
    $commenti = '/api/v1/community/posts/'.$post->id.'/comments';

    Sanctum::actingAs($commentatore);
    $id = $this->postJson($commenti, ['body' => 'Serve prenotare?'])->assertCreated()
        ->assertJsonPath('data.display_name', 'Emma Commenta')->assertJsonPath('data.can_delete', true)->json('data.id');
    $this->postJson($commenti, ['body' => str_repeat('a', 1001)])->assertUnprocessable();

    Sanctum::actingAs($lettore);
    $this->getJson('/api/v1/community/posts/'.$post->id)->assertOk()
        ->assertJsonPath('data.post.can_comment', false)
        ->assertJsonPath('data.comments.0.body', 'Serve prenotare?')
        ->assertJsonPath('data.comments.0.can_delete', false);
    $this->postJson($commenti, ['body' => 'Provo lo stesso'])->assertForbidden();
    $this->deleteJson('/api/v1/community/comments/'.$id)->assertForbidden();

    Sanctum::actingAs($commentatore);
    $this->deleteJson('/api/v1/community/comments/'.$id)->assertOk()->assertExactJson(['data' => ['ok' => true]]);
    expect(CommunityComment::query()->count())->toBe(0)
        ->and($autore->notifications()->where('data->kind', 'comment')->count())->toBe(1);
});

it('segnala un consiglio, un commento e un profilo una volta sola, ma non ciò che non vede', function (): void {
    $autore = fcomPersona('vito_autore');
    $scrittore = fcomPersona('zeno_scrive');
    $segnalante = fcomPersona('nadia_segnala');
    $privata = fcomPersona('privata_x', 'private');
    $post = fcomConsiglio($autore, $this->data, 'Serata di poesia');
    $commento = app(Community::class)->comment($scrittore, $post, 'Testo offensivo', null);

    $this->actingAs($segnalante, 'web')->from('/bacheca/post/'.$post->id);
    $this->post('/bacheca/segnala', ['type' => 'community_post', 'id' => $post->id, 'reason' => 'spam'])
        ->assertRedirect('/bacheca/post/'.$post->id)->assertSessionHas('status', __('community.reported'));
    // Segnalare due volte la stessa cosa non apre una seconda pratica.
    $this->post('/bacheca/segnala', ['type' => 'community_post', 'id' => $post->id, 'reason' => 'spam'])->assertSessionHas('status', __('community.reported'));
    $this->post('/bacheca/segnala', ['type' => 'community_comment', 'id' => $commento->id, 'reason' => 'offensive', 'note' => 'Insulti'])->assertSessionHasNoErrors();
    $this->post('/bacheca/segnala', ['type' => 'community_profile', 'id' => $scrittore->communityProfile->id, 'reason' => 'offensive'])->assertSessionHasNoErrors();
    $this->post('/bacheca/segnala', ['type' => 'community_post', 'id' => $post->id, 'reason' => 'antipatia'])->assertSessionHasErrors('reason');

    Sanctum::actingAs($autore);
    $this->postJson('/api/v1/community/reports', ['type' => 'community_profile', 'id' => $privata->communityProfile->id, 'reason' => 'spam'])->assertNotFound();
    $this->postJson('/api/v1/community/reports', ['type' => 'community_comment', 'id' => $commento->id, 'reason' => 'spam'])->assertOk();

    expect(Report::query()->count())->toBe(4)
        ->and(Report::query()->where('reporter_user_id', $segnalante->id)->count())->toBe(3)
        ->and(Report::query()->pluck('status')->unique()->all())->toBe([ReportStatus::Pending]);
    $suCommento = Report::query()->where('reporter_user_id', $segnalante->id)->where('reportable_type', $commento->getMorphClass())->sole();
    expect($suCommento->reportable_id)->toBe($commento->id)
        ->and($suCommento->reason)->toBe(ReportReason::Offensive)
        ->and($suCommento->note)->toBe('Insulti');
});
