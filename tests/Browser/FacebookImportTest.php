<?php

declare(strict_types=1);

use App\Filament\Venue\Resources\Events\EventResource;
use App\Models\Event;
use App\Services\Import\HostResolver;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\VenueIsolationScenario;

it('previews the imported photo and editable fields on desktop and mobile', function (string $device): void {
    $scenario = VenueIsolationScenario::make();
    $this->actingAs($scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($scenario->venueA);
    $payload = file_get_contents(base_path('tests/Fixtures/facebook/event.json'));
    Process::fake(['*' => Process::result(output: $payload)]);
    $this->mock(HostResolver::class)->shouldReceive('resolve')->andReturn(['93.184.216.34']);
    $photo = UploadedFile::fake()->image('poster.jpg', 1200, 628);
    Http::fake(['https://www.facebook.com/events/*' => Http::response('<html><script type="application/json">{}</script></html>'), 'https://scontent.xx.fbcdn.net/*' => Http::response(file_get_contents($photo->getPathname()), 200, ['Content-Type' => 'image/jpeg'])]);
    $page = visit(EventResource::getUrl('create', panel: 'venue', tenant: $scenario->venueA))->on()->{$device}()
        ->fill('Hai già un evento su Facebook?', 'https://www.facebook.com/events/1078756118449684/');
    // Wait for lazy textarea components before morphing the entire import form.
    expect($page->script('async () => { const deadline = performance.now() + 5000; do { const fields = [...document.querySelectorAll("textarea[x-model=state]")]; if (fields.every(field => field._x_dataStack?.some(data => Object.prototype.hasOwnProperty.call(data, "state")))) return true; await new Promise(resolve => setTimeout(resolve, 50)); } while (performance.now() < deadline); return false; }'))->toBeTrue();
    $page->click('Carica dal link Facebook')
        ->assertSee('Dati caricati nel modulo')
        ->assertSee('Locale della fonte');
    $page->assertVisible('.filepond--image-preview');
    expect($page->script('document.querySelector("input[id$=\".title\"]").value'))->toBe('Concerto importato');
    $page->fill('input[id$=".title"]', 'Titolo integrato nel browser');
    expect($page->script('document.querySelector("input[id$=\".title\"]").value'))->toBe('Titolo integrato nel browser');
    expect(Event::whereIn('title', ['Concerto importato', 'Titolo integrato nel browser'])->exists())->toBeFalse();
    $page->assertSee('Controlla il luogo prima di salvare');
    expect($page->script('document.querySelector("input[id$=\".custom_location.lat\"]").value'))->toBe('45.42');
    expect($page->script('document.documentElement.scrollWidth <= innerWidth'))->toBeTrue();
    $page->screenshot(filename: 'facebook-import-'.$device);
})->with(['desktop', 'mobile']);
