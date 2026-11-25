<?php

declare(strict_types=1);

use App\Enums\EventStatus;
use App\Models\Organizer;
use App\Models\User;
use App\Services\Management\ManagementOptions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Support\ImageFixtures;
use Tests\Support\VenueIsolationScenario;

/*
 * Le immagini caricate dall'app di gestione: locandina (locale e
 * organizzazione), logo, copertina e galleria del locale. Stesse regole dei
 * campi immagine dei pannelli, stesse raccolte media, stessa regola del
 * ritorno in revisione per la locandina nuova di un evento pubblicato.
 */
beforeEach(function (): void {
    Notification::fake();
    $this->disk = config()->string('media-library.disk_name');
    Storage::fake($this->disk);
    $this->s = VenueIsolationScenario::make();
    $this->venueUrl = '/api/v1/management/venues/'.$this->s->venueA->id;
});

function managementImage(string $name = 'foto.png', int $width = 400, int $height = 500): UploadedFile
{
    return ImageFixtures::upload($name, ImageFixtures::png($width, $height));
}

it('carica la locandina di una bozza senza cambiarne lo stato', function (): void {
    Sanctum::actingAs($this->s->editorA);

    $this->postJson($this->venueUrl.'/events/'.$this->s->draftEventA->id.'/poster', ['image' => managementImage()])->assertOk()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.sent_to_review', false)
        ->assertJsonStructure(['data' => ['poster' => ['thumb', 'card', 'full', 'blurhash', 'width', 'height']]]);

    expect($this->s->draftEventA->fresh()->getMedia('poster'))->toHaveCount(1);
});

it('rimanda in revisione l’evento pubblicato di un locale senza pubblicazione autonoma, come il pannello', function (): void {
    $this->s->venueA->update(['auto_publish' => false]);
    Sanctum::actingAs($this->s->ownerA);

    $this->postJson($this->venueUrl.'/events/'.$this->s->publishedEventA->id.'/poster', ['image' => managementImage()])->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.sent_to_review', true);
    expect($this->s->publishedEventA->fresh()->status)->toBe(EventStatus::Pending);

    // Togliere la locandina non porta niente di nuovo in pagina.
    $this->s->publishedEventA->refresh()->forceFill(['status' => EventStatus::Published])->saveQuietly();
    $this->deleteJson($this->venueUrl.'/events/'.$this->s->publishedEventA->id.'/poster')->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.sent_to_review', false);
    expect($this->s->publishedEventA->fresh()->getMedia('poster'))->toHaveCount(0);
});

it('lascia pubblicato l’evento di un locale che pubblica da sé', function (): void {
    $this->s->venueA->update(['auto_publish' => true]);
    Sanctum::actingAs($this->s->editorA);

    $this->postJson($this->venueUrl.'/events/'.$this->s->publishedEventA->id.'/poster', ['image' => managementImage()])->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.sent_to_review', false);
});

it('rimanda sempre alla redazione l’evento pubblicato di un’organizzazione, anche co-organizzato', function (): void {
    $owner = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->s->city->id, 'owner_id' => $owner->id, 'is_active' => true]);
    // Firmato da un altro organizzatore, con questo come co-organizzatore: lo gestisce come nel pannello.
    $event = $this->s->publishedEventB;
    $event->organizers()->attach($organizer);

    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/management/organizers/'.$organizer->id.'/events/'.$event->id.'/poster', ['image' => managementImage()])->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.sent_to_review', true);
    // Un evento che non firma né co-organizza resta fuori.
    $this->postJson('/api/v1/management/organizers/'.$organizer->id.'/events/'.$this->s->publishedEventA->id.'/poster', ['image' => managementImage()])->assertNotFound();
});

it('rifiuta un file che non è un’immagine, anche se si chiama .jpg', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $fake = ImageFixtures::upload('locandina.jpg', '<?php echo "ciao"; ?>'.str_repeat(' ', 4096));

    $this->postJson($this->venueUrl.'/events/'.$this->s->draftEventA->id.'/poster', ['image' => $fake])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['image']]]);
    $this->postJson($this->venueUrl.'/events/'.$this->s->draftEventA->id.'/poster', [])->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['image']]]);
    // Troppo piccola per essere una locandina.
    $this->postJson($this->venueUrl.'/events/'.$this->s->draftEventA->id.'/poster', ['image' => managementImage('mini.png', 100, 100)])->assertUnprocessable();

    expect($this->s->draftEventA->fresh()->getMedia('poster'))->toHaveCount(0);
});

it('rifiuta un file oltre il peso massimo', function (): void {
    Sanctum::actingAs($this->s->ownerA);
    $heavy = UploadedFile::fake()->image('enorme.jpg', 400, 500)->size((int) (config()->integer('media.max_upload_bytes') / 1024) + 1);

    $this->postJson($this->venueUrl.'/events/'.$this->s->draftEventA->id.'/poster', ['image' => $heavy])->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['image']]]);
});

