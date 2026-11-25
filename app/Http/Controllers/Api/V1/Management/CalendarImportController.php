<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Management;

use App\DTOs\ImportedEventDto;
use App\Enums\ApiErrorCode;
use App\Enums\ImportRunStatus;
use App\Exceptions\ApiException;
use App\Exceptions\ImportException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Management\CalendarImportRequest;
use App\Models\ImportSource;
use App\Models\User;
use App\Models\Venue;
use App\Services\Import\VenueCalendarImport;
use App\Support\Api\ApiDate;
use Illuminate\Http\JsonResponse;

/**
 * Il calendario collegato del locale (W-IM-01…07), con le regole della pagina
 * «Calendario collegato» del pannello, che stanno in `VenueCalendarImport`.
 *
 * Nella pagina il pulsante che collega si accende solo dopo l'anteprima; qui
 * l'anteprima restituisce un `preview_token` firmato, e il collegamento senza
 * quel segno (o con il segno di un altro indirizzo, di un altro file, di
 * un'altra persona) risponde 422. Il CSV si manda in multipart nel campo
 * `file`, sia all'anteprima sia al collegamento: l'anteprima non lo tiene,
 * il collegamento lo salva una volta sola nella cartella del locale.
 */
final class CalendarImportController extends Controller
{
    /** Stato del collegamento e valori con cui aprire il modulo. */
    public function show(CalendarImportRequest $request, VenueCalendarImport $import): JsonResponse
    {
        return response()->json(['data' => $this->state($request->venue(), $request->actor(), $import)]);
    }

    /**
     * Anteprima: le prime date che entrerebbero, con il filtro già applicato,
     * senza scrivere niente.
     *
     * Come nella pagina, un calendario che non si legge non è un errore della
     * richiesta: torna in `error`, e il segno dell'anteprima arriva lo stesso.
     */
    public function preview(CalendarImportRequest $request, VenueCalendarImport $import): JsonResponse
    {
        $venue = $request->venue();
        $type = $request->importType();
        $contents = $request->fileContents();
        $rows = [];
        $error = null;

        try {
            $dates = $contents !== null
                ? $import->previewContents($venue, $contents, $request->keywords())
                : $import->preview($venue, $type, $request->sourceUrl(), $request->keywords());
            $rows = array_map(fn (ImportedEventDto $date): array => $this->row($date, $this->timezone($venue)), $dates);
        } catch (ImportException $exception) {
            $error = $exception->getMessage();
        }

        return response()->json(['data' => [
            'rows' => $rows,
            'error' => $error,
            'preview_token' => $import->previewToken($venue, $request->actor(), $type, VenueCalendarImport::previewSubject($request->sourceUrl(), $contents)),
            'expires_in_minutes' => VenueCalendarImport::PREVIEW_TOKEN_MINUTES,
        ]]);
    }

    /**
     * Collega il calendario, o aggiorna il collegamento, e accoda subito la
     * prima lettura. Chiede il `preview_token` dell'anteprima degli stessi
     * tipo e indirizzo (o file). 201 la prima volta, 200 dopo.
     */
    public function store(CalendarImportRequest $request, VenueCalendarImport $import): JsonResponse
    {
        $venue = $request->venue();
        $type = $request->importType();
        $contents = $request->fileContents();
        $subject = VenueCalendarImport::previewSubject($request->sourceUrl(), $contents);

        if (! $import->previewTokenMatches((string) $request->validated('preview_token'), $venue, $request->actor(), $type, $subject)) {
            $message = __('manage.calendar.notifications.preview_first');

            throw new ApiException(ApiErrorCode::ValidationFailed, $message, ['preview_token' => [$message]]);
        }

        $existed = $import->source($venue) !== null;
        $url = $contents !== null ? $import->storeContents($venue, $contents) : $request->sourceUrl();
        $import->connect($venue, $type, $url, $request->keywords());

        return response()->json([
            'data' => $this->state($venue, $request->actor(), $import),
            'message' => __('manage.calendar.notifications.connected').' '.__('manage.calendar.notifications.connected_body'),
        ], $existed ? 200 : 201);
    }

