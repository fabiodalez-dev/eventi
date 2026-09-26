<?php

declare(strict_types=1);

use App\Enums\ProfileVisibility;
use App\Models\User;
use App\Models\Venue;
use App\Services\Community\Community;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Il modulo del profilo pubblico: quanto lavoro chiede, e quando lo dice.
 *
 * Tre difetti dello stesso tipo — informazione che esiste ma arriva tardi o non
 * arriva: il nome utente già preso lo si scopriva inviando il modulo, il nome
 * pubblico si riscriveva a mano pur avendolo già dato all'iscrizione, e i locali
 * consigliati erano un elenco di caselle senza modo di cercarli.
 */
beforeEach(function (): void {
    config(['community.enabled' => true]);
    $this->city = testCity();
    $this->user = User::factory()->create(['first_name' => 'Chiara', 'last_name' => 'Bersani']);
    /* Il modulo esiste solo per chi può partecipare: senza numero verificato la
       pagina mostra l'invito a verificarlo, non i campi. */
    $this->user->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'profilo-form')])->save();
    $this->user = $this->user->fresh();
});

/** Un JPEG minimo, come lo produce il controller dopo la ricodifica. */
function immagineDiProva(): string
{
    $immagine = imagecreatetruecolor(48, 48);
    imagefill($immagine, 0, 0, imagecolorallocate($immagine, 80, 120, 200));
    ob_start();
    imagejpeg($immagine, null, 85);
    $byte = (string) ob_get_clean();
    imagedestroy($immagine);

    return $byte;
}

/** Un'altra persona che può già partecipare: creare un profilo lo richiede. */
function personaVerificata(): User
{
    $persona = User::factory()->create();
    $persona->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'altra-'.$persona->id)])->save();

    return $persona->fresh();
}

