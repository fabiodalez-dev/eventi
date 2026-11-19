<?php

declare(strict_types=1);

use App\Enums\ProfileVisibility;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * La foto del profilo non finisce mai su una pagina d'errore.
 *
 * Prima una WebP animata o un file rovinato arrivavano a `imagecreatefromstring`,
 * il cui avviso diventava un 500 (o un 422 nudo); una foto troppo grande dava
 * «validation.dimensions», la chiave non tradotta. Ora ogni caso torna al
 * modulo con una frase in italiano sotto il campo, e la foto accettata viene
 * ricodificata dal server a 512 px sul lato lungo.
 */
beforeEach(function (): void {
    config(['community.enabled' => true]);
    Storage::fake('local');
    $this->city = testCity();
    $this->user = User::factory()->create(['first_name' => 'Chiara']);
    $this->user->forceFill(['whatsapp_verified_at' => now(), 'whatsapp_phone_hash' => hash('sha256', 'foto-profilo')])->save();
    $this->user = $this->user->fresh();
});

/** Un'intestazione WebP animata (VP8X con il bit dell'animazione): GD non la sa decodificare. */
function webpAnimataProfilo(): UploadedFile
{
    $vp8x = 'VP8X'.pack('V', 10).chr(0x02)."\0\0\0".substr(pack('V', 299), 0, 3).substr(pack('V', 299), 0, 3);
    $anim = 'ANIM'.pack('V', 6)."\0\0\0\0\0\0";
    $corpo = 'WEBP'.$vp8x.$anim;
    $percorso = tempnam(sys_get_temp_dir(), 'webp');
    file_put_contents($percorso, 'RIFF'.pack('V', strlen($corpo)).$corpo);

    return new UploadedFile($percorso, 'animata.webp', 'image/webp', null, true);
}

/**
 * Un PNG che dichiara 5000×5000 pixel: basta l'intestazione per misurarlo, e
 * non serve occupare cento megabyte di memoria per costruirlo davvero.
 */
function pngEnormeProfilo(): UploadedFile
{
    $ihdr = 'IHDR'.pack('NN', 5000, 5000).chr(8).chr(2).chr(0).chr(0).chr(0);
    $chunk = fn (string $dati): string => pack('N', strlen($dati) - 4).$dati.pack('N', crc32($dati));
    $percorso = tempnam(sys_get_temp_dir(), 'png');
    file_put_contents($percorso, "\x89PNG\r\n\x1a\n".$chunk($ihdr).$chunk('IEND'));

    return new UploadedFile($percorso, 'enorme.png', 'image/png', null, true);
}

/** I campi minimi di un profilo valido, con la foto da provare. */
function profiloConFoto(UploadedFile $foto, string $handle = 'chiara_foto'): array
{
    return ['display_name' => 'Chiara', 'handle' => $handle, 'visibility' => ProfileVisibility::Members->value, 'avatar' => $foto];
}

/** Nessun messaggio uscito come chiave non tradotta («validation.dimensions»). */
function senzaChiaviNonTradotte(TestResponse $risposta): void
{
    $messaggi = collect(session('errors')?->getBag('default')->all() ?? []);
    expect($messaggi)->not->toBeEmpty();
    $messaggi->each(fn (string $messaggio) => expect($messaggio)->not->toStartWith('validation.')->not->toStartWith('community.'));
}

it('rifiuta una WebP animata con un errore sul campo, non con un 500', function (): void {
    $risposta = $this->actingAs($this->user)->from(route('community.settings'))
        ->post(route('community.settings.update'), profiloConFoto(webpAnimataProfilo()));

    $risposta->assertRedirect(route('community.settings'))
        ->assertSessionHasErrors(['avatar' => __('community.avatar_errors.animated')]);
    senzaChiaviNonTradotte($risposta);
    // La foto si controlla prima di salvare: niente profilo a metà.
    expect($this->user->fresh()->communityProfile)->toBeNull();
});

