<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Actions\GenerateOccurrencesAction;
use App\DTOs\ImportedEventDto;
use App\DTOs\ImportMapping;
use App\DTOs\ImportReport;
use App\Enums\EventStatus;
use App\Enums\ImportRunStatus;
use App\Enums\OccurrenceStatus;
use App\Enums\VerificationStatus;
use App\Exceptions\ImportException;
use App\Models\Category;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\EventRecurrence;
use App\Models\ImportRun;
use App\Models\ImportSource;
use App\Support\Import\SourceRef;
use App\Support\VenueEventDefaults;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Il core dell'import (§14.2): `fetch → map → filtro → deduplica → scrittura`,
 * più il resoconto di ciò che è successo.
 *
 * Non nomina alcun driver — glielo consegna `ImportDriverFactory` — ed è questo
 * che permette di aggiungere una sorgente RSS o un'API senza toccare questa
 * classe.
 *
 * **L'idempotenza.** La chiave è `events.source_ref` (`SourceRef`): la seconda
 * esecuzione dello stesso feed non crea nulla e non modifica nulla, e l'unico
 * contatore che si muove è «invariati». Una voce cambiata a monte **aggiorna**
 * l'evento che esiste già; non ne nasce mai un secondo.
 *
 * **Ciò che si aggiorna e ciò che non si tocca.** L'import riscrive quello che
 * il calendario dichiara — titolo, descrizione, luogo, date — e lascia stare
 * tutto il resto. In particolare non rimette `status` a `published` e non
 * riabbassa `verification_status`: se un redattore ha ritirato o verificato un
 * evento importato, l'esecuzione oraria non deve disfare quella decisione ogni
 * sessanta minuti.
 *
 * **Le sparizioni non cancellano.** Una voce che non compare più nel feed viene
 * marcata `cancelled` sulle occorrenze future e contata nel resoconto. Non si
 * cancella niente, e la spazzata non parte affatto se anche una sola voce del
 * feed non si è lasciata interpretare: un'esecuzione che ha capito il feed a
 * metà non sa che cosa è davvero sparito. Un feed irraggiungibile non arriva
 * nemmeno fin qui — è un'eccezione, e le eccezioni si riprovano.
 *
 * **Le ricorrenze passano da `GenerateOccurrencesAction`.** Le `RRULE` del file
 * non vengono espanse qui: si scrive la regola in `event_recurrences` e si
 * chiama l'azione, che è già idempotente. Quando la regola cambia a monte, le
 * date che non produce più vengono annullate confrontandole con
 * `expectedStarts()` della stessa azione.
 */
final class ImportRunner
{
    public function __construct(
        private readonly ImportDriverFactory $drivers,
        private readonly GenerateOccurrencesAction $generate,
    ) {}

    /**
     * @throws ImportException quando la sorgente è irraggiungibile o illeggibile
     */
    public function run(ImportSource $source): ImportReport
    {
        $report = new ImportReport;

        try {
            $driver = $this->drivers->for($source);
            $raw = $driver->fetch($source);
        } catch (ImportException $exception) {
            $this->record($source, ImportRunStatus::Failed, $exception->getMessage(), $report);

            throw $exception;
        }

        $candidates = $this->candidates($driver, $raw, $source, $report);
        $category = $this->categoryId($source);

        if ($category === null) {
            $failure = ImportException::noCategory();
            $this->record($source, ImportRunStatus::Failed, $failure->getMessage(), $report);

            throw $failure;
        }

        foreach ($candidates as $ref => $dto) {
            $this->persist($source, $dto, $ref, $category, $report);
        }

        if ($report->errorCount() === 0) {
            $this->sweep($source, array_keys($candidates), $report);
        }

        $this->record($source, $report->status(), $report->errorSummary(), $report);

        return $report;
    }

