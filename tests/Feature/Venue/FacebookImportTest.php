<?php

declare(strict_types=1);

use App\Filament\Venue\Resources\Events\Pages\CreateEvent;
use App\Models\Event;
use App\Services\Import\FacebookEventImport;
use App\Services\Import\HostResolver;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\VenueIsolationScenario;

beforeEach(function (): void {
    $this->scenario = VenueIsolationScenario::make();
    $this->actingAs($this->scenario->ownerA);
    Filament::setCurrentPanel('venue');
    Filament::setTenant($this->scenario->venueA);
    $this->payload = json_decode(file_get_contents(base_path('tests/Fixtures/facebook/event.json')), true);
    $this->mock(HostResolver::class)->shouldReceive('resolve')->andReturn(['93.184.216.34']);
    Http::preventStrayRequests();
});

it('prefills the existing form and photo without creating an event, then saves user edits and original metadata', function (): void {
    Process::fake(['*' => Process::result(output: json_encode($this->payload))]);
    $image = UploadedFile::fake()->image('source.jpg', 1200, 628);
    Http::fake(['https://scontent.xx.fbcdn.net/*' => Http::response(file_get_contents($image->getPathname()), 200, ['Content-Type' => 'image/jpeg'])]);
    $before = Event::count();
    $page = Livewire::test(CreateEvent::class)
        ->fillForm(['facebook_url' => $this->payload['url']])
        ->call('importFacebook')
        ->assertFormSet(['title' => 'Concerto importato', 'starts_at' => '2026-10-16 21:00', 'price_type' => 'ticket', 'price_min' => 15, 'custom_location.address' => 'Via Ticino 5, Padova', 'custom_location.lat' => 45.42, 'custom_location.lng' => 11.87])
        ->assertSee('Controlla il luogo prima di salvare')
        ->assertNotified('Dati caricati nel modulo');
    expect(Event::count())->toBe($before)
        ->and($page->get('data.poster_media'))->toHaveCount(1)
        ->and(json_encode($page->get('data.description')))->toContain('Informazioni aggiuntive alla fine.');
    $page->fillForm([
        'title' => 'Titolo corretto dal locale',
        'description' => '<p>Descrizione integrata dal locale</p>',
        'starts_at' => '2026-10-16 23:00:00',
        'category_id' => $this->scenario->category->id,
        'repeat' => false,
    ])->call('create')->assertHasNoFormErrors();
    $event = Event::where('title', 'Titolo corretto dal locale')->sole();
    expect($event->description)->toContain('Descrizione integrata dal locale')
        ->and($event->venue_id)->toBe($this->scenario->venueA->id)
        ->and($event->occurrences()->sole()->starts_at->format('Y-m-d H:i:s'))->toBe('2026-10-16 21:00:00')
        ->and($event->getFirstMedia('poster'))->not->toBeNull()
        ->and($event->source_metadata['facebook']['description'])->toBe($this->payload['description'])
        ->and($event->source_ref)->toBe('facebook:1078756118449684')
        ->and($event->custom_location['lat'])->toBe(45.42)
        ->and($event->custom_location['lng'])->toBe(11.87);
});

it('preserves existing input on extraction failure', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1, errorOutput: 'Page unavailable')]);
    Livewire::test(CreateEvent::class)->fillForm(['title' => 'Lavoro già iniziato', 'facebook_url' => $this->payload['url']])
        ->call('importFacebook')->assertNotified('Importazione non riuscita')
        ->assertFormSet(['title' => 'Lavoro già iniziato']);
});

it('imports text even when the remote photo is unavailable', function (): void {
    Process::fake(['*' => Process::result(output: json_encode($this->payload))]);
    Http::fake(['*' => Http::response('', 404)]);
    Livewire::test(CreateEvent::class)->fillForm(['facebook_url' => $this->payload['url']])
        ->call('importFacebook')->assertFormSet(['title' => 'Concerto importato'])
        ->assertNotified('Dati caricati nel modulo');
});

