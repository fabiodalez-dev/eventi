<?php

declare(strict_types=1);

use App\Filament\Support\PosterColumn;
use App\Models\City;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

beforeEach(function (): void {
    testCity();
    view()->share('errors', new ViewErrorBag);
});

/** Il pezzo di pagina dentro la colonna di servizio, fino alla fine di `main`. */
function serviceColumn(string $html): string
{
    $start = strpos($html, 'data-service-layout');
    $end = strpos($html, '</main>', (int) $start);

    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($html, (int) $start, (int) $end - (int) $start);
}

it('disegna la pagina campione con un solo titolo e nessuna larghezza annidata', function (): void {
    occurrenceAt(City::query()->firstOrFail(), testCategory(), now()->addDay()->setTime(21, 0)->toDateTimeString());

    $html = $this->get('/_design/servizio')->assertOk()->getContent();
    $column = serviceColumn((string) $html);

    expect(substr_count((string) $html, '<h1'))->toBe(1)
        ->and($column)->toContain('max-w-service')
        ->and(substr_count($column, 'max-w-'))->toBe(1)
        ->and($column)->toContain('data-service-page')
        ->and($column)->toContain('data-card')
        ->and($column)->toContain('data-card-row')
        ->and($column)->toContain('data-stat-list')
        ->and($column)->toContain('data-segmented')
        ->and($column)->toContain('data-event-row');
});

it('migra la pagina Aspetto sul layout di servizio con il ritorno al profilo', function (): void {
    $user = User::factory()->create();

    $html = (string) $this->actingAs($user)->get('/aspetto')->assertOk()->getContent();
    $column = serviceColumn($html);

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(substr_count($html, 'data-profile-back'))->toBe(1)
        ->and(substr_count($column, 'max-w-'))->toBe(1)
        ->and($column)->toContain('data-appearance-choice');
});

it('non stampa il ritorno al profilo del layout nelle pagine di servizio', function (): void {
    $html = Blade::render('<x-layouts.app service title="Prova"><x-service-page title="Prova">corpo</x-service-page></x-layouts.app>');

    expect($html)->not->toContain('data-profile-back')
        ->and($html)->toContain('data-service-layout');
});

it('stampa il ritorno solo quando la pagina ne dichiara uno', function (): void {
    $without = Blade::render('<x-service-page title="Titolo">corpo</x-service-page>');
    $with = Blade::render('<x-service-page title="Titolo" back="/il-mio-profilo">corpo</x-service-page>');
    $label = Blade::render('<x-service-page title="Titolo" back="/biglietti" back-label="I miei biglietti">corpo</x-service-page>');

    expect($without)->not->toContain('data-profile-back')
        ->and(substr_count($without, '<h1'))->toBe(1)
        ->and($with)->toContain('data-profile-back')
        ->and($with)->toContain('Il mio profilo')
        ->and($label)->toContain('I miei biglietti');
});

it('mostra un solo riepilogo degli errori, sotto il titolo e non sotto le schede', function (): void {
    view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag(['email' => ['Email non valida.']])));

    $html = Blade::render('<x-service-page title="Titolo" :tabs="[[\'href\' => \'/a\', \'label\' => \'A\', \'active\' => true]]">corpo</x-service-page>');

    expect(substr_count($html, 'role="alert"'))->toBe(1)
        ->and($html)->toContain('data-form-errors')
        ->and($html)->toContain('Email non valida.')
        ->and($html)->toContain('aria-current="page"');
});

it('lascia il riepilogo degli errori alla testata e non alle schede', function (): void {
    view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag(['email' => ['Email non valida.']])));

    $tabs = '[[\'href\' => \'/a\', \'label\' => \'A\', \'active\' => true]]';

    expect(Blade::render('<x-section-tabs label="Sezioni" :items="'.$tabs.'" />'))->not->toContain('Email non valida.')
        ->and(Blade::render('<x-section-tabs label="Sezioni" :items="'.$tabs.'" :scroll="true" />'))->toContain('svc-tabs--scroll');
});

