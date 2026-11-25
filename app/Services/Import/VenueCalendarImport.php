<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\DTOs\ImportedEventDto;
use App\DTOs\ImportReport;
use App\Enums\ImportSourceType;
use App\Exceptions\ImportException;
use App\Jobs\Import\ImportSourceJob;
use App\Models\ImportSource;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

/**
 * Il calendario collegato di un locale (§14.2, W-IM-01…07): le regole che
 * stavano dentro la pagina «Calendario collegato» del pannello, adesso usate
 * dalla pagina e dall'API dell'app.
 *
 * Un locale ha una sorgente sola, ICS o CSV, e la governa il referente
 * (`ImportSourcePolicy`). Si collega solo dopo l'anteprima: nella pagina lo
 * ricorda lo stato del componente, nell'app un `preview_token` firmato che
 * lega l'anteprima al locale, alla persona e a ciò che si è guardato.
 *
 * **Il CSV caricato.** Sta sul disco privato (`storage/app/private`), in
 * `imports/<locale>/`, e la sorgente lo indica come `upload:<nome>`: il
 * driver lo legge da lì e da nessun'altra cartella. Nella pagina lo salva il
 * campo di Filament con un nome casuale; dall'app lo salvo io con il nome
 * ricavato dal contenuto (`calendario-<sha256>.csv`), così lo stesso foglio
 * caricato due volte resta un file solo. L'anteprima dell'app non lascia
 * niente: il file entra nella cartella solo per il tempo della lettura.
 * Quando la sorgente passa a un altro file o a un indirizzo, o si scollega,
 * il file di prima si cancella, e con lui quelli rimasti da anteprime della
 * pagina mai collegate (più vecchi di un giorno): con 10 GB di quota un
 * foglio da 8 MB ricaricato ogni settimana non deve restare per sempre.
 */
final class VenueCalendarImport
{
    /** Quanto vale un'anteprima dell'app: abbastanza per leggerla, non per tenerla in tasca giorni. */
    public const PREVIEW_TOKEN_MINUTES = 60;

    /** Gli stessi tetti del modulo della pagina. */
    public const MAX_KEYWORDS = 100;

    public const MAX_KEYWORD_LENGTH = 100;

    public const MAX_URL_LENGTH = 1000;

    /** I file di anteprime della pagina mai collegate si tengono un giorno, poi si tolgono. */
    private const ORPHAN_HOURS = 24;

    public function __construct(private readonly ImportRunner $runner) {}

    /**
     * I due tipi che il locale può collegare.
     *
     * @return list<ImportSourceType>
     */
    public static function types(): array
    {
        return [ImportSourceType::Ics, ImportSourceType::Csv];
    }

    /**
     * La sorgente del locale, se esiste.
     *
     * Un locale ha un calendario solo: chi ne ha due tenga il secondo in
     * redazione, dove l'elenco completo è già previsto.
     */
    public function source(Venue $venue): ?ImportSource
    {
        return ImportSource::query()
            ->where('venue_id', $venue->getKey())
            ->whereIn('type', array_map(static fn (ImportSourceType $type): string => $type->value, self::types()))
            ->latest('id')
            ->first();
    }

    public static function isUpload(?ImportSource $source): bool
    {
        return $source !== null && str_starts_with((string) $source->url, CsvImportDriver::UPLOAD_PREFIX);
    }

    /**
     * I valori con cui si apre il modulo: quelli della sorgente, oppure ICS e
     * le parole escluse predefinite. Un file caricato non torna nell'indirizzo.
     *
     * @return array{type: string, url: string|null, exclude_keywords: list<string>}
     */
    public function formDefaults(?ImportSource $source): array
    {
        $keywords = $source !== null
            ? $source->mapping['exclude_keywords'] ?? config('import.exclude_keywords')
            : config('import.exclude_keywords');

        return [
            'type' => $source?->type->value ?? ImportSourceType::Ics->value,
            'url' => $source !== null && ! self::isUpload($source) ? $source->url : null,
            'exclude_keywords' => array_values(array_map('strval', is_array($keywords) ? $keywords : [])),
        ];
    }

