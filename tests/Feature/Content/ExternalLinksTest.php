<?php

declare(strict_types=1);

use App\DTOs\ExternalLink;
use App\DTOs\ExternalLinkList;
use App\Enums\PriceType;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\Events\Pages\EditEvent as AdminEditEvent;
use App\Filament\Venue\Resources\Events\Pages\CreateEvent as VenueCreateEvent;
use App\Filament\Venue\Resources\Events\Pages\EditEvent as VenueEditEvent;
use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Rules\ExternalLinks;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

/**
 * I link esterni di un evento: la colonna `events.external_links` di §7.6, da
 * quando qualcuno la compila a quando qualcuno la legge.
 *
 * Il filo che tiene insieme questo file è **una definizione sola** di link
 * accettabile: la regola che parla a chi compila, il cast che protegge il
 * modello, la scheda pubblica e l'API leggono tutti `App\DTOs\ExternalLink`.
 * Se qui dentro una prova fallisce mentre le altre passano, vuol dire che
 * qualcuno ha ricominciato a decidere per conto proprio.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<int, array<string, mixed>>|null  $links
 * @return array<string, array<int, string>>
 */
function externalLinkErrors(?array $links): array
{
    $validator = Validator::make(
        ['external_links' => $links],
        ['external_links' => [new ExternalLinks]],
    );

    /** @var array<string, array<int, string>> $errors */
    $errors = $validator->errors()->toArray();

    return $errors;
}

// ---------------------------------------------------------------- validazione

it('accetta un elenco di coppie etichetta e indirizzo', function (): void {
    expect(externalLinkErrors([
        ['label' => 'Evento Facebook', 'url' => 'https://facebook.com/events/1'],
        ['label' => 'Sito ufficiale', 'url' => 'http://esempio.test/serata'],
    ]))->toBe([]);
});

it('lascia passare un evento senza link: il campo è facoltativo', function (mixed $vuoto): void {
    expect(externalLinkErrors($vuoto))->toBe([]);
})->with([
    'null' => null,
    'elenco vuoto' => [[]],
]);

it('rifiuta gli schemi diversi da http e https', function (string $url): void {
    $errori = externalLinkErrors([['label' => 'Altro', 'url' => $url]]);

    expect($errori)->toHaveKey('external_links')
        ->and($errori['external_links'][0])->toContain('http, https');
})->with([
    'javascript' => 'javascript:alert(1)',
    'data' => 'data:text/html;base64,PHNjcmlwdD4=',
    'mailto' => 'mailto:info@esempio.test',
    'ftp' => 'ftp://esempio.test/file',
    'senza schema' => 'esempio.test/serata',
]);

it('rifiuta un indirizzo senza host utilizzabile', function (string $url): void {
    $errori = externalLinkErrors([['label' => 'Altro', 'url' => $url]]);

    expect($errori)->toHaveKey('external_links')
        ->and($errori['external_links'][0])->toContain('numero 1');
})->with([
    'host vuoto' => 'https://',
    'solo barre' => 'https:///percorso',
    'host senza suffisso' => 'https://esempio',
    'host con spazio' => 'https://esem pio.test',
]);

it('rifiuta le credenziali prima del dominio', function (): void {
    $errori = externalLinkErrors([
        ['label' => 'Banca', 'url' => 'https://banca.test@dominio-ostile.test/accedi'],
    ]);

    expect($errori)->toHaveKey('external_links');
});

it('rifiuta più di otto link', function (): void {
    $links = [];

    for ($i = 1; $i <= ExternalLinkList::MAX_LINKS + 1; $i++) {
        $links[] = ['label' => 'Link '.$i, 'url' => 'https://esempio.test/'.$i];
    }

    $errori = externalLinkErrors($links);

    expect($errori)->toHaveKey('external_links')
        ->and($errori['external_links'][0])->toContain((string) ExternalLinkList::MAX_LINKS);
});

it('accetta esattamente otto link', function (): void {
    $links = [];

    for ($i = 1; $i <= ExternalLinkList::MAX_LINKS; $i++) {
        $links[] = ['label' => 'Link '.$i, 'url' => 'https://esempio.test/'.$i];
    }

    expect(externalLinkErrors($links))->toBe([]);
});

it('rifiuta un\'etichetta più lunga di quaranta caratteri', function (): void {
    $errori = externalLinkErrors([
        ['label' => str_repeat('a', ExternalLink::MAX_LABEL_LENGTH + 1), 'url' => 'https://esempio.test'],
    ]);

    expect($errori)->toHaveKey('external_links')
        ->and($errori['external_links'][0])->toContain((string) ExternalLink::MAX_LABEL_LENGTH);
});

it('pretende l\'etichetta e dice quale riga manca', function (): void {
    $errori = externalLinkErrors([
        ['label' => 'Instagram', 'url' => 'https://instagram.test/locale'],
        ['label' => '   ', 'url' => 'https://esempio.test'],
    ]);

    expect($errori)->toHaveKey('external_links')
        ->and($errori['external_links'][0])->toContain('numero 2');
});

