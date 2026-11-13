<?php

declare(strict_types=1);

require_once __DIR__.'/SupportoFlussiCommunity.php';

use App\Actions\Account\SaveOccurrences;
use App\Models\CommunityProfile;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/*
 * Il profilo social, dalla prima visita del modulo a quando gli altri lo
 * trovano: scelta del nome utente, cambio con attesa, visibilità, foto, e le
 * porte chiuse a chi non ha ancora confermato email o numero.
 */

beforeEach(function (): void {
    fcomPrepara();
    $this->city = testCity(['is_active' => true]);
    $this->category = testCategory();
    freezeLocal($this->city, '2026-09-10 18:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

it('crea il profilo dal modulo dopo aver controllato il nome utente e compare fra le persone', function (): void {
    $chiara = fcomVerificato(['first_name' => 'Chiara', 'last_name' => 'Bersani']);
    fcomPersona('chiara_b', 'public', 'Chiara B');

    // Il modulo propone il nome di battesimo, mai il cognome dell'iscrizione.
    $this->actingAs($chiara, 'web')->get('/profilo-social')->assertOk()
        ->assertSee('name="display_name" value="Chiara"', false)
        ->assertDontSee('value="Bersani"', false);

    // Il controllo dal vivo dice subito che il primo nome è preso e il secondo no.
    $this->getJson('/profilo-social/nome-utente?handle=chiara_b')->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.reason', fn (?string $motivo): bool => filled($motivo));
    $this->getJson('/profilo-social/nome-utente?handle=Chiara_Padova')->assertOk()
        ->assertJsonPath('data.handle', 'chiara_padova')
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.changeable_from', null);

    $this->post('/profilo-social', [
        'handle' => 'chiara_padova', 'display_name' => 'Chiara', 'bio' => 'Concerti e teatro in centro',
        'city_id' => $this->city->id, 'visibility' => 'public',
    ])->assertRedirect(route('community.settings'))->assertSessionHas('status', __('community.updated'));

    $profilo = CommunityProfile::query()->where('user_id', $chiara->id)->sole();
    expect($profilo->handle)->toBe('chiara_padova')
        ->and($profilo->visibility->value)->toBe('public')
        // Il primo nome scelto non fa partire l'attesa fra due cambi.
        ->and($profilo->handle_changed_at)->toBeNull();
    $this->assertDatabaseHas('community_handle_aliases', ['handle' => 'chiara_padova', 'community_profile_id' => $profilo->id]);

    // Chi non ha un account la trova nell'elenco, nella pagina e nella ricerca dell'app.
    fcomEsci();
    $this->get('/persone')->assertOk()->assertSee('Chiara')->assertSee('@chiara_padova');
    $this->get('/persone/chiara_padova')->assertOk()->assertSee('Concerti e teatro in centro');
    $this->getJson('/api/v1/community/people?q=chiara')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/community/people/chiara_padova')->assertOk()
        ->assertJsonPath('data.profile.display_name', 'Chiara')
        ->assertJsonPath('data.profile.city', $this->city->name)
        ->assertJsonPath('data.profile.is_own', false);
});

it('cambia nome una volta, rinvia dal nome lasciato e fa aspettare trenta giorni il cambio successivo', function (): void {
    $luca = fcomVerificato();
    $profiloDi = fn (): CommunityProfile => CommunityProfile::query()->where('user_id', $luca->id)->sole();
    // Ogni richiesta vera ricarica l'utente: qui lo si rifà a mano, prima di ogni passo.
    $comeLuca = fn () => $this->actingAs($luca->fresh(), 'web');
    $dati = fn (string $handle): array => ['handle' => $handle, 'display_name' => 'Luca', 'visibility' => 'public'];

    $comeLuca()->post('/profilo-social', $dati('luca_primo'))->assertSessionHasNoErrors();
    $comeLuca()->post('/profilo-social', $dati('luca_secondo'))->assertSessionHasNoErrors();
    expect($profiloDi()->handle)->toBe('luca_secondo')
        ->and($profiloDi()->handle_changed_at)->not->toBeNull();

    // Il vecchio collegamento porta ancora alla persona, con il nome nuovo.
    $comeLuca()->get('/persone/luca_primo')->assertRedirect(route('community.profile', 'luca_secondo'));

    // Il controllo dal vivo avvisa prima del salvataggio, e dice da quando.
    $comeLuca()->getJson('/profilo-social/nome-utente?handle=luca_terzo')->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.changeable_from', fn (?string $da): bool => $da !== null);
    $comeLuca()->post('/profilo-social', $dati('luca_terzo'))->assertSessionHasErrors('handle');
    expect($profiloDi()->handle)->toBe('luca_secondo');

    // Il nome appena lasciato resta prenotato: un'altra persona non lo prende.
    $this->actingAs(fcomVerificato(), 'web')->post('/profilo-social', $dati('luca_primo'))->assertSessionHasErrors('handle');

    // Passati trenta giorni il cambio si riapre, e il nome più vecchio torna libero.
    $this->travel(30)->days();
    $comeLuca()->post('/profilo-social', $dati('luca_terzo'))->assertSessionHasNoErrors();
    expect($profiloDi()->handle)->toBe('luca_terzo')
        ->and(DB::table('community_handle_aliases')->where('community_profile_id', $profiloDi()->id)->orderBy('handle')->pluck('handle')->all())
        ->toBe(['luca_secondo', 'luca_terzo']);
});

it('mostra ogni profilo solo al pubblico che ha scelto, al titolare sempre', function (): void {
    fcomPersona('anna_pubblica', 'public', 'Anna Pubblica');
    fcomPersona('bruno_iscritti', 'members', 'Bruno Iscritti');
    $privata = fcomPersona('carla_privata', 'private', 'Carla Privata');

    // Chi passa senza account vede solo i profili pubblici.
    $this->get('/persone')->assertOk()->assertSee('Anna Pubblica')->assertDontSee('Bruno Iscritti')->assertDontSee('Carla Privata');
    $this->get('/persone/bruno_iscritti')->assertNotFound();
    $this->get('/persone/carla_privata')->assertNotFound();
    $this->getJson('/api/v1/community/people')->assertOk()->assertJsonCount(1, 'data');

    // Un iscritto con email confermata, anche senza numero, vede anche «solo iscritti».
    $lettore = User::factory()->create();
    $this->actingAs($lettore, 'web')->get('/persone')->assertOk()
        ->assertSee('Anna Pubblica')->assertSee('Bruno Iscritti')->assertDontSee('Carla Privata');
    $this->get('/persone/bruno_iscritti')->assertOk()->assertSee('Bruno Iscritti');
    $this->get('/persone/carla_privata')->assertNotFound();

    Sanctum::actingAs($lettore);
    $this->getJson('/api/v1/community/people?q=iscritti')->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.handle', 'bruno_iscritti');
    $this->getJson('/api/v1/community/people/bruno_iscritti')->assertOk()
        ->assertJsonPath('data.profile.visibility', 'members')
        ->assertJsonPath('data.profile.handle_changeable_from', null);

    // Il profilo «solo io» resta visibile a chi lo possiede.
    $this->actingAs($privata, 'web')->get('/persone/carla_privata')->assertOk()->assertSee('Carla Privata');
});

it('carica la foto dall app, la serve solo a chi può vedere il profilo e la toglie su richiesta', function (): void {
    Storage::fake('local');
    $sara = fcomVerificato(['first_name' => 'Sara']);

    Sanctum::actingAs($sara);
    $this->getJson('/api/v1/community/profile')->assertOk()
        ->assertJsonPath('data.profile', null)
        ->assertJsonPath('data.suggested.display_name', 'Sara')
        ->assertJsonPath('data.access.required_step', 'profile');
    $this->getJson('/api/v1/community/profile/handle?handle=sara_foto')->assertOk()->assertJsonPath('data.available', true);

    $dati = ['handle' => 'sara_foto', 'display_name' => 'Sara', 'visibility' => 'members'];
    $this->postJson('/api/v1/community/profile', [...$dati, 'avatar' => UploadedFile::fake()->image('ritratto.png', 200, 200)])->assertOk()
        ->assertJsonPath('data.handle', 'sara_foto')
        ->assertJsonPath('data.avatar_url', fn (?string $url): bool => $url !== null && str_contains($url, '/api/v1/community/avatar/'));

    $profilo = CommunityProfile::query()->where('user_id', $sara->id)->sole();
    // Ricodificata in JPEG sul disco privato: niente EXIF, niente formati eseguibili.
    expect($profilo->getFirstMedia('avatar')->mime_type)->toBe('image/jpeg')
        ->and($profilo->getFirstMedia('avatar')->disk)->toBe('local');

    $indirizzo = '/community-avatar/'.$profilo->id;
    $this->actingAs($sara->fresh(), 'web')->get($indirizzo)->assertOk();

    // Profilo «solo iscritti»: l'ospite non vede la foto, l'iscritto sì, sul sito e nell'app.
    fcomEsci();
    $this->get($indirizzo)->assertNotFound();
    $lettore = User::factory()->create();
    $this->actingAs($lettore, 'web')->get($indirizzo)->assertOk();
    Sanctum::actingAs($lettore);
    $this->get('/api/v1/community/avatar/'.$profilo->id)->assertOk();

    Sanctum::actingAs($sara->fresh());
    $this->postJson('/api/v1/community/profile', [...$dati, 'remove_avatar' => true])->assertOk()->assertJsonPath('data.avatar_url', null);
    $this->actingAs($lettore, 'web')->get($indirizzo)->assertNotFound();
});

it('tiene fuori dalle rotte verificate chi non ha confermato l email e dal profilo chi non ha il numero', function (): void {
    $persona = fcomPersona('dario_pubblico');
    $data = fcomData($this->city, $this->category, '2026-09-15 21:00');

    $nuovo = User::factory()->unverified()->create();
    $this->actingAs($nuovo, 'web');
    foreach (['/bacheca', '/profilo-social', '/persone-che-mi-seguono', '/avvisi', '/avvisi/ultimi', '/verifica-whatsapp'] as $pagina) {
        $this->get($pagina)->assertRedirect(route('verification.notice'));
    }
    $this->post('/persone/'.$persona->id.'/segui')->assertRedirect(route('verification.notice'));
    $this->post('/sondaggi', ['dates' => [$data->id], 'closes_at' => '2026-09-12'])->assertRedirect(route('verification.notice'));
    // Le pagine pubbliche restano aperte.
    $this->get('/persone')->assertOk()->assertSee('Dario pubblico');

    Sanctum::actingAs($nuovo);
    $this->getJson('/api/v1/community/feed')->assertForbidden();
    $this->postJson('/api/v1/community/people/'.$persona->id.'/follow')->assertForbidden();
    expect(DB::table('followables')->count())->toBe(0);

    fcomEsci();
    $this->get('/bacheca')->assertRedirect(route('login'));

    // Email confermata ma niente numero: si segue, ma non si crea il profilo né si pubblica.
    $senzaNumero = User::factory()->create();
    $this->actingAs($senzaNumero, 'web')->get('/profilo-social')->assertOk()
        ->assertDontSee('action="'.route('community.settings.update').'"', false);
    $this->post('/profilo-social', ['handle' => 'senza_numero', 'display_name' => 'Senza', 'visibility' => 'public'])->assertForbidden();
    $this->post('/persone/'.$persona->id.'/segui')->assertRedirect();
    app(SaveOccurrences::class)->one($senzaNumero, $data);
    $this->put('/salvataggi/'.$data->id.'/visibilita', ['visibility' => 'public'])->assertForbidden();

    expect(DB::table('followables')->where('user_id', $senzaNumero->id)->count())->toBe(1)
        ->and(CommunityProfile::query()->count())->toBe(1);
    $this->assertDatabaseCount('community_posts', 0);
});
