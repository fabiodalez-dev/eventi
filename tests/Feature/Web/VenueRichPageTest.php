<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Venue;
use App\Services\Seo\BeforeGoing;
use App\Services\Seo\EditorialContent;
use Carbon\Carbon;
use Tests\Support\ImageFixtures;

/**
 * Blocco 7: la scheda del locale mostra ciò che il locale compila già.
 *
 * Galleria e caratteristiche erano dati salvati e mai stampati — trenta foto
 * caricabili che nessuna pagina apriva, e il blocco «Prima di andare» che
 * girava solo per gli eventi.
 */
beforeEach(function (): void {
    $this->city = testCity();
    $this->category = testCategory();
});

afterEach(fn () => Carbon::setTestNow());

it('pubblica la galleria del locale e non lascia intestazioni orfane', function (): void {
    $conFoto = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Circolo Con Foto']);
    $conFoto->addMedia(ImageFixtures::upload('sala.png', ImageFixtures::png(800, 600)))->toMediaCollection('gallery');
    $conFoto->addMedia(ImageFixtures::upload('bancone.png', ImageFixtures::png(800, 600)))->toMediaCollection('gallery');

    $this->get('/locali/'.$conFoto->slug)
        ->assertOk()
        ->assertSee(__('venues.detail.gallery'))
        ->assertSee(__('venues.detail.gallery_alt', ['venue' => $conFoto->name, 'number' => 1]))
        ->assertSee(__('venues.detail.gallery_alt', ['venue' => $conFoto->name, 'number' => 2]));

    $senzaFoto = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Circolo Senza Foto']);
    $this->get('/locali/'.$senzaFoto->slug)->assertOk()->assertDontSee(__('venues.detail.gallery'));
});

it('stampa le caratteristiche del locale una volta sola', function (): void {
    $venue = Venue::factory()->approved()->create([
        'city_id' => $this->city->getKey(),
        'name' => 'Circolo Che Compila',
        'content_details' => [
            'age_groups' => ['3-5'],
            'accessibility' => 'yes',
            'parking_type' => 'free',
            'weather_policy' => 'Con la pioggia si suona dentro.',
        ],
    ]);

    $html = $this->get('/locali/'.$venue->slug)->assertOk()
        ->assertSee(__('seo.before_going'))
        ->assertSee(__('family.title'))
        ->assertSee(__('seo.parking_free'))
        ->getContent();

    /* Le stesse note comparivano anche nell'elenco piatto più in basso: da
       quando esiste il riassunto, stamparle entrambe le direbbe due volte. */
    expect(substr_count($html, 'Con la pioggia si suona dentro.'))->toBe(1);
    expect($html)->not->toContain('<h3 class="font-bold">'.__('seo.fields.weather_policy'));

    $spoglio = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Circolo Spoglio', 'content_details' => []]);
    $this->get('/locali/'.$spoglio->slug)->assertOk()->assertDontSee(__('seo.before_going'));
});

it('non cambia una virgola di quello che vedeva un evento', function (): void {
    $venue = Venue::factory()->approved()->create(['city_id' => $this->city->getKey(), 'name' => 'Teatro Invariato']);
    $occurrence = occurrenceAtLocal($this->city, $this->category, '2026-09-20 21:00:00', venue: $venue, event: [
        'title' => 'Concerto invariato',
        'content_details' => ['age_groups' => ['6-10'], 'weather_policy' => 'Se piove si rimanda.'],
    ]);
    $event = $occurrence->event->fresh();

    $details = app(EditorialContent::class)->details($event);
    expect($details['practical_items'])->toBe(app(BeforeGoing::class)->items($event, $details));

    $html = $this->get('/eventi/'.$event->slug)->assertOk()->assertSee(__('seo.before_going'))->getContent();
    expect(substr_count($html, 'Se piove si rimanda.'))->toBe(1);
    expect($html)->not->toContain('<h3 class="font-bold">'.__('seo.fields.weather_policy'));
});

it('lascia alle pagine di categoria il loro elenco per campi', function (): void {
    $category = Category::factory()->create([
        'name' => 'Teatro ragazzi',
        'content_details' => ['weather_policy' => 'Gli spettacoli si tengono al chiuso.'],
    ]);
    occurrenceAtLocal($this->city, $category, '2026-09-21 21:00:00');

    $this->get('/eventi/categoria/'.$category->slug)
        ->assertOk()
        ->assertSee(__('seo.fields.weather_policy'))
        ->assertSee('Gli spettacoli si tengono al chiuso.');
});
