<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;

/*
 * Le pagine di servizio sono una famiglia sola: la colonna da 48rem del
 * layout `service`, la testata di `<x-service-page>` con un solo h1, card e
 * pulsanti del sistema. Qui si controllano due cose.
 *
 * 1. Le viste: ogni vista che chiede il layout di servizio usa la testata
 *    comune e non scrive larghezze, griglie a più colonne, raggi, fondi
 *    bianchi o pulsanti colorati a mano (eccezioni: il fondo bianco del QR, il
 *    calendario a 7 colonne).
 * 2. Le pagine vere, da ospite e da collegato: rispondono, hanno la colonna
 *    di servizio, un solo h1 e nessuna di quelle classi nel corpo.
 */

const SERVICE_VIEWS_ROOT = 'resources/views';

/** Le viste che chiedono il layout di servizio, più i frammenti che includono. @return list<string> */
function serviceViewFiles(): array
{
    $root = base_path(SERVICE_VIEWS_ROOT);
    $views = collect(File::allFiles($root))
        ->map(fn (SplFileInfo $file): string => $file->getPathname())
        ->filter(fn (string $path): bool => str_ends_with($path, '.blade.php')
            && preg_match('/<x-layouts\.app[^>]*\bservice\b/', (string) file_get_contents($path)) === 1)
        ->values()
        ->all();

    $seen = [];
    while ($views !== []) {
        $path = array_pop($views);
        if (isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        preg_match_all("/@include(?:If|When|Unless)?\\(\\s*'([^']+)'/", (string) file_get_contents($path), $includes);
        foreach ($includes[1] as $name) {
            $included = $root.'/'.str_replace('.', '/', $name).'.blade.php';
            if (is_file($included) && ! isset($seen[$included])) {
                $views[] = $included;
            }
        }
    }

    return array_keys($seen);
}

/**
 * Le classi vietate in una pagina di servizio. Nel sorgente delle viste valgono
 * tutte; nella pagina disegnata il pulsante pieno e le pastiglie tonde dei
 * contatori arrivano dai componenti del sistema (`<x-button>`, le schede), e
 * lì sono giusti.
 *
 * @return array<string, string>
 */
function forbiddenServiceClasses(bool $rendered = false): array
{
    $rules = [
        'larghezza' => '/class="[^"]*(?<![\w-])max-w-(?!prose\b|full\b)/',
        'centratura' => '/class="[^"]*(?<![\w-])mx-auto\b/',
        'griglia' => '/class="[^"]*(?<![\w-])(?:sm:|md:|lg:|xl:)?grid-cols-(?!7\b)[2-9]\b/',
        'raggio' => $rendered ? '/class="[^"]*(?<![\w-])rounded(?!-full\b)(?:-[\w]+)?\b/' : '/class="[^"]*(?<![\w-])rounded(?:-[\w]+)?\b/',
        'fondo bianco' => '/class="[^"]*(?<![\w-])bg-white\b(?![^"]*"[^>]*role="img")/',
    ];

    return $rendered ? $rules : [...$rules, 'pulsante a mano' => '/<(?:a|button)\b[^>]*class="[^"]*(?<![\w-])bg-brand\b/'];
}

it('fa usare a ogni vista di servizio la testata comune, senza larghezze né colori scritti a mano', function (): void {
    $files = serviceViewFiles();
    expect(count($files))->toBeGreaterThan(50);

    $problems = [];
    foreach ($files as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

        if (preg_match('/<x-layouts\.app[^>]*\bservice\b/', $source) === 1 && ! str_contains($source, '<x-service-page')) {
            $problems[] = $relative.': manca <x-service-page>';
        }
        foreach (forbiddenServiceClasses() as $what => $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $problems[] = $relative.': '.$what;
            }
        }
    }

    expect($problems)->toBe([]);
});

it('tiene i pannelli senza colori esadecimali e con la variante scura dei grigi', function (): void {
    $problems = [];
    foreach (File::allFiles(resource_path('views/filament')) as $file) {
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $file->getContents());
        $relative = str_replace(base_path().'/', '', $file->getPathname());

        /* La legenda del calendario della redazione ripete i colori del
           widget, che li decide in PHP: è l'unica eccezione. */
        if (! str_ends_with($relative, 'admin/pages/events-calendar.blade.php') && preg_match('/style="[^"]*#[0-9a-fA-F]{3,8}\b/', $source) === 1) {
            $problems[] = $relative.': colore esadecimale';
        }
        preg_match_all('/class="([^"]*)"/', $source, $classes);
        foreach ($classes[1] as $list) {
            preg_match_all('/(?<![\w:-])(text|bg|border)-gray-\d{2,3}\b/', $list, $grays, PREG_SET_ORDER);
            foreach ($grays as [$token, $property]) {
                if (! preg_match('/(?<![\w-])dark:'.$property.'-/', $list)) {
                    $problems[] = $relative.': '.$token.' senza dark:';
                }
            }
        }
    }

    expect(array_values(array_unique($problems)))->toBe([]);
});

