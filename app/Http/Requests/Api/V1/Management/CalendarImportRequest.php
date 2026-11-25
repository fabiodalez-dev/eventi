<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Management;

use App\Enums\ImportSourceType;
use App\Models\ImportSource;
use App\Models\User;
use App\Models\Venue;
use App\Services\Import\ImportUrlGuard;
use App\Services\Import\VenueCalendarImport;
use App\Support\Management\ManagementSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Il calendario collegato del locale (W-IM-01…07). Lo apre chi apre la pagina
 * «Calendario collegato» del pannello: `ImportSourcePolicy::create` sul
 * locale, cioè il referente, mai il collaboratore. Leggere, sospendere e
 * scollegare chiedono in più `run`, `update` e `delete` sulla sorgente, come
 * le azioni della pagina.
 *
 * Il modulo è quello della pagina: tipo (ICS o CSV), indirizzo oppure file
 * CSV, parole che tengono fuori una data. Il file arriva in multipart nel
 * campo `file`; con il tipo ICS si ignora, come nella pagina.
 */
final class CalendarImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('create', [ImportSource::class, $this->venue()]);

        $ability = match ($this->action()) {
            'run' => 'run',
            'pause', 'resume' => 'update',
            'destroy' => 'delete',
            default => null,
        };

        if ($ability !== null) {
            Gate::authorize($ability, $this->source());
        }

        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        if (! in_array($this->action(), ['preview', 'store'], true)) {
            return [];
        }

        $rules = [
            'type' => ['required', 'string', Rule::in(array_map(static fn (ImportSourceType $type): string => $type->value, VenueCalendarImport::types()))],
            'url' => [
                'nullable', 'string', 'url', 'max:'.VenueCalendarImport::MAX_URL_LENGTH,
                'required_if:type,'.ImportSourceType::Ics->value, 'required_without:file',
                static function (string $attribute, mixed $value, callable $fail): void {
                    if (! is_string($value) || $value === '') {
                        return;
                    }

                    $reason = app(ImportUrlGuard::class)->reject($value);

                    if ($reason !== null) {
                        $fail($reason);
                    }
                },
            ],
            'file' => [
                'exclude_unless:type,'.ImportSourceType::Csv->value, 'nullable', 'file',
                'mimetypes:text/csv,text/plain,application/vnd.ms-excel,application/csv',
                'max:'.(int) (config()->integer('import.max_bytes') / 1024),
            ],
            'exclude_keywords' => ['nullable', 'array', 'max:'.VenueCalendarImport::MAX_KEYWORDS],
            'exclude_keywords.*' => ['string', 'max:'.VenueCalendarImport::MAX_KEYWORD_LENGTH],
        ];

        if ($this->action() === 'store') {
            $rules['preview_token'] = ['required', 'string', 'max:4000'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.required_without' => __('manage.calendar.form.url_or_file'),
            'url.required_if' => __('manage.calendar.form.url_or_file'),
            'preview_token.required' => __('manage.calendar.notifications.preview_first'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'type' => __('manage.calendar.form.type'),
            'url' => __('manage.calendar.form.url'),
            'file' => __('manage.calendar.form.file'),
            'exclude_keywords' => __('manage.calendar.form.exclude'),
            'exclude_keywords.*' => __('manage.calendar.form.exclude'),
        ];
    }

    public function venue(): Venue
    {
        $venue = ManagementSubject::require()->venue();
        abort_unless($venue instanceof Venue, 404);

        return $venue;
    }

    public function actor(): User
    {
        $user = $this->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** La sorgente del locale: 404 se non c'è niente da leggere, sospendere o scollegare. */
    public function source(): ImportSource
    {
        $source = app(VenueCalendarImport::class)->source($this->venue());
        abort_unless($source instanceof ImportSource, 404);

        return $source;
    }

    public function importType(): ImportSourceType
    {
        return ImportSourceType::tryFrom((string) $this->validated('type')) ?? ImportSourceType::Ics;
    }

    /** L'indirizzo scritto (vuoto se c'è solo il file). */
    public function sourceUrl(): string
    {
        return trim((string) $this->validated('url', ''));
    }

    /**
     * Il contenuto del CSV caricato, se il tipo è CSV e il file c'è: come
     * nella pagina, con il file l'indirizzo non conta.
     */
    public function fileContents(): ?string
    {
        if ($this->importType() !== ImportSourceType::Csv) {
            return null;
        }

        $file = $this->file('file');

        if (! $file instanceof UploadedFile) {
            return null;
        }

        $contents = $file->get();

        return is_string($contents) ? $contents : null;
    }

    /** @return list<string> */
    public function keywords(): array
    {
        return array_values(array_map('strval', (array) $this->validated('exclude_keywords', [])));
    }

    /** L'ultima parte del nome della rotta: `show`, `preview`, `store`, `run`, `pause`, `resume`, `destroy`. */
    private function action(): string
    {
        $name = (string) $this->route()?->getName();

        return substr($name, (int) strrpos($name, '.') + 1);
    }
}