    /**
     * L'anteprima di §14.2: percorre `fetch` e `map`, applica il filtro e **non
     * scrive niente**. Restituisce le prime date che entrerebbero, in ordine di
     * inizio, perché è così che le si legge — e perché un fuso interpretato
     * male si vede a colpo d'occhio solo se le date sono in ordine.
     *
     * @return list<ImportedEventDto>
     *
     * @throws ImportException quando la sorgente è irraggiungibile o illeggibile
     */
    public function preview(ImportSource $source, ?int $limit = null): array
    {
        $limit ??= config()->integer('import.preview_size');
        $driver = $this->drivers->for($source);
        $candidates = $this->candidates($driver, $driver->fetch($source), $source, new ImportReport);

        $dtos = array_values($candidates);

        usort($dtos, static fn (ImportedEventDto $a, ImportedEventDto $b): int => $a->startsAt <=> $b->startsAt);

        return array_slice($dtos, 0, max(0, $limit));
    }

    // ------------------------------------------------------ mappatura e filtro

    /**
     * Le voci del feed ridotte a candidati, indicizzati per `source_ref`.
     *
     * Qui avvengono tre cose: la mappatura (una voce illeggibile diventa un
     * errore nel resoconto e non ferma le altre), il filtro di esclusione di
     * §14.2, e la deduplica **dentro la stessa esecuzione** — un feed che
     * ripete lo stesso `UID` descrive un evento solo, e la prima occorrenza nel
     * file è quella che vale.
     *
     * @param  list<mixed>  $raw
     * @return array<string, ImportedEventDto>
     */
    private function candidates(ImportSourceDriver $driver, array $raw, ImportSource $source, ImportReport $report): array
    {
        $mapping = ImportMapping::fromSource($source);
        $candidates = [];

        foreach ($raw as $item) {
            $dto = $driver->map($item, $source);

            if ($dto === null) {
                $report->addError(__('import.errors.unmappable'));

                continue;
            }

            if ($mapping->excludes($dto->title)) {
                $report->addExcluded();

                continue;
            }

            $ref = SourceRef::make($source, $dto->key());

            if (! isset($candidates[$ref])) {
                $candidates[$ref] = $dto;
            }
        }

        return $candidates;
    }

    // -------------------------------------------------------------- scrittura

    private function persist(ImportSource $source, ImportedEventDto $dto, string $ref, int $category, ImportReport $report): void
    {
        $existing = Event::withTrashed()
            ->where('city_id', $source->city_id)
            ->where('source', $source->type->eventSource())
            ->where('source_ref', $ref)
            ->first();

        /*
         * Un evento cestinato dalla redazione resta cestinato. Ripescarlo a
         * ogni esecuzione oraria significherebbe che il pulsante "elimina" non
         * elimina niente, e chi lo ha premuto non capirebbe perché.
         */
        if ($existing !== null && $existing->trashed()) {
            $report->addUnchanged();

            return;
        }

        DB::transaction(function () use ($source, $dto, $ref, $category, $report, $existing): void {
            if ($existing === null) {
                $this->create($source, $dto, $ref, $category, $report);
                $report->addCreated();

                return;
            }

            $changed = $this->syncEvent($existing, $dto, $source);
            $changed = $this->syncDates($existing, $dto, $report) || $changed;

            $changed ? $report->addUpdated() : $report->addUnchanged();
        });
    }

    /**
     * Un evento importato nasce **pubblicato** e **non verificato** (D32):
     * pubblicato perché è la decisione del committente, non verificato perché
     * §14.1 impone che il sito e l'API sappiano distinguerlo da un evento
     * confermato dal locale.
     */
    private function create(ImportSource $source, ImportedEventDto $dto, string $ref, int $category, ImportReport $report): void
    {
        $event = new Event([
            'city_id' => $source->city_id,
            'venue_id' => $source->venue_id,
            'category_id' => $category,
            'title' => $dto->title,
            'description' => $dto->description,
            'custom_location' => $this->customLocation($dto, $source),
            'external_links' => $this->externalLinks($dto),
            'source' => $source->type->eventSource(),
            'source_ref' => $ref,
            'verification_status' => VerificationStatus::Unverified,
            'status' => EventStatus::Published,
            'published_at' => Carbon::now(),
        ]);

        $event->save();

        $this->syncDates($event, $dto, $report);
    }