it('offre le varianti e le misure del pulsante', function (): void {
    expect(Blade::render('<x-button>Salva</x-button>'))->toContain('bg-brand')->toContain('min-h-12')->toContain('type="button"')
        ->and(Blade::render('<x-button variant="secondary">Annulla</x-button>'))->toContain('border-2')
        ->and(Blade::render('<x-button variant="danger" type="submit">Elimina</x-button>'))->toContain('bg-danger')->toContain('text-on-danger')->toContain('type="submit"')
        ->and(Blade::render('<x-button variant="ghost" href="/x">Vai</x-button>'))->toContain('<a href="/x"')->toContain('text-brand')
        ->and(Blade::render('<x-button size="sm">Piccolo</x-button>'))->toContain('min-h-10')->not->toContain('min-h-12')
        ->and(Blade::render('<x-button :block="true">Largo</x-button>'))->toContain('w-full sm:w-auto');
});

it('compone card, righe e avvisi con i soli token del tema', function (): void {
    // Il corpo prima degli slot con nome: `Blade::render` con uno slot con nome
    // prima del contenuto predefinito lascia aperto un buffer (anche con i
    // componenti di Laravel), e PHPUnit segnala il test come rischioso.
    $card = Blade::render('<x-card title="Il tuo ruolo" description="Spiegazione" tone="alert">Corpo<x-slot:actions><x-button size="sm">Esci</x-button></x-slot:actions><x-slot:footer>Piede</x-slot:footer></x-card>');
    $rows = Blade::render('<x-card padding="none" title="Voci"><x-card.row href="/a" icon="bookmark" title="Salvati" badge="Nuovo" /><x-card.row title="Senza link" /></x-card>');
    $notice = Blade::render('<x-notice tone="alert" title="Attenzione">Testo</x-notice>');

    expect($card)->toContain('<h2')->toContain('svc-card--alert')->toContain('svc-card__footer')->toContain('Esci')
        ->and($rows)->toContain('<a')->toContain('href="/a"')->toContain('svc-card--flush')->toContain('Nuovo')
        ->and(substr_count($rows, 'data-card-row'))->toBe(2)
        ->and($notice)->toContain('role="alert"')->toContain('svc-notice--alert');

    foreach ([$card, $rows, $notice] as $html) {
        expect($html)->not->toMatch('/#[0-9a-fA-F]{3,8}\b/')
            ->and($html)->not->toContain('bg-white')
            ->and($html)->not->toContain('max-w-');
    }
});

it('mostra le iniziali quando manca la foto', function (): void {
    expect(Blade::render('<x-avatar name="Giulia Rossi" />'))->toContain('GR')
        ->and(Blade::render('<x-avatar name="Giulia" src="/foto.jpg" size="lg" />'))->toContain('src="/foto.jpg"')->toContain('size-[72px]');
});

it('disegna la riga evento con la locandina o il segnaposto', function (): void {
    $occurrence = occurrenceAt(City::query()->firstOrFail(), testCategory(), now()->addDay()->setTime(21, 0)->toDateTimeString());
    $occurrence->event->update(['title' => 'Concerto in piazza']);

    $html = Blade::render('<x-service-event-row :occurrence="$occurrence"><x-slot:badge>Confermata</x-slot:badge></x-service-event-row>', ['occurrence' => $occurrence->fresh(['event.media'])]);

    expect($html)->toContain('data-event-row')
        ->and($html)->toContain('Concerto in piazza')
        ->and($html)->toContain('data-poster-placeholder')
        ->and($html)->toContain('Confermata')
        ->and($html)->toContain('/eventi/');
});

it('registra la pagina campione solo fuori dalla produzione', function (): void {
    expect(app()->environment('testing'))->toBeTrue()
        ->and(Route::has('design.service'))->toBeTrue();

    $routes = file_get_contents(base_path('routes/web.php'));
    expect($routes)->toContain("if (app()->environment('local', 'testing'))");
});

it('dà alla colonna della locandina dei pannelli una fonte sempre valida', function (): void {
    $occurrence = occurrenceAt(City::query()->firstOrFail(), testCategory(), now()->addDay()->toDateTimeString());
    $event = $occurrence->event;

    expect(PosterColumn::url(null))->toBe(asset(PosterColumn::PLACEHOLDER))
        ->and(PosterColumn::url($event))->toBe(asset(PosterColumn::PLACEHOLDER))
        ->and(is_file(public_path(PosterColumn::PLACEHOLDER)))->toBeTrue();

    $event->update(['poster' => 'https://cdn.example.test/locandina.jpg']);
    expect(PosterColumn::url($event->fresh()))->toBe('https://cdn.example.test/locandina.jpg');

    $column = PosterColumn::make('event.poster_media');
    expect($column->getName())->toBe('event.poster_media')
        ->and($column->getImageWidth())->toBe('48px')
        ->and($column->getImageHeight())->toBe('64px');
});
