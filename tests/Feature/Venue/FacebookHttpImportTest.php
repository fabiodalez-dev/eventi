<?php

declare(strict_types=1);

use App\Services\Import\FacebookEventImport;
use App\Services\Import\FacebookHttpSource;
use App\Services\Import\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->mock(HostResolver::class)->shouldReceive('resolve')->andReturn(['93.184.216.34']);
    Http::preventStrayRequests();
    $this->url = 'https://www.facebook.com/events/1078756118449684/';
    $this->node = [
        'id' => '1078756118449684', 'name' => 'Città e caffè',
        'event_description' => ['text' => 'Descrizione integrale: più dettagli fino alla fine.', 'ranges' => []],
        'start_timestamp' => 1792177200,
        'event_place' => ['name' => 'Piazza della città', 'location' => ['latitude' => 45.42, 'longitude' => 11.87]],
        'cover_media_renderer' => ['cover_photo' => ['photo' => ['full_image' => ['uri' => 'https://scontent.xx.fbcdn.net/poster.jpg']]]],
    ];
    $this->html = '<!doctype html><html><body><script>alert("never execute this")</script><script type="application/json">'.json_encode(['data' => [$this->node, ['id' => '999', 'name' => 'Suggerito', 'event_description' => ['text' => 'Altro evento']]]], JSON_UNESCAPED_UNICODE).'</script></body></html>';
});

it('imports complete Unicode data through HTTP and the real parser without a browser', function (): void {
    Http::fake(['https://www.facebook.com/events/*' => Http::response($this->html)]);
    config(['import.facebook.chromium_binary' => '/missing/chromium']);
    $data = app(FacebookEventImport::class)->fetch($this->url);
    expect($data['title'])->toBe($this->node['name'])
        ->and($data['description'])->toBe($this->node['event_description']['text'])
        ->and($data['venue']['latitude'])->toBe(45.42)
        ->and($data['cover']['url'])->toBe('https://scontent.xx.fbcdn.net/poster.jpg');
    Http::assertSent(fn (Request $request): bool => $request->url() === $this->url.'?locale=it_IT'
        && $request->hasHeader('User-Agent', 'inCitta-event-import/1.0 (+https://eventi.fabiodalez.it)'));
    Http::assertSentCount(1);
});

it('rejects preview-only HTML and never replaces a full description with Open Graph text', function (): void {
    Http::fake(['*' => Http::response('<meta property="og:title" content="Anteprima"><meta property="og:description" content="Testo breve">')]);
    expect(fn () => app(FacebookEventImport::class)->fetch($this->url))->toThrow(RuntimeException::class);
});

it('rejects redirects and HTTP errors without starting the parser', function (int $status): void {
    Process::fake();
    Http::fake(['*' => Http::response('', $status, ['Location' => 'https://127.0.0.1/private'])]);
    expect(fn () => app(FacebookEventImport::class)->fetch($this->url))->toThrow(Exception::class);
    Http::assertSentCount(1);
    Process::assertNothingRan();
})->with([301, 302, 403, 429, 500]);

it('rejects oversized HTML before parsing it', function (): void {
    Process::fake();
    Http::fake(['*' => Http::response(str_repeat('x', FacebookHttpSource::MAX_BYTES + 1))]);
    expect(fn () => app(FacebookEventImport::class)->fetch($this->url))->toThrow(RuntimeException::class);
    Process::assertNothingRan();
});

it('rejects private DNS resolutions before sending the HTTP request', function (): void {
    $this->instance(HostResolver::class, new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return ['127.0.0.1'];
        }
    });
    Process::fake();
    expect(fn () => app(FacebookEventImport::class)->fetch($this->url))->toThrow(Exception::class);
    Http::assertNothingSent();
    Process::assertNothingRan();
});

it('does not load external scripts or document entities', function (): void {
    $html = '<!DOCTYPE html [<!ENTITY external SYSTEM "file:///etc/passwd">]><html><script src="https://evil.test/script.js"></script><script type="application/json">{"safe":true}</script></html>';
    expect(app(FacebookHttpSource::class)->extractScripts($html))->toBe(['{"safe":true}']);
    Http::assertNothingSent();
});

it('rejects noncanonical input even when calling the HTTP source directly', function (): void {
    expect(fn () => app(FacebookHttpSource::class)->scripts('https://www.facebook.com.evil.test/events/123/'))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('pins the checked public DNS address and disables redirects, cookies and proxies', function (): void {
    Http::fake(function (Request $request, array $options) {
        expect($options['allow_redirects'])->toBeFalse()
            ->and($options['cookies'])->toBeFalse()
            ->and($options['proxy'])->toBe('')
            ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['www.facebook.com:443:93.184.216.34']);

        return Http::response($this->html);
    });
    expect(app(FacebookHttpSource::class)->scripts($this->url))->toHaveCount(1);
});

it('blocks DNS rebinding between validation and connection', function (): void {
    $this->instance(HostResolver::class, new class implements HostResolver
    {
        private int $calls = 0;

        public function resolve(string $host): array
        {
            return ++$this->calls === 1 ? ['93.184.216.34'] : ['169.254.169.254'];
        }
    });
    expect(fn () => app(FacebookHttpSource::class)->scripts($this->url))->toThrow(Exception::class);
    Http::assertNothingSent();
});

it('aborts the response stream as soon as the size limit is exceeded', function (): void {
    Process::fake();
    Http::fake(function (Request $request, array $options) {
        $options['sink']->write(str_repeat('x', FacebookHttpSource::MAX_BYTES));
        $options['sink']->write('x');

        return Http::response('unreachable');
    });
    expect(fn () => app(FacebookEventImport::class)->fetch($this->url))->toThrow(Exception::class);
    Process::assertNothingRan();
});

it('rejects credentials and explicit ports in photo URLs before any HTTP request', function (string $url): void {
    expect(fn () => app(FacebookEventImport::class)->photo($url))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with(['https://user:pass@scontent.xx.fbcdn.net/photo.jpg', 'https://scontent.xx.fbcdn.net:443/photo.jpg']);