it('dice in italiano che una foto da 5000×5000 è troppo grande', function (): void {
    $risposta = $this->actingAs($this->user)->from(route('community.settings'))
        ->post(route('community.settings.update'), profiloConFoto(pngEnormeProfilo()));

    $risposta->assertRedirect(route('community.settings'))
        ->assertSessionHasErrors(['avatar' => __('community.avatar_errors.too_big', ['max' => 4096])]);
    senzaChiaviNonTradotte($risposta);
});

it('spiega che le foto HEIC vanno convertite', function (): void {
    $heic = UploadedFile::fake()->create('IMG_0001.heic', 40, 'image/heic');

    $risposta = $this->actingAs($this->user)->from(route('community.settings'))
        ->post(route('community.settings.update'), profiloConFoto($heic));

    $risposta->assertSessionHasErrors(['avatar' => __('community.avatar_errors.heic')]);
    // `bail`: un solo messaggio per il campo, non tre.
    expect(session('errors')->get('avatar'))->toHaveCount(1);
});

it('rifiuta un JPEG rovinato con la frase di un file che non si legge', function (): void {
    $intero = UploadedFile::fake()->image('intera.jpg', 400, 300);
    $byte = (string) file_get_contents($intero->getRealPath());
    $percorso = tempnam(sys_get_temp_dir(), 'jpg');
    file_put_contents($percorso, substr($byte, 0, 200));
    $rovinato = new UploadedFile($percorso, 'rovinata.jpg', 'image/jpeg', null, true);

    $risposta = $this->actingAs($this->user)->from(route('community.settings'))
        ->post(route('community.settings.update'), profiloConFoto($rovinato));

    $risposta->assertRedirect(route('community.settings'))->assertSessionHasErrors('avatar');
    senzaChiaviNonTradotte($risposta);
});

it('salva la foto di un telefono ridotta a 512 pixel sul lato lungo', function (): void {
    $this->actingAs($this->user)->post(route('community.settings.update'), profiloConFoto(UploadedFile::fake()->image('telefono.jpg', 4032, 3024)))
        ->assertRedirect()->assertSessionHasNoErrors();

    $media = $this->user->fresh()->communityProfile->getFirstMedia('avatar');
    expect($media)->not->toBeNull()->and($media->mime_type)->toBe('image/jpeg');
    [$larghezza, $altezza] = getimagesizefromstring(Storage::disk('local')->get($media->getPathRelativeToRoot()));
    expect($larghezza)->toBe(512)->and($altezza)->toBe(384);
});

it('non ingrandisce una foto già piccola', function (): void {
    $this->actingAs($this->user)->post(route('community.settings.update'), profiloConFoto(UploadedFile::fake()->image('piccola.png', 200, 120)))
        ->assertRedirect()->assertSessionHasNoErrors();

    $media = $this->user->fresh()->communityProfile->getFirstMedia('avatar');
    [$larghezza, $altezza] = getimagesizefromstring(Storage::disk('local')->get($media->getPathRelativeToRoot()));
    expect([$larghezza, $altezza])->toBe([200, 120]);
});

it('dall\'app la WebP animata è un 422 con l\'errore sul campo', function (): void {
    Sanctum::actingAs($this->user);

    $this->post('/api/v1/community/profile', profiloConFoto(webpAnimataProfilo(), 'chiara_app'), ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonFragment([__('community.avatar_errors.animated')]);
});

it('mostra il messaggio della foto una volta sola nella pagina', function (): void {
    $this->actingAs($this->user)->from(route('community.settings'))
        ->post(route('community.settings.update'), profiloConFoto(webpAnimataProfilo()));

    $html = $this->actingAs($this->user)->get(route('community.settings'))->assertOk()->getContent();
    expect(substr_count($html, e(__('community.avatar_errors.animated'))))->toBe(1);
});
