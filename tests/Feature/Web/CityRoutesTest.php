<?php

declare(strict_types=1);

use App\Models\City;
use Carbon\Carbon;

afterEach(function (): void {
    Carbon::setTestNow();
});

/*
 * §11.1: «predisporre `/{city}/eventi` senza renderlo obbligatorio per la città
 * di default». Le stesse rotte esistono due volte, e la città predefinita
 * continua a stare all'indirizzo nudo.
 */
it('serve le stesse liste con e senza il prefisso della città', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    occurrenceAtLocal($city, $category, '2026-09-06 21:00:00', event: ['title' => 'Serata padovana']);

    $this->get('/eventi')->assertOk()->assertSee('Serata padovana');
    $this->get('/'.$city->slug.'/eventi')->assertOk()->assertSee('Serata padovana');
    $this->get('/'.$city->slug)->assertOk();
});

it('mostra gli eventi della città chiesta, non quelli della predefinita', function (): void {
    $padova = testCity();
    $category = testCategory();

    $vicenza = City::factory()->create([
        'name' => 'Vicenza',
        'slug' => 'vicenza',
        'is_active' => true,
        'timezone' => 'Europe/Rome',
    ]);

    freezeLocal($padova, '2026-09-05 12:00:00');

    occurrenceAtLocal($padova, $category, '2026-09-06 21:00:00', event: ['title' => 'Serata padovana']);
    occurrenceAtLocal($vicenza, $category, '2026-09-06 21:00:00', event: ['title' => 'Serata vicentina']);

    $this->get('/vicenza/eventi')
        ->assertOk()
        ->assertSee('Serata vicentina')
        ->assertDontSee('Serata padovana');
});

it('risponde 404 a una città che non esiste o non è ancora accesa', function (): void {
    testCity();

    City::factory()->create(['name' => 'Verona', 'slug' => 'verona', 'is_active' => false]);

    $this->get('/verona/eventi')->assertNotFound();
    $this->get('/atlantide/eventi')->assertNotFound();
});