it('legge le righe di un ripetitore, che sono una mappa e non una lista', function (): void {
    $errori = externalLinkErrors([
        '4b1c-uno' => ['label' => 'Sito ufficiale', 'url' => 'https://esempio.test'],
        '4b1c-due' => ['label' => 'Rotto', 'url' => 'javascript:alert(1)'],
    ]);

    expect($errori)->toHaveKey('external_links')
        ->and($errori['external_links'][0])->toContain('numero 2');
});

// ----------------------------------------------------------------------- cast

it('restituisce sempre un elenco tipizzato, mai un array grezzo', function (): void {
    $city = testCity();
    $category = testCategory();

    $event = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: [
        'external_links' => [
            ['label' => 'Evento Facebook', 'url' => 'https://facebook.test/eventi/1'],
        ],
    ])->event->fresh();

    expect($event->external_links)->toBeInstanceOf(ExternalLinkList::class)
        ->and($event->external_links)->toHaveCount(1)
        ->and($event->external_links->links[0])->toBeInstanceOf(ExternalLink::class)
        ->and($event->external_links->links[0]->label)->toBe('Evento Facebook')
        ->and($event->external_links->toArray())->toBe([
            ['label' => 'Evento Facebook', 'url' => 'https://facebook.test/eventi/1'],
        ]);
});

it('scrive null in colonna quando non resta nessun link', function (): void {
    $city = testCity();
    $category = testCategory();

    $event = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00')->event;

    // Righe inutilizzabili: nel modello non entrano, e la colonna non deve
    // restare con un elenco vuoto che significherebbe la stessa cosa di null.
    $event->update(['external_links' => [
        ['label' => '', 'url' => 'https://esempio.test'],
        ['label' => 'Pericoloso', 'url' => 'javascript:alert(1)'],
    ]]);

    expect($event->fresh()?->external_links->isEmpty())->toBeTrue()
        ->and($event->fresh()?->getRawOriginal('external_links'))->toBeNull();
});

it('non fa entrare nel modello un link che il sito non potrebbe mostrare', function (): void {
    $city = testCity();
    $category = testCategory();

    $event = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00')->event;

    $event->update(['external_links' => [
        ['label' => 'Buono', 'url' => 'https://esempio.test/serata'],
        ['label' => 'Cattivo', 'url' => 'javascript:alert(1)'],
    ]]);

    expect($event->fresh()?->external_links->toArray())->toBe([
        ['label' => 'Buono', 'url' => 'https://esempio.test/serata'],
    ]);
});

// ------------------------------------------------------------- scheda pubblica

it('elenca i link nella scheda pubblica con rel e target di sicurezza', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $event = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: [
        'title' => 'Serata con i link',
        'external_links' => [
            ['label' => 'Evento Facebook', 'url' => 'https://facebook.test/eventi/9'],
            ['label' => 'Rassegna stampa', 'url' => 'https://giornale.test/articolo'],
        ],
    ])->event;

    $html = $this->get('/eventi/'.$event->slug)->assertOk()->getContent();

    expect($html)->toContain(__('events.detail.external_links'))
        ->and($html)->toContain('Evento Facebook')
        ->and($html)->toContain('Rassegna stampa');

    // Ogni ancora verso l'esterno porta i tre valori di `rel` e si apre in
    // una scheda nuova: nessuna eccezione, nessun ordine diverso.
    preg_match_all('#<a\b[^>]*href="(https://(?:facebook|giornale)\.test[^"]*)"[^>]*>#', $html, $anchors);

    expect($anchors[0])->toHaveCount(2);

    foreach ($anchors[0] as $anchor) {
        expect($anchor)->toContain('rel="nofollow noopener noreferrer"')
            ->and($anchor)->toContain('target="_blank"');
    }
});

it('non rende nessuna sezione quando l\'evento non ha link', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 12:00:00');

    $event = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: [
        'title' => 'Serata senza link',
        'external_links' => null,
    ])->event;

    $html = $this->get('/eventi/'.$event->slug)->assertOk()->getContent();

    expect($html)->not->toContain('id="link-evento"')
        ->and($html)->not->toContain(__('events.detail.external_links'))
        ->and($html)->not->toContain('rel="nofollow');
});

// ------------------------------------------------------------------------- API

it('espone i link nella risorsa dell\'evento come coppie etichetta e indirizzo', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $event = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: [
        'external_links' => [
            ['label' => 'Sito ufficiale', 'url' => 'https://esempio.test/serata'],
        ],
    ])->event;

    $data = $this->getJson('/api/v1/events/'.$event->slug)->assertOk()->json('data');

    expect($data['external_links'])->toBe([
        ['label' => 'Sito ufficiale', 'url' => 'https://esempio.test/serata'],
    ]);
});

it('restituisce un elenco vuoto, non null, per un evento senza link', function (): void {
    $city = testCity();
    $category = testCategory();

    freezeLocal($city, '2026-09-05 09:00:00');

    $event = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: [
        'external_links' => null,
    ])->event;

    $data = $this->getJson('/api/v1/events/'.$event->slug)->assertOk()->json('data');

    expect($data['external_links'])->toBe([]);
});

// -------------------------------------------------------------------- pannelli