    /**
     * I soli campi che il calendario dichiara davvero. Tutto il resto —
     * categoria, prezzo, locandina, stato editoriale — appartiene a chi cura
     * l'evento da questa parte, e riscriverlo a ogni ora lo cancellerebbe.
     */
    private function syncEvent(Event $event, ImportedEventDto $dto, ImportSource $source): bool
    {
        $event->fill([
            'title' => $dto->title,
            'description' => $dto->description,
            'custom_location' => $this->customLocation($dto, $source),
            'external_links' => $this->externalLinks($dto),
        ]);

        if (! $event->isDirty()) {
            return false;
        }

        $event->save();

        return true;
    }

    /**
     * Allinea le date. L'occorrenza di riferimento è la più antica dell'evento:
     * è quella nata dal `DTSTART`, ed è quella da cui `GenerateOccurrencesAction`
     * eredita durata e orario di porte.
     */
    private function syncDates(Event $event, ImportedEventDto $dto, ImportReport $report): bool
    {
        $anchor = $event->occurrences()->orderBy('starts_at')->orderBy('id')->first();
        $isNew = $anchor === null;

        $anchor ??= new EventOccurrence(['event_id' => $event->getKey()]);
        $anchor->setRelation('event', $event);

        $anchor->fill([
            'starts_at' => Carbon::instance($dto->startsAt),
            'ends_at' => $dto->endsAt === null ? null : Carbon::instance($dto->endsAt),
            'is_all_day' => $dto->isAllDay,
        ]);

        /*
         * `STATUS:CANCELLED` a monte annulla la data. Il contrario non vale: un
         * evento tolto qui dalla redazione, o annullato da una precedente
         * sparizione, non torna in piedi da solo — riaccenderlo sarebbe una
         * decisione, e l'import non ne prende.
         */
        if ($dto->isCancelled) {
            $anchor->status = OccurrenceStatus::Cancelled;
        } elseif ($isNew) {
            $anchor->status = OccurrenceStatus::Scheduled;
        }

        $changed = $anchor->isDirty();
        $existing = $event->recurrences()->orderBy('id')->first();
        $recurrence = $dto->isRecurring() ? $this->syncRecurrence($event, $dto, $existing) : null;

        if ($recurrence !== null && $anchor->recurrence_id !== $recurrence->getKey()) {
            $anchor->recurrence_id = $recurrence->getKey();
            $changed = true;
        }

        if ($changed) {
            $anchor->save();
        }

        if ($recurrence !== null) {
            ($this->generate)($recurrence);

            return $this->cancelDropped($recurrence, $report) || $changed;
        }

        /*
         * La serie è diventata una data sola: il feed non porta più alcuna
         * `RRULE`. Le date che la regola aveva materializzato non sono più
         * previste da nessuno, e vanno trattate come qualunque altra data
         * sparita — marcate, non rimosse.
         */
        if ($existing !== null) {
            return $this->cancelSeries($existing, $anchor, $report) || $changed;
        }

        return $changed;
    }

    /**
     * La regola RFC 5545 del feed, scritta così com'è in `event_recurrences`.
     * Non viene espansa qui: `GenerateOccurrencesAction` esiste, è idempotente
     * e sa già farlo (D20, D22).
     */
    private function syncRecurrence(Event $event, ImportedEventDto $dto, ?EventRecurrence $recurrence): EventRecurrence
    {
        $recurrence ??= new EventRecurrence(['event_id' => $event->getKey()]);

        $recurrence->fill([
            'rrule' => (string) $dto->rrule,
            'until' => $dto->until === null ? null : Carbon::instance($dto->until),
            'exdates' => $dto->exdates === [] ? null : $dto->exdates,
        ]);

        if ($recurrence->isDirty() || ! $recurrence->exists) {
            $recurrence->save();
        }

        return $recurrence;
    }