    /**
     * «Leggi adesso»: una lettura subito, con il riepilogo dell'esito. Una
     * lettura che non riesce non è un errore della richiesta: `outcome` è
     * `failed`, il motivo in `message` e nello stato aggiornato.
     */
    public function run(CalendarImportRequest $request, VenueCalendarImport $import): JsonResponse
    {
        $source = $request->source();

        try {
            $report = $import->run($source);
            $outcome = $report->status();
            $message = __('import.report.summary', $report->counters());
            $counters = $report->counters();
        } catch (ImportException $exception) {
            $outcome = ImportRunStatus::Failed;
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : __('manage.calendar.notifications.run_failed');
            $counters = null;
        }

        return response()->json(['data' => [
            'outcome' => $outcome->value,
            'outcome_label' => $outcome->label(),
            'title' => __('manage.calendar.notifications.run_done'),
            'message' => $message,
            'counters' => $counters,
            'state' => $this->state($request->venue(), $request->actor(), $import),
        ]]);
    }

    /** Sospende: la sorgente resta collegata, ma la lettura di ogni ora la salta. */
    public function pause(CalendarImportRequest $request, VenueCalendarImport $import): JsonResponse
    {
        $import->setActive($request->source(), false);

        return response()->json([
            'data' => $this->state($request->venue(), $request->actor(), $import),
            'message' => __('manage.calendar.notifications.suspended'),
        ]);
    }

    public function resume(CalendarImportRequest $request, VenueCalendarImport $import): JsonResponse
    {
        $import->setActive($request->source(), true);

        return response()->json([
            'data' => $this->state($request->venue(), $request->actor(), $import),
            'message' => __('manage.calendar.notifications.resumed'),
        ]);
    }

    /** Scollega: le date già pubblicate restano; il file caricato, se c'era, si cancella. */
    public function destroy(CalendarImportRequest $request, VenueCalendarImport $import): JsonResponse
    {
        $import->disconnect($request->source());

        return response()->json([
            'data' => $this->state($request->venue(), $request->actor(), $import),
            'message' => __('manage.calendar.notifications.disconnected'),
        ]);
    }

    /** @return array<string, mixed> */
    private function state(Venue $venue, User $user, VenueCalendarImport $import): array
    {
        $source = $import->source($venue);

        return [
            'source' => $source === null ? null : $this->source($source, $user, $this->timezone($venue)),
            'form' => $import->formDefaults($source),
            'default_exclude_keywords' => array_values(array_map('strval', (array) config('import.exclude_keywords'))),
            'limits' => [
                'max_keywords' => VenueCalendarImport::MAX_KEYWORDS,
                'max_keyword_length' => VenueCalendarImport::MAX_KEYWORD_LENGTH,
                'max_url_length' => VenueCalendarImport::MAX_URL_LENGTH,
                'max_file_bytes' => config()->integer('import.max_bytes'),
                'preview_size' => config()->integer('import.preview_size'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function source(ImportSource $source, User $user, string $timezone): array
    {
        $status = $source->last_status !== null ? ImportRunStatus::tryFrom((string) $source->last_status) : null;
        $upload = VenueCalendarImport::isUpload($source);

        return [
            'id' => (int) $source->getKey(),
            'type' => $source->type->value,
            'type_label' => $source->type->label(),
            /* Come la pagina: l'indirizzo così com'è; per un file caricato `url` è nullo e `is_upload` vero. */
            'address' => (string) $source->url,
            'url' => $upload ? null : (string) $source->url,
            'is_upload' => $upload,
            'is_active' => (bool) $source->is_active,
            'last_run_at' => ApiDate::instant($source->last_run_at, $timezone),
            'last_status' => $source->last_status,
            'last_status_label' => $status?->label() ?? $source->last_status,
            'last_error' => $source->last_error,
            'can_run' => $user->can('run', $source),
            'can_update' => $user->can('update', $source),
            'can_delete' => $user->can('delete', $source),
        ];
    }

    /** @return array<string, mixed> */
    private function row(ImportedEventDto $date, string $timezone): array
    {
        $starts = $date->startsAt->setTimezone($timezone);
        $notes = [];

        if ($date->isAllDay) {
            $notes[] = __('import.preview.all_day');
        }

        if ($date->isRecurring()) {
            $notes[] = __('import.preview.recurring');
        }

        return [
            'title' => $date->title,
            'starts_at' => ApiDate::instant($date->startsAt, $timezone),
            'ends_at' => ApiDate::instant($date->endsAt, $timezone),
            /* Lo stesso testo della tabella della pagina, nell'ora della città. */
            'when' => $date->isAllDay ? $starts->format('d/m/Y') : $starts->format('d/m/Y H:i'),
            'where' => $date->location,
            'all_day' => $date->isAllDay,
            'recurring' => $date->isRecurring(),
            'cancelled' => $date->isCancelled,
            'notes' => $notes,
        ];
    }

    private function timezone(Venue $venue): string
    {
        return (string) ($venue->city->timezone ?? config('app.timezone'));
    }
}