it('rejects unsupported URLs before invoking a browser', function (string $url): void {
    Process::fake();
    expect(fn () => app(FacebookEventImport::class)->fetch($url))->toThrow(RuntimeException::class);
    Process::assertNothingRan();
})->with(['http://facebook.com/events/123/', 'https://facebook.com.evil.test/events/123/', 'https://127.0.0.1/events/123/', 'https://user:pass@facebook.com/events/123/', 'https://www.facebook.com/csopedro', 'https://facebook.com:8080/events/123/']);

it('rejects wrong-event, missing-description and invalid JSON responses', function (string $variant): void {
    $data = $this->payload;
    if ($variant === 'id') {
        $data['id'] = '999';
    }
    if ($variant === 'description') {
        $data['description'] = null;
    }
    Process::fake(['*' => Process::result(output: $variant === 'json' ? 'not json' : json_encode($data))]);
    expect(fn () => app(FacebookEventImport::class)->fetch($data['url']))->toThrow(Exception::class);
})->with(['id', 'description', 'json']);

it('rejects unsafe, fake and oversized images', function (string $variant): void {
    if ($variant === 'host') {
        expect(fn () => app(FacebookEventImport::class)->photo('https://localhost/event.jpg'))->toThrow(RuntimeException::class);
        Http::assertNothingSent();

        return;
    }
    config(['media.max_upload_bytes' => 100]);
    Http::fake(['*' => Http::response($variant === 'large' ? str_repeat('x', 101) : '<html>not a photo</html>')]);
    expect(fn () => app(FacebookEventImport::class)->photo($this->payload['cover']['url']))->toThrow(RuntimeException::class);
})->with(['host', 'fake', 'large']);

it('does not guess a free price when Facebook provides no price', function (): void {
    $this->payload['description'] = 'Un evento senza prezzo dichiarato.';
    expect(app(FacebookEventImport::class)->formData($this->payload, 'Europe/Rome')['price_type'])->toBe('unknown');
});

it('enforces per-account burst and daily limits before starting the scraper', function (string $period): void {
    Process::fake();
    $key = 'facebook-import:'.$this->scenario->ownerA->id.($period === 'daily' ? ':daily' : '');
    for ($i = 0; $i < ($period === 'daily' ? 30 : 3); $i++) {
        RateLimiter::hit($key, 86400);
    }
    Livewire::test(CreateEvent::class)->fillForm(['facebook_url' => $this->payload['url']])->call('importFacebook');
    Process::assertNothingRan();
})->with(['burst', 'daily']);

it('does not run simultaneous browser imports and releases its lock after failure', function (): void {
    Process::fake(['*' => Process::result(exitCode: 1)]);
    $lock = Cache::lock('facebook-import:browser', 65);
    $lock->get();
    try {
        expect(fn () => app(FacebookEventImport::class)->fetch($this->payload['url']))->toThrow(RuntimeException::class);
        Process::assertNothingRan();
    } finally {
        $lock->release();
    }
    expect(fn () => app(FacebookEventImport::class)->fetch($this->payload['url']))->toThrow(RuntimeException::class);
    $nextLock = Cache::lock('facebook-import:browser', 65);
    expect($nextLock->get())->toBeTrue();
    $nextLock->release();
});

it('refuses private DNS destinations and photo redirects without following them', function (string $variant): void {
    if ($variant === 'dns') {
        $this->instance(HostResolver::class, new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['127.0.0.1'];
            }
        });
        expect(fn () => app(FacebookEventImport::class)->photo($this->payload['cover']['url']))->toThrow(Exception::class);
        Http::assertNothingSent();
    } else {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/private'])]);
        expect(fn () => app(FacebookEventImport::class)->photo($this->payload['cover']['url']))->toThrow(RuntimeException::class);
        Http::assertSentCount(1);
    }
})->with(['dns', 'redirect']);

it('converts summer and winter UTC timestamps into the venue timezone', function (): void {
    $import = app(FacebookEventImport::class);
    expect($import->formData($this->payload, 'Europe/Rome')['starts_at'])->toBe('2026-10-16 21:00');
    $this->payload['starts_at'] = '2026-12-16T19:00:00Z';
    expect($import->formData($this->payload, 'Europe/Rome')['starts_at'])->toBe('2026-12-16 20:00');
});