    /**
     * Le date future che la regola **non produce più**: una `RRULE` riscritta a
     * monte, o una `EXDATE` aggiunta. Vengono annullate, mai cancellate, come
     * ogni sparizione (§14.2).
     *
     * L'elenco di ciò che la regola produce lo dà `expectedStarts()` della
     * stessa azione che le materializza: due espansori RFC 5545 nel progetto
     * divergerebbero, ed è esattamente il genere di divergenza che nessuno
     * nota finché non manca una serata.
     *
     * Le date con `is_exception = true` restano fuori: sono quelle che qualcuno
     * ha spostato o annullato a mano (D21), e la decisione di una persona vale
     * più della regola del feed.
     */
    private function cancelDropped(EventRecurrence $recurrence, ImportReport $report): bool
    {
        $expected = $this->generate->expectedStarts($recurrence);

        return $this->cancel(
            EventOccurrence::query()->where('recurrence_id', $recurrence->getKey())->where('is_exception', false),
            $report,
            static fn (EventOccurrence $occurrence): bool => ! isset(
                $expected[$occurrence->starts_at->utc()->format('Y-m-d H:i:s')],
            ),
        );
    }

    /**
     * La serie che il feed ha smesso di dichiarare: tutte le date generate
     * dalla regola tranne quella di riferimento, che resta ed è l'unica che il
     * calendario descrive ancora.
     */
    private function cancelSeries(EventRecurrence $recurrence, EventOccurrence $anchor, ImportReport $report): bool
    {
        return $this->cancel(
            EventOccurrence::query()
                ->where('recurrence_id', $recurrence->getKey())
                ->where('is_exception', false)
                ->whereKeyNot($anchor->getKey()),
            $report,
            static fn (EventOccurrence $occurrence): bool => true,
        );
    }

    /**
     * Annulla le date **future** che il filtro seleziona. Il passato non si
     * tocca: una serata già avvenuta non diventa annullata perché il
     * calendario non la elenca più.
     *
     * @param  Builder<EventOccurrence>  $query
     * @param  callable(EventOccurrence): bool  $shouldCancel
     */
    private function cancel(Builder $query, ImportReport $report, callable $shouldCancel): bool
    {
        $cancelled = 0;

        $occurrences = $query
            ->where('starts_at', '>=', CarbonImmutable::now('UTC'))
            ->where('status', '!=', OccurrenceStatus::Cancelled->value)
            ->orderBy('starts_at')
            ->get();

        foreach ($occurrences as $occurrence) {
            if (! $shouldCancel($occurrence)) {
                continue;
            }

            $occurrence->status = OccurrenceStatus::Cancelled;
            $occurrence->save();
            $cancelled++;
        }

        if ($cancelled > 0) {
            $report->addCancelled($cancelled);
        }

        return $cancelled > 0;
    }

    // ------------------------------------------------------------ le sparizioni

    /**
     * Le voci che questa sorgente aveva scritto e che il feed non porta più.
     *
     * Le date future diventano `cancelled` e finiscono nel resoconto. Niente
     * viene rimosso: una rete che sbaglia, un export interrotto a metà o un
     * filtro cambiato a monte distruggerebbero altrimenti dati veri, e nessuno
     * saprebbe che cosa c'era prima.
     *
     * @param  list<string>  $seen
     */
    private function sweep(ImportSource $source, array $seen, ImportReport $report): void
    {
        $query = Event::query()
            ->where('city_id', $source->city_id)
            ->where('source', $source->type->eventSource())
            ->where('source_ref', 'like', SourceRef::likePattern($source));

        if ($seen !== []) {
            $query->whereNotIn('source_ref', $seen);
        }

        foreach ($query->orderBy('id')->cursor() as $event) {
            $this->cancel(
                EventOccurrence::query()->where('event_id', $event->getKey()),
                $report,
                static fn (EventOccurrence $occurrence): bool => true,
            );
        }
    }