    /**
     * Una sorgente non salvata, che serve solo all'anteprima. Se il locale ne
     * ha già una, è quella in memoria con i valori nuovi: non si salva.
     *
     * @param  list<string>  $keywords
     */
    public function draft(Venue $venue, ImportSourceType $type, string $url, array $keywords): ImportSource
    {
        $source = $this->source($venue) ?? new ImportSource;

        $source->fill([
            'city_id' => $venue->city_id,
            'venue_id' => $venue->getKey(),
            'type' => $type,
            'url' => $url,
            'mapping' => ['exclude_keywords' => $keywords],
        ]);

        $source->setRelation('venue', $venue);

        return $source;
    }

    /**
     * Guarda cosa entrerebbe, senza scrivere niente.
     *
     * @param  list<string>  $keywords
     * @return list<ImportedEventDto>
     *
     * @throws ImportException quando il calendario è irraggiungibile o illeggibile
     */
    public function preview(Venue $venue, ImportSourceType $type, string $url, array $keywords): array
    {
        return $this->runner->preview($this->draft($venue, $type, $url, $keywords));
    }

    /**
     * L'anteprima di un CSV che arriva come contenuto (l'app): il file entra
     * nella cartella del locale solo per la lettura, poi se ne va, a meno che
     * non sia proprio quello che la sorgente usa già.
     *
     * @param  list<string>  $keywords
     * @return list<ImportedEventDto>
     *
     * @throws ImportException
     */
    public function previewContents(Venue $venue, string $contents, array $keywords): array
    {
        $name = self::uploadName($contents);
        $path = $this->uploadPath($venue, $name);
        $inUse = in_array($name, $this->uploadsInUse($venue), true);

        Storage::disk('local')->put($path, $contents);

        try {
            return $this->preview($venue, ImportSourceType::Csv, CsvImportDriver::UPLOAD_PREFIX.$name, $keywords);
        } finally {
            if (! $inUse) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    /**
     * Salva il CSV arrivato dall'app nella cartella del locale e restituisce
     * l'indirizzo che la sorgente userà (`upload:<nome>`).
     */
    public function storeContents(Venue $venue, string $contents): string
    {
        $name = self::uploadName($contents);
        Storage::disk('local')->put($this->uploadPath($venue, $name), $contents);

        return CsvImportDriver::UPLOAD_PREFIX.$name;
    }

    /** Il nome del file caricato dall'app: lo stesso contenuto, lo stesso file. */
    public static function uploadName(string $contents): string
    {
        return 'calendario-'.hash('sha256', $contents).'.csv';
    }

    /**
     * Accende la sorgente, o ne aggiorna una già esistente, e accoda subito
     * la prima lettura. Da lì la legge `import:run` ogni ora.
     *
     * @param  list<string>  $keywords
     */
    public function connect(Venue $venue, ImportSourceType $type, string $url, array $keywords): ImportSource
    {
        $source = $this->source($venue) ?? new ImportSource;
        $previous = (string) $source->url;

        $source->fill([
            'city_id' => $venue->city_id,
            'venue_id' => $venue->getKey(),
            'type' => $type,
            'url' => $url,
            'mapping' => ['exclude_keywords' => $keywords],
            'is_active' => true,
        ]);

        $source->save();

        if ($previous !== $url) {
            $this->forgetUpload($venue, $previous);
        }
        $this->pruneUploads($venue);

        ImportSourceJob::dispatch($source->getKey());

        return $source;
    }

    /**
     * «Leggi adesso»: una lettura subito, nello stesso processo.
     *
     * @throws ImportException quando il calendario è irraggiungibile o illeggibile
     */
    public function run(ImportSource $source): ImportReport
    {
        return $this->runner->run($source);
    }

    /** Sospende o riattiva: una sorgente sospesa resta collegata ma `import:run` la salta. */
    public function setActive(ImportSource $source, bool $active): ImportSource
    {
        $source->update(['is_active' => $active]);

        return $source;
    }

    /**
     * Scollega. Le date già pubblicate restano dove sono: si interrompe solo
     * la lettura del calendario. Il file caricato, se c'era, non serve più.
     */
    public function disconnect(ImportSource $source): void
    {
        $venue = $source->venue;
        $url = (string) $source->url;

        $source->delete();

        if ($venue instanceof Venue) {
            $this->forgetUpload($venue, $url);
            $this->pruneUploads($venue);
        }
    }

    /**
     * Il segno che l'anteprima c'è stata, per l'app: il locale, la persona, il
     * tipo e ciò che si è letto (l'indirizzo, o l'impronta del file). Cifrato
     * con la chiave dell'applicazione, scade dopo un'ora.
     */
    public function previewToken(Venue $venue, User $user, ImportSourceType $type, string $subject): string
    {
        return Crypt::encryptString((string) json_encode([
            'venue' => (int) $venue->getKey(),
            'user' => (int) $user->getKey(),
            'type' => $type->value,
            'subject' => $subject,
            'expires' => now()->addMinutes(self::PREVIEW_TOKEN_MINUTES)->getTimestamp(),
        ]));
    }

    /** Se il segno descrive proprio questo collegamento, ed è ancora valido. */
    public function previewTokenMatches(?string $token, Venue $venue, User $user, ImportSourceType $type, string $subject): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return false;
        }

        return is_array($payload)
            && ($payload['venue'] ?? null) === (int) $venue->getKey()
            && ($payload['user'] ?? null) === (int) $user->getKey()
            && ($payload['type'] ?? null) === $type->value
            && ($payload['subject'] ?? null) === $subject
            && is_int($payload['expires'] ?? null)
            && $payload['expires'] >= now()->getTimestamp();
    }