/** @return array<string, array{0: string, 1: bool}> */
function servicePageRoutes(): array
{
    return [
        'accedi' => ['/accedi', false],
        'registrati' => ['/registrati', false],
        'password dimenticata' => ['/password-dimenticata', false],
        'collegamento' => ['/accedi/collegamento', false],
        'registra il tuo locale' => ['/registra-il-tuo-locale', false],
        'registra la tua organizzazione' => ['/registra-la-tua-organizzazione', false],
        'stasera' => ['/stasera', false],
        'aspetto' => ['/aspetto', false],
        'profilo' => ['/il-mio-profilo', true],
        'biglietti' => ['/biglietti', true],
        'le mie richieste' => ['/le-mie-richieste', true],
        'le mie serate' => ['/le-mie-serate', true],
        'interessi' => ['/profilo/interessi', true],
        'persone' => ['/persone', true],
        'profilo social' => ['/profilo-social', true],
        'avvisi' => ['/avvisi', true],
        'passaggi' => ['/passaggi', true],
        'regole dei passaggi' => ['/passaggi/regole', true],
        'messaggi dei passaggi' => ['/passaggi/messaggi', true],
    ];
}

it('disegna le pagine di servizio con la colonna comune e un solo titolo', function (string $name): void {
    (new RolesAndPermissionsSeeder)->run();
    testCity();
    [$url, $auth] = servicePageRoutes()[$name];
    $request = $auth ? $this->actingAs(User::factory()->create(['email_verified_at' => now()])) : $this;

    $html = $request->get($url)->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('data-service-layout')
        ->and($html)->toContain('data-service-page');

    $body = substr($html, (int) strpos($html, 'data-service-page'));
    $body = substr($body, 0, (int) (strpos($body, '</main>') ?: strlen($body)));
    $body = (string) preg_replace(['/<aside hidden data-live-sponsorship.*?<\/aside>/s', '/<dialog .*?<\/dialog>/s'], '', $body);

    foreach (forbiddenServiceClasses(rendered: true) as $what => $pattern) {
        expect(preg_match($pattern, $body))->toBe(0, $name.': '.$what);
    }
})->with(array_keys(servicePageRoutes()));

it('spiega un collegamento firmato scaduto invece di un 403 generico', function (): void {
    (new RolesAndPermissionsSeeder)->run();
    $user = User::factory()->create();

    $response = $this->get(route('account.magic-link.login', ['user' => $user->id, 'expires' => now()->subMinute()->timestamp, 'signature' => 'sbagliata']));

    $response->assertForbidden()
        ->assertSee(__('errors.link.title'))
        ->assertSee(__('errors.link.request'))
        ->assertSee('href="'.route('account.magic-link').'"', false)
        ->assertDontSee(__('errors.403.title'));

    $this->get('/email/verifica/'.$user->id.'/abc?expires=1&signature=x')->assertForbidden()->assertSee(__('errors.link.title'));
    $this->getJson(route('account.magic-link.login', ['user' => $user->id, 'signature' => 'x']))->assertForbidden()->assertJsonMissing(['title' => __('errors.link.title')]);
});

it('riporta all accesso chi esce dalla pagina 403 per cambiare account', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('account.logout'), ['to' => 'login'])->assertRedirect(route('login'));
    $this->actingAs($user)->post(route('account.logout'))->assertRedirect(route('home'));
});

it('non registra la pagina del sistema grafico in produzione', function (): void {
    /* La rotta si registra all'avvio solo in `local` e `testing`: lo si
       chiede a un'applicazione avviata davvero in produzione. */
    $production = Process::env(['APP_ENV' => 'production', 'APP_DEBUG' => 'false'])
        ->path(base_path())
        ->run([PHP_BINARY, 'artisan', 'route:list', '--path=_design', '--json']);

    expect($production->successful())->toBeTrue()
        ->and(json_decode($production->output(), true))->toBe([])
        ->and(Route::has('design.service'))->toBeTrue();
});
