<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\DTOs\ExternalLinkList;
use App\Enums\PriceType;
use App\Rules\RealImage;
use App\Services\Http\BoundedStream;
use Carbon\CarbonImmutable;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

class FacebookEventImport
{
    public function __construct(private readonly ImportUrlGuard $guard) {}

    public function canonicalUrl(string $input): string
    {
        if (strlen($input) > 2048) {
            throw new RuntimeException('Il link Facebook è troppo lungo.');
        }
        $parts = parse_url(trim($input));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['pass']) || isset($parts['user']) || isset($parts['port'])
            || ! in_array(strtolower($parts['host'] ?? ''), ['facebook.com', 'www.facebook.com', 'm.facebook.com', 'web.facebook.com'], true)
            || ! preg_match('~^/events/(?:[^/]+/)*([0-9]+)/?$~', $parts['path'] ?? '', $match)) {
            throw new RuntimeException('Inserisci il link HTTPS di un singolo evento Facebook.');
        }

        return 'https://www.facebook.com/events/'.$match[1].'/';
    }

    /** @param list<string> $arguments */
    private function runScript(string $script, array $arguments = [], ?string $input = null): ProcessResult
    {
        return Process::timeout($script === 'check-runtime.mjs' ? 55 : 15)->input($input)->env([
            'FACEBOOK_IMPORT_BROWSER_CHANNEL' => config('import.facebook.browser_channel'),
            'LD_LIBRARY_PATH' => config('import.facebook.library_path'),
            'FACEBOOK_IMPORT_SINGLE_PROCESS' => config('import.facebook.single_process') ? '1' : '0',
            'FACEBOOK_IMPORT_CHROMIUM_BINARY' => config('import.facebook.chromium_binary'),
            'UV_THREADPOOL_SIZE' => '1',
        ])->run([config()->string('import.facebook.node_binary'), '--v8-pool-size=1', base_path('scripts/facebook/'.$script), ...$arguments]);
    }

    public function checkRuntime(bool $browser = false): void
    {
        if (! $browser && (! extension_loaded('curl') || ! class_exists(\DOMDocument::class))) {
            throw new RuntimeException('Il runtime HTTP richiede le estensioni PHP cURL e DOM.');
        }
        $result = $browser ? $this->runScript('check-runtime.mjs') : $this->runScript('parse-scripts.mjs', ['--check']);
        $expected = $browser ? ['chromium' => true, 'javascript' => true] : ['http_parser' => true];
        if (! $result->successful() || json_decode($result->output(), true) !== $expected) {
            throw new RuntimeException(($browser ? 'Chromium' : 'Parser HTTP').' non operativo: '.$result->errorOutput());
        }
    }

    /** @return array<string, mixed> */
    public function fetch(string $input): array
    {
        $url = $this->canonicalUrl($input);
        $lock = Cache::lock('facebook-import:browser', 65);
        if (! $lock->get()) {
            throw new RuntimeException('Un’importazione è già in corso. Riprova tra poco.');
        }
        try {
            $scripts = app(FacebookHttpSource::class)->scripts($url);
            $result = $this->runScript('parse-scripts.mjs', [$url], json_encode($scripts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } finally {
            $lock->release();
        }

        if (! $result->successful()) {
            report(new RuntimeException('Facebook import: '.$result->errorOutput()));
            throw new RuntimeException('Non è stato possibile leggere questo evento pubblico. Riprova oppure compila il modulo manualmente.');
        }
        if (strlen($result->output()) > 1024 * 1024) {
            throw new RuntimeException('I dati restituiti da Facebook sono troppo grandi.');
        }
        $data = json_decode($result->output(), true, 64, JSON_THROW_ON_ERROR);
        Validator::make($data, [
            'id' => ['required', 'regex:/^[0-9]+$/'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:50000'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', ...(! empty($data['starts_at']) ? ['after:starts_at'] : [])],
            'url' => ['required', 'in:'.$url],
            'cover' => ['nullable', 'array'],
            'cover.url' => ['nullable', 'string', 'max:4096'],
            'date_label' => ['nullable', 'string', 'max:1000'],
            'ticket_url' => ['nullable', 'url:https,http', 'max:255'],
            'is_cancelled' => ['sometimes', 'boolean'],
            'is_online' => ['sometimes', 'boolean'],
            'venue' => ['nullable', 'array'],
            'venue.name' => ['nullable', 'string', 'max:255'],
            'venue.address' => ['nullable', 'string', 'max:255'],
            'venue.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'venue.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'hosts' => ['sometimes', 'array', 'max:100'],
            'hosts.*.name' => ['nullable', 'string', 'max:500'],
            'responded_count' => ['nullable', 'integer', 'min:0'],
            'interested_count' => ['nullable', 'integer', 'min:0'],
            'going_count' => ['nullable', 'integer', 'min:0'],
            'external_links.*' => ['string', 'max:4096'],
            'external_links' => ['sometimes', 'array', 'max:100'],
        ])->validate();
        if ($url !== 'https://www.facebook.com/events/'.$data['id'].'/') {
            throw new RuntimeException('Facebook ha restituito un evento diverso da quello richiesto.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function locationAddress(array $data): ?string
    {
        $place = $data['venue'] ?? [];
        if (filled($place['address'] ?? null)) {
            return $place['address'];
        }

        // Facebook may identify a park or square only by its name and map pin.
        return filled($place['name'] ?? null) && isset($place['latitude'], $place['longitude'])
            ? $place['name'] : null;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function formData(array $data, string $timezone): array
    {
        $fields = [
            'description' => $data['description'],
            'price_type' => PriceType::Unknown->value,
            'price_min' => null,
            'price_max' => null,
            'external_links' => [['label' => __('facebook_import.source_link'), 'url' => $data['url']]],
        ];
        if (! empty($data['title'])) {
            $fields['title'] = $data['title'];
        }
        foreach (['starts_at', 'ends_at'] as $dateField) {
            if (! empty($data[$dateField])) {
                $fields[$dateField] = CarbonImmutable::parse($data[$dateField])->setTimezone($timezone)->format('Y-m-d H:i');
            }
        }
        if (($address = self::locationAddress($data)) !== null) {
            $fields['custom_location'] = [
                'name' => $data['venue']['name'] ?? null,
                'address' => $address,
                'lat' => $data['venue']['latitude'] ?? null,
                'lng' => $data['venue']['longitude'] ?? null,
            ];
        }
        // Only explicit, unambiguous admission prices. Other prices stay in the full description.
        if (preg_match('/\bIngresso\s*€\s*(\d+(?:[.,]\d{1,2})?)(?![\d.,])/iu', $data['description'], $price)) {
            $fields['price_type'] = PriceType::Ticket->value;
            $fields['price_min'] = (float) str_replace(',', '.', $price[1]);
        }
        if (! empty($data['ticket_url'])) {
            $fields['ticket_url'] = $data['ticket_url'];
        }
        foreach (array_slice($data['external_links'] ?? [], 0, ExternalLinkList::MAX_LINKS - 1) as $link) {
            if (is_string($link) && strlen($link) <= 255 && filter_var($link, FILTER_VALIDATE_URL)
                && in_array(parse_url($link, PHP_URL_SCHEME), ['http', 'https'], true)) {
                $fields['external_links'][] = ['label' => __('facebook_import.external_link'), 'url' => $link];
            }
        }

        return $fields;
    }

    public function photo(string $url): TemporaryUploadedFile
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (strlen($url) > 4096 || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || ! str_ends_with($host, '.fbcdn.net')) {
            throw new RuntimeException('La foto non proviene dal server immagini di Facebook.');
        }
        $address = $this->guard->resolvedAddress($url);
        $response = Http::timeout(20)->connectTimeout(5)->setHandler(new CurlHandler)->withOptions([
            'proxy' => '', 'allow_redirects' => false,
            'sink' => new BoundedStream(config()->integer('media.max_upload_bytes')),
            'curl' => $address === null ? [] : [CURLOPT_RESOLVE => [$host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)]],
        ])->get($url)->throw();
        if (! $response->successful()) {
            throw new RuntimeException('La foto non è disponibile senza reindirizzamenti.');
        }
        $bytes = $response->body();
        if (strlen($bytes) > config()->integer('media.max_upload_bytes')) {
            throw new RuntimeException('La foto supera la dimensione massima consentita.');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info[0] * $info[1] > 40000000) {
            throw new RuntimeException('La foto di Facebook non è un’immagine supportata.');
        }
        $extension = match ($info['mime']) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg'
        };
        $name = Str::random(30).'-meta'.base64_encode('evento-facebook.'.$extension).'-.'.$extension;
        FileUploadConfiguration::storage()->put(FileUploadConfiguration::path($name, false), $bytes);

        $upload = TemporaryUploadedFile::createFromLivewire($name);
        try {
            Validator::make(['photo' => $upload], ['photo' => [new RealImage]])->validate();
        } catch (\Throwable $error) {
            $upload->delete();
            throw $error;
        }

        return $upload;
    }
}