it('sostituisce logo e copertina senza lasciare file orfani', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->postJson($this->venueUrl.'/media/logo', ['image' => managementImage('primo.png')])->assertOk()->assertJsonPath('data.collection', 'logo');
    $first = $this->s->venueA->fresh()->getFirstMedia('logo');
    expect($first)->toBeInstanceOf(Media::class);
    Storage::disk($this->disk)->assertExists($first->getPathRelativeToRoot());

    $this->postJson($this->venueUrl.'/media/logo', ['image' => managementImage('secondo.png')])->assertOk()
        ->assertJsonStructure(['data' => ['image' => ['thumb', 'card', 'full']]]);
    $logos = $this->s->venueA->fresh()->getMedia('logo');

    expect($logos)->toHaveCount(1)
        ->and($logos->first()->getKey())->not->toBe($first->getKey())
        ->and(Media::query()->whereKey($first->getKey())->exists())->toBeFalse();
    Storage::disk($this->disk)->assertMissing($first->getPathRelativeToRoot());

    $this->postJson($this->venueUrl.'/media/cover', ['image' => managementImage('copertina.png', 800, 400)])->assertOk();
    $this->getJson($this->venueUrl.'/media')->assertOk()->assertJsonPath('data.gallery', [])
        ->assertJsonStructure(['data' => ['logo' => ['full'], 'cover' => ['full']]]);

    $logo = $logos->first();
    $this->deleteJson($this->venueUrl.'/media/logo')->assertOk()->assertJsonPath('data.image', null);
    Storage::disk($this->disk)->assertMissing($logo->getPathRelativeToRoot());
    $this->postJson($this->venueUrl.'/media/avatar', ['image' => managementImage()])->assertNotFound();
});

it('lascia le immagini del locale al solo referente', function (): void {
    Sanctum::actingAs($this->s->editorA);

    $this->getJson($this->venueUrl.'/media')->assertForbidden();
    $this->postJson($this->venueUrl.'/media/logo', ['image' => managementImage()])->assertForbidden();
    $this->postJson($this->venueUrl.'/media/gallery', ['image' => managementImage()])->assertForbidden();

    // Le immagini del locale non esistono per un'organizzazione.
    $owner = User::factory()->create();
    $organizer = Organizer::create(['name' => 'Collettivo Portici', 'city_id' => $this->s->city->id, 'owner_id' => $owner->id, 'is_active' => true]);
    Sanctum::actingAs($owner);
    $this->postJson('/api/v1/management/organizers/'.$organizer->id.'/media/logo', ['image' => managementImage()])->assertNotFound();
});

it('aggiunge, riordina e toglie le foto della galleria entro il tetto', function (): void {
    Sanctum::actingAs($this->s->ownerA);

    $this->postJson($this->venueUrl.'/media/gallery', ['image' => managementImage('uno.png')])->assertCreated();
    $gallery = $this->postJson($this->venueUrl.'/media/gallery', ['image' => managementImage('due.png')])->assertCreated()->json('data.gallery');
    $ids = array_column($gallery, 'id');
    expect($ids)->toHaveCount(2);

    $reversed = array_reverse($ids);
    $this->putJson($this->venueUrl.'/media/gallery/order', ['ids' => $reversed])->assertOk()->assertJsonPath('data.gallery.0.id', $reversed[0]);
    // Tutte e sole le foto del locale: una foto di un altro locale non entra nell'ordine.
    $other = $this->s->venueB->addMedia(managementImage('altro.png'))->toMediaCollection('gallery');
    $this->putJson($this->venueUrl.'/media/gallery/order', ['ids' => [$reversed[0], $other->id]])->assertUnprocessable();
    $this->deleteJson($this->venueUrl.'/media/gallery/'.$other->id)->assertNotFound();

    $this->deleteJson($this->venueUrl.'/media/gallery/'.$reversed[0])->assertOk()->assertJsonCount(1, 'data.gallery');

    // Il tetto: righe scritte a mano fino a 30, la trentunesima foto è rifiutata.
    $template = $this->s->venueA->fresh()->getFirstMedia('gallery');
    for ($i = $this->s->venueA->fresh()->getMedia('gallery')->count(); $i < ManagementOptions::GALLERY_MAX; $i++) {
        $copy = $template->replicate();
        $copy->uuid = (string) Str::uuid();
        $copy->save();
    }
    $this->postJson($this->venueUrl.'/media/gallery', ['image' => managementImage('troppe.png')])->assertUnprocessable()
        ->assertJsonStructure(['error' => ['fields' => ['image']]]);
    expect($this->s->venueA->fresh()->getMedia('gallery'))->toHaveCount(ManagementOptions::GALLERY_MAX);
});