    // ------------------------------------------------------------- il contorno

    /**
     * La categoria con cui nascono gli eventi di questa sorgente: quella
     * dichiarata, altrimenti l'abituale del locale collegato, altrimenti la
     * prima del catalogo. `events.category_id` non ammette il vuoto, e un
     * import senza catalogo è una configurazione incompleta, non un evento
     * senza categoria.
     */
    private function categoryId(ImportSource $source): ?int
    {
        if ($source->default_category_id !== null) {
            return (int) $source->default_category_id;
        }

        $venue = $source->venue;

        if ($venue !== null) {
            $defaults = VenueEventDefaults::formDefaults($venue);
            $category = $defaults['category_id'] ?? null;

            if (is_int($category) || is_numeric($category)) {
                return (int) $category;
            }
        }

        $first = Category::query()->orderBy('id')->value('id');

        return is_numeric($first) ? (int) $first : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function customLocation(ImportedEventDto $dto, ImportSource $source): ?array
    {
        if ($source->venue_id !== null || $dto->location === null) {
            return null;
        }

        return ['name' => $dto->location];
    }

    /**
     * @return array<string, string>|null
     */
    /**
     * L'indirizzo da cui l'evento è stato importato, nella forma che
     * `events.external_links` ha per contratto: coppie etichetta/indirizzo
     * (`App\DTOs\ExternalLink`). Una mappa `{"source": "…"}` non arriverebbe
     * al modello — il cast la scarterebbe — e comunque «source» non è una
     * parola che si mostra a chi legge la scheda.
     *
     * @return list<array{label: string, url: string}>|null
     */
    private function externalLinks(ImportedEventDto $dto): ?array
    {
        return $dto->url === null
            ? null
            : [['label' => __('events.external_links.source'), 'url' => $dto->url]];
    }

    /**
     * L'esito, scritto in due posti che non dicono la stessa cosa: sulla
     * sorgente («come è andata l'ultima volta», che è ciò che leggono l'elenco
     * e la dashboard di §14.5) e nello storico («che cosa è successo finora»,
     * che è ciò che serve a capire da quando una sorgente non porta più
     * niente).
     *
     * Viene chiamato **prima** che un guasto venga rilanciato: se aspettasse
     * la fine del lavoro di coda, un calendario irraggiungibile non lascerebbe
     * alcuna traccia sulla sorgente e la pagina resterebbe muta.
     */
    private function record(ImportSource $source, ImportRunStatus $status, ?string $error, ImportReport $report): void
    {
        $source->forceFill([
            'last_run_at' => Carbon::now(),
            'last_status' => $status->value,
            'last_error' => $error,
        ])->save();

        ImportRun::fromReport($source, $status, $report, $error)->save();

        $this->prune($source);
    }

    /**
     * Lo storico si tiene corto. Un'esecuzione oraria produce ottomila righe
     * l'anno per sorgente, e nessuna delle domande a cui lo storico risponde
     * — da quando è ferma, che cosa ha portato ieri — ha bisogno di più delle
     * ultime.
     */
    private function prune(ImportSource $source): void
    {
        $keep = config()->integer('import.history_size');

        $oldest = ImportRun::query()
            ->where('import_source_id', $source->getKey())
            ->orderByDesc('id')
            ->skip($keep - 1)
            ->take(1)
            ->value('id');

        if ($oldest === null) {
            return;
        }

        ImportRun::query()
            ->where('import_source_id', $source->getKey())
            ->where('id', '<', $oldest)
            ->delete();
    }
}