it('dice subito se un nome utente è già preso, con le regole del salvataggio', function (): void {
    $altra = personaVerificata();
    app(Community::class)->profile($altra, ['handle' => 'chi_c_era_prima',
        'display_name' => 'Chi c’era prima', 'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);

    $chiedi = fn (string $handle) => $this->actingAs($this->user)
        ->getJson(route('community.handle', ['handle' => $handle]))->assertOk();

    $chiedi('chi_c_era_prima')->assertJsonPath('data.available', false);
    $chiedi('nome_libero')->assertJsonPath('data.available', true)->assertJsonPath('data.reason', null);

    /* Le regole sono quelle di `ProfileRequest`, non una copia: troppo corto e
       caratteri non ammessi devono risultare non disponibili qui esattamente
       come lo sarebbero al salvataggio. */
    $chiedi('ab')->assertJsonPath('data.available', false);
    $chiedi('Nome Con Spazi')->assertJsonPath('data.available', false);
    $chiedi('')->assertJsonPath('data.available', false)->assertJsonPath('data.reason', null);
});

it('non considera occupato il proprio nome utente', function (): void {
    app(Community::class)->profile($this->user, ['handle' => 'il_mio_nome',
        'display_name' => 'Chiara', 'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);

    $this->actingAs($this->user->fresh())->getJson(route('community.handle', ['handle' => 'il_mio_nome']))
        ->assertOk()->assertJsonPath('data.available', true);
});

it('quello che dice disponibile, il salvataggio lo accetta', function (): void {
    /* È la garanzia che regge tutto il resto: un «disponibile» smentito dal
       salvataggio sarebbe peggio del silenzio di prima. */
    $this->actingAs($this->user)->getJson(route('community.handle', ['handle' => 'chiara_b']))
        ->assertOk()->assertJsonPath('data.available', true);

    $this->actingAs($this->user)->post(route('community.settings.update'), [
        'handle' => 'chiara_b', 'display_name' => 'Chiara', 'visibility' => ProfileVisibility::Public->value,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('community_profiles', ['user_id' => $this->user->getKey(), 'handle' => 'chiara_b']);
});

it('chiede di accedere prima di rispondere sui nomi utente', function (): void {
    $this->get(route('community.handle', ['handle' => 'qualsiasi']))->assertRedirect();
});

it('propone il nome di battesimo come nome pubblico, e non il cognome', function (): void {
    $this->actingAs($this->user)->get(route('community.settings'))->assertOk()
        ->assertSee('value="Chiara"', false)
        ->assertDontSee('Bersani');
});

it('non sovrascrive il nome pubblico di chi ha già un profilo', function (): void {
    app(Community::class)->profile($this->user, ['handle' => 'come_mi_chiamo',
        'display_name' => 'Come mi chiamo io', 'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);

    $this->actingAs($this->user->fresh())->get(route('community.settings'))->assertOk()
        ->assertSee('value="Come mi chiamo io"', false)
        ->assertDontSee('value="Chiara"', false);
});

it('offre la ricerca dei locali tenendo le caselle che funzionano senza JavaScript', function (): void {
    $locale = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Centro Sociale Pedro']);
    $this->user->follows()->create(['followable_type' => 'venue', 'followable_id' => $locale->getKey()]);

    $this->actingAs($this->user->fresh())->get(route('community.settings'))->assertOk()
        ->assertSee('data-locali-input', false)
        ->assertSee('data-locali-caselle', false)
        ->assertSee(__('community.venue_search'))
        // La casella resta nel documento: è lei a essere inviata.
        ->assertSee('name="venue_ids[]" value="'.$locale->getKey().'"', false);
});

it('non disegna la ricerca a chi non segue nessun locale', function (): void {
    $this->actingAs($this->user)->get(route('community.settings'))->assertOk()
        ->assertSee(__('community.no_venues'))
        ->assertDontSee('data-locali-input', false);
});

/*
 * La foto caricata dal modulo del sito, non solo dall'app.
 *
 * Il percorso esisteva e funzionava — l'ho verificato in produzione — ma il solo
 * test con un file passava dalla rotta dell'app (`/api/v1/community/profile`).
 * Quella del sito è l'unica pagina multipart del progetto, e non aveva copertura:
 * quando l'invio dal browser di prova ha smesso di funzionare, non c'era modo di
 * distinguere un guasto dell'applicazione da un limite del banco di prova.
 */
it('carica la foto dal modulo del sito e la ricodifica', function (): void {
    Storage::fake('local');

    $this->actingAs($this->user)->post(route('community.settings.update'), [
        'display_name' => 'Chiara', 'handle' => 'chiara_con_foto', 'visibility' => ProfileVisibility::Members->value,
        'avatar' => UploadedFile::fake()->image('ritratto.png', 240, 240),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $media = $this->user->fresh()->communityProfile->getFirstMedia('avatar');

    /* La ricodifica non è un vezzo: butta via EXIF, metadati e qualunque coda
       eseguibile appesa a un file che si dichiara immagine. */
    expect($media)->not->toBeNull()
        ->and($media->file_name)->toBe('avatar.jpg')
        ->and($media->mime_type)->toBe('image/jpeg');
});

it('rifiuta dal modulo del sito ciò che non è un\'immagine', function (): void {
    Storage::fake('local');

    $this->actingAs($this->user)->post(route('community.settings.update'), [
        'display_name' => 'Chiara', 'handle' => 'chiara_senza_foto', 'visibility' => ProfileVisibility::Members->value,
        'avatar' => UploadedFile::fake()->create('script.svg', 2, 'image/svg+xml'),
    ])->assertSessionHasErrors('avatar');

    expect($this->user->fresh()->communityProfile)->toBeNull();
});

it('toglie la foto quando lo si chiede, senza toccare il resto del profilo', function (): void {
    Storage::fake('local');

    $this->actingAs($this->user)->post(route('community.settings.update'), [
        'display_name' => 'Chiara', 'handle' => 'chiara_foto_via', 'visibility' => ProfileVisibility::Members->value,
        'avatar' => UploadedFile::fake()->image('ritratto.png'),
    ])->assertRedirect();

    $this->actingAs($this->user->fresh())->post(route('community.settings.update'), [
        'display_name' => 'Chiara', 'handle' => 'chiara_foto_via', 'visibility' => ProfileVisibility::Members->value,
        'remove_avatar' => '1',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $profilo = $this->user->fresh()->communityProfile;
    expect($profilo->getFirstMedia('avatar'))->toBeNull()
        ->and($profilo->handle)->toBe('chiara_foto_via');
});

/*
 * La foto si vede dove la persona è nominata.
 *
 * Non era così: gli elenchi delle relazioni e i commenti della bacheca
 * mostravano il solo nome. E la foto deve seguire la stessa regola del nome —
 * dove il profilo non è visibile a chi guarda e il nome diventa «Iscritto», il
 * volto non esce: identifica più di una parola.
 */
it('mostra la foto negli elenchi di chi segui e di chi ti segue', function (): void {
    Storage::fake('local');
    $altra = personaVerificata();
    app(Community::class)->profile($altra, ['handle' => 'con_la_foto', 'display_name' => 'Con la foto',
        'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);
    $altra->communityProfile->addMediaFromString(immagineDiProva())->usingFileName('avatar.jpg')->toMediaCollection('avatar');

    app(Community::class)->profile($this->user, ['handle' => 'chi_guarda', 'display_name' => 'Chi guarda',
        'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);
    app(Community::class)->follow($this->user->fresh(), $altra->fresh(), true);

    $atteso = $altra->communityProfile->fresh()->avatarUrl();
    expect($atteso)->not->toBeEmpty();

    $this->actingAs($this->user->fresh())->get(route('community.followers', ['tab' => 'following']))
        ->assertOk()->assertSee($atteso, false);
});

it('non mostra la foto di un profilo che chi guarda non può vedere', function (): void {
    Storage::fake('local');
    $riservata = personaVerificata();
    app(Community::class)->profile($riservata, ['handle' => 'riservata', 'display_name' => 'Riservata',
        'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Private->value]);
    $riservata->communityProfile->addMediaFromString(immagineDiProva())->usingFileName('avatar.jpg')->toMediaCollection('avatar');
    $nascosto = $riservata->communityProfile->fresh()->avatarUrl();

    app(Community::class)->profile($this->user, ['handle' => 'chi_guarda_2', 'display_name' => 'Chi guarda',
        'city_id' => $this->city->getKey(), 'visibility' => ProfileVisibility::Public->value]);
    app(Community::class)->follow($riservata->fresh(), $this->user->fresh(), true);

    $this->actingAs($this->user->fresh())->get(route('community.followers', ['tab' => 'followers']))
        ->assertOk()->assertDontSee($nascosto, false)->assertSee(__('community.member'));
});