it('keeps imported metadata locked against browser-side changes', function (): void {
    Livewire::test(CreateEvent::class)->set('facebookImport', ['id' => 'other']);
})->throws(CannotUpdateLockedPropertyException::class);

it('checks the current venue permission again when importing', function (): void {
    Process::fake();
    $page = Livewire::test(CreateEvent::class)->fillForm(['facebook_url' => $this->payload['url']]);
    $this->actingAs($this->scenario->ownerB);
    $page->call('importFacebook')->assertForbidden();
    Process::assertNothingRan();
});

it('rejects oversized scraper output before filling the form', function (): void {
    Process::fake(['*' => Process::result(output: str_repeat('x', 1024 * 1024 + 1))]);
    expect(fn () => app(FacebookEventImport::class)->fetch($this->payload['url']))->toThrow(RuntimeException::class);
});

it('sanitizes imported markup when saving through the same editor validation as manual events', function (): void {
    $this->payload['description'] = '<p>Testo sicuro</p><script>alert(1)</script><img src=x onerror=alert(2)>';
    $this->payload['cover'] = null;
    Process::fake(['*' => Process::result(output: json_encode($this->payload))]);
    Livewire::test(CreateEvent::class)->fillForm(['facebook_url' => $this->payload['url']])
        ->call('importFacebook')->fillForm(['category_id' => $this->scenario->category->id])
        ->call('create')->assertHasNoFormErrors();
    $event = Event::where('title', 'Concerto importato')->sole();
    expect($event->description)->toContain('Testo sicuro')->not->toContain('<script', 'onerror');
});

it('imports a description with missing optional fields and preserves fields already entered', function (): void {
    $this->payload['starts_at'] = null;
    $this->payload['ends_at'] = null;
    $this->payload['cover'] = null;
    Process::fake(['*' => Process::result(output: json_encode($this->payload))]);
    $before = Event::count();
    $page = Livewire::test(CreateEvent::class)->fillForm([
        'facebook_url' => $this->payload['url'],
        'title' => 'Titolo già inserito',
        'starts_at' => '2026-12-20 20:30',
    ])->call('importFacebook')->assertNotified('Dati caricati nel modulo')
        ->assertFormSet(['title' => 'Concerto importato', 'starts_at' => '2026-12-20 20:30']);
    expect(json_encode($page->get('data.description')))->toContain('Informazioni aggiuntive alla fine.')
        ->and(Event::count())->toBe($before);
});

it('rejects an import without its Facebook title', function (): void {
    $this->payload['title'] = null;
    Process::fake(['*' => Process::result(output: json_encode($this->payload))]);
    Livewire::test(CreateEvent::class)->fillForm(['title' => 'Titolo manuale', 'facebook_url' => $this->payload['url']])
        ->call('importFacebook')->assertNotified('Importazione non riuscita')->assertFormSet(['title' => 'Titolo manuale']);
});

it('keeps a Facebook map pin without a street and warns before overriding the venue', function (): void {
    $this->payload['venue']['address'] = null;
    $this->payload['venue']['name'] = "Giardini dell'Arena, Padova PD, Italia";
    $this->payload['cover'] = null;
    Process::fake(['*' => Process::result(output: json_encode($this->payload))]);
    $page = Livewire::test(CreateEvent::class)->fillForm(['facebook_url' => $this->payload['url']])
        ->call('importFacebook')
        ->assertFormSet(['custom_location.address' => $this->payload['venue']['name'], 'custom_location.lat' => 45.42, 'custom_location.lng' => 11.87])
        ->assertSee('Controlla il luogo prima di salvare');
    $page->fillForm(['custom_location.address' => ''])->assertDontSee('Controlla il luogo prima di salvare');
});

it('does not replace the venue with an unlocated Facebook place name', function (): void {
    $this->payload['venue']['address'] = null;
    $this->payload['venue']['latitude'] = null;
    expect(app(FacebookEventImport::class)->formData($this->payload, 'Europe/Rome'))->not->toHaveKey('custom_location');
});