it('mostra e salva i link nel modulo di /admin', function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $admin = User::factory()->create();
    $admin->assignRole(UserRole::SuperAdmin->value);
    $this->actingAs($admin);

    $city = City::factory()->padova()->create();
    $category = testCategory();

    $event = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00', event: [
        'external_links' => [['label' => 'Instagram', 'url' => 'https://instagram.test/locale']],
    ])->event;

    Filament::setCurrentPanel('admin');

    Livewire::test(AdminEditEvent::class, ['record' => $event->getRouteKey()])
        ->assertFormFieldExists('external_links')
        ->assertFormSet(fn (array $state): bool => $state['external_links'] !== [])
        ->fillForm([
            'external_links' => [
                ['label' => 'Evento Facebook', 'url' => 'https://facebook.test/eventi/3'],
                ['label' => 'Rassegna stampa', 'url' => 'https://giornale.test/pezzo'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($event->refresh()->external_links->toArray())->toBe([
        ['label' => 'Evento Facebook', 'url' => 'https://facebook.test/eventi/3'],
        ['label' => 'Rassegna stampa', 'url' => 'https://giornale.test/pezzo'],
    ]);
});

it('respinge nel modulo di /admin un link con schema non ammesso', function (): void {
    (new RolesAndPermissionsSeeder)->run();

    $admin = User::factory()->create();
    $admin->assignRole(UserRole::SuperAdmin->value);
    $this->actingAs($admin);

    $city = City::factory()->padova()->create();
    $category = testCategory();

    $event = occurrenceAtLocal($city, $category, '2026-09-10 21:00:00')->event;

    Filament::setCurrentPanel('admin');

    Livewire::test(AdminEditEvent::class, ['record' => $event->getRouteKey()])
        ->fillForm([
            'external_links' => [['label' => 'Trappola', 'url' => 'javascript:alert(1)']],
        ])
        ->call('save')
        ->assertHasFormErrors(['external_links']);

    expect($event->refresh()->external_links->isEmpty())->toBeTrue();
});

it('offre biglietti, prenotazione e link esterni nell\'ultimo passo del wizard di /gestione', function (): void {
    $scenario = VenueIsolationScenario::make();

    $this->actingAs($scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($scenario->venueA);

    $componente = Livewire::test(VenueCreateEvent::class);

    $componente->assertFormFieldExists('ticket_url')
        ->assertFormFieldExists('booking_url')
        ->assertFormFieldExists('external_links');

    $componente
        ->fillForm([
            'title' => 'Serata con i link',
            'starts_at' => CarbonImmutable::now('Europe/Rome')->addDays(3)->setTime(21, 30)->format('Y-m-d H:i:s'),
            'category_id' => $scenario->category->getKey(),
            'price_type' => PriceType::Free->value,
            'ticket_url' => 'https://biglietti.test/serata',
            'booking_url' => 'https://prenota.test/serata',
            'external_links' => [
                ['label' => 'Evento Facebook', 'url' => 'https://facebook.test/eventi/7'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $event = Event::query()->where('title', 'Serata con i link')->sole();

    expect($event->ticket_url)->toBe('https://biglietti.test/serata')
        ->and($event->booking_url)->toBe('https://prenota.test/serata')
        ->and($event->external_links->toArray())->toBe([
            ['label' => 'Evento Facebook', 'url' => 'https://facebook.test/eventi/7'],
        ]);
});

it('mostra biglietti, prenotazione e link esterni anche nella scheda di modifica di /gestione', function (): void {
    $scenario = VenueIsolationScenario::make();

    $this->actingAs($scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($scenario->venueA);

    Livewire::test(VenueEditEvent::class, ['record' => $scenario->publishedEventA->getRouteKey()])
        ->assertFormFieldExists('ticket_url')
        ->assertFormFieldExists('booking_url')
        ->assertFormFieldExists('external_links')
        ->fillForm([
            'ticket_url' => 'https://biglietti.test/aurora',
            'booking_url' => 'https://prenota.test/aurora',
            'external_links' => [
                ['label' => 'Sito ufficiale', 'url' => 'https://aurora.test'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $event = $scenario->publishedEventA->refresh();

    expect($event->ticket_url)->toBe('https://biglietti.test/aurora')
        ->and($event->booking_url)->toBe('https://prenota.test/aurora')
        ->and($event->external_links->toArray())->toBe([
            ['label' => 'Sito ufficiale', 'url' => 'https://aurora.test'],
        ]);
});

it('respinge nel wizard di /gestione un elenco con un indirizzo senza dominio', function (): void {
    $scenario = VenueIsolationScenario::make();

    $this->actingAs($scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($scenario->venueA);

    Livewire::test(VenueCreateEvent::class)
        ->fillForm([
            'title' => 'Serata con un link rotto',
            'starts_at' => CarbonImmutable::now('Europe/Rome')->addDays(3)->setTime(21, 30)->format('Y-m-d H:i:s'),
            'category_id' => $scenario->category->getKey(),
            'price_type' => PriceType::Free->value,
            'external_links' => [['label' => 'Sito', 'url' => 'https://']],
        ])
        ->call('create')
        ->assertHasFormErrors(['external_links']);
});
