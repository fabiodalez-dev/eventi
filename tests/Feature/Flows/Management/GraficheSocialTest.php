<?php

declare(strict_types=1);

use App\Filament\Venue\Pages\Social as VenueSocial;
use App\Models\SocialBatch;
use App\Services\Social\SocialStudio;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * Flusso: il gestore di un locale prepara le grafiche social delle sue
 * serate. Guarda l'anteprima della locandina, genera il pacchetto dal
 * pannello, scarica lo zip e condivide l'immagine con il collegamento
 * firmato. Il locale accanto non vede né l'anteprima né il pacchetto, e
 * un collegamento senza firma non apre niente.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

it('genera le grafiche di una serata dal pannello del locale, le fa scaricare a chi le ha fatte e a nessun altro', function (): void {
    Storage::fake('local');
    $s = VenueIsolationScenario::make();
    freezeLocal($s->city, '2026-09-10 18:00');
    $date = $s->occurrenceA;
    $giorno = $date->business_date->format('Y-m-d');

    // 1. Anteprima della locandina: al proprio gestore sì, all'altro locale no.
    $this->actingAs($s->ownerA)->get(SocialStudio::previewUrl($date))->assertOk()
        ->assertHeader('content-type', 'image/jpeg');
    $this->actingAs($s->ownerB)->get(route('social.preview', $date))->assertForbidden();

    // 2. Il gestore apre lo studio del suo locale e genera il pacchetto.
    $this->actingAs($s->ownerA)->get('/gestione/'.$s->venueA->slug.'/social')->assertOk();
    Filament::setCurrentPanel('venue');
    Filament::setTenant($s->venueA);
    $studio = Livewire::test(VenueSocial::class)
        ->set('date', $giorno)
        ->assertSet('selected', [$date->id])
        ->set('format', 'portrait')
        ->call('generate')
        ->assertHasNoErrors()
        ->assertNotified(__('social.ready'));

    $batch = SocialBatch::query()->findOrFail($studio->get('batchId'));
    expect($batch->items)->toHaveCount(1)
        ->and($batch->format)->toBe('portrait')
        ->and(Storage::disk('local')->exists($batch->items[0]['path']))->toBeTrue();

    // Una serata di un altro locale non entra nel pacchetto, nemmeno forzandola.
    Livewire::test(VenueSocial::class)
        ->set('date', $s->occurrenceB->business_date->format('Y-m-d'))
        ->assertSet('selected', [])
        ->set('selected', [$s->occurrenceB->id])
        ->call('generate')
        ->assertForbidden();
    expect(SocialBatch::query()->count())->toBe(1);

    // 3. Lo zip si scarica dal proprio account, non da quello del locale accanto.
    $this->get(route('social.zip', $batch))->assertDownload();
    $this->actingAs($s->ownerB)->get(route('social.zip', $batch))->assertForbidden();

    // 4. L'immagine si apre col collegamento firmato, anche senza account;
    //    senza firma, o con un indice manomesso, no.
    auth()->logout();
    $this->get(SocialStudio::imageUrl($batch, 0))->assertOk()->assertHeader('content-type', 'image/jpeg');
    $this->get('/social/grafiche/'.$batch->id.'/0.jpg')->assertForbidden();
    $this->get(str_replace('/0.jpg', '/1.jpg', SocialStudio::imageUrl($batch, 0)))->assertForbidden();
    $this->get(SocialStudio::imageUrl($batch, 5))->assertNotFound();

    // Lo zip e l'anteprima vogliono comunque un account.
    $this->get(route('social.zip', $batch))->assertRedirect();
    $this->get(route('social.preview', $date))->assertRedirect();
});