    /** Ciò che il segno dell'anteprima ricorda della sorgente: l'indirizzo o l'impronta del file. */
    public static function previewSubject(?string $url, ?string $contents): string
    {
        return $contents !== null ? 'file:'.hash('sha256', $contents) : 'url:'.trim((string) $url);
    }

    private function uploadPath(Venue $venue, string $name): string
    {
        return CsvImportDriver::UPLOAD_DIRECTORY.'/'.$venue->getKey().'/'.$name;
    }

    /**
     * Toglie il file indicato da un vecchio `upload:<nome>`, solo dentro la
     * cartella del locale e solo se nessuna sorgente del locale lo usa ancora.
     */
    private function forgetUpload(Venue $venue, string $url): void
    {
        if (! str_starts_with($url, CsvImportDriver::UPLOAD_PREFIX)) {
            return;
        }

        $name = substr($url, strlen(CsvImportDriver::UPLOAD_PREFIX));

        if (CsvImportDriver::isPlainName($name) && ! in_array($name, $this->uploadsInUse($venue), true)) {
            Storage::disk('local')->delete($this->uploadPath($venue, $name));
        }
    }

    /**
     * I nomi dei file che le sorgenti del locale usano (di solito uno, ma la
     * redazione può averne dichiarate altre).
     *
     * @return list<string>
     */
    private function uploadsInUse(Venue $venue): array
    {
        return ImportSource::query()
            ->where('venue_id', $venue->getKey())
            ->where('url', 'like', CsvImportDriver::UPLOAD_PREFIX.'%')
            ->pluck('url')
            ->map(static fn (mixed $url): string => substr((string) $url, strlen(CsvImportDriver::UPLOAD_PREFIX)))
            ->values()
            ->all();
    }

    /**
     * I file della cartella del locale che nessuna sorgente usa e che hanno
     * più di un giorno: sono anteprime della pagina mai collegate. Quelli più
     * recenti restano, perché qualcuno potrebbe star per premere «Collega».
     */
    private function pruneUploads(Venue $venue): void
    {
        $disk = Storage::disk('local');
        $inUse = $this->uploadsInUse($venue);
        $threshold = now()->subHours(self::ORPHAN_HOURS)->getTimestamp();

        foreach ($disk->files(CsvImportDriver::UPLOAD_DIRECTORY.'/'.$venue->getKey()) as $path) {
            if (in_array(basename($path), $inUse, true) || $disk->lastModified($path) > $threshold) {
                continue;
            }

            $disk->delete($path);
        }
    }
}
