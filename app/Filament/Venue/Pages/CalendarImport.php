<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Enums\ImportRunStatus;
use App\Enums\ImportSourceType;
use App\Filament\Support\ImportPreviewRows;
use App\Filament\Venue\Support\CurrentVenue;
use App\Jobs\Import\ImportSourceJob;
use App\Models\ImportSource;
use App\Services\Import\ImportRunner;
use App\Services\Import\ImportUrlGuard;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Throwable;

/**
 * §14.2 — «il locale fornisce l'URL pubblico del proprio calendario».
 *
 * Chi gestisce un locale sa dov'è il proprio calendario meglio di chiunque in
 * redazione, e ha già l'abitudine di tenerlo aggiornato: collegarlo costa un
 * incolla e vale decine di inserimenti a mano risparmiati.
 *
 * **L'anteprima non è un ornamento, è il contrappeso.** Gli eventi importati
 * vengono pubblicati direttamente (D32), quindi l'unico momento in cui una
 * persona guarda cosa sta entrando è *prima* di accendere la sorgente. Per
 * questo il pulsante che attiva compare solo dopo che l'anteprima è stata
 * mostrata: non si accende alla cieca un canale che scrive sul sito pubblico.
 *
 * Insieme al filtro delle parole escluse è ciò che tiene fuori le voci interne
 * — «riunione staff», «chiuso per ferie» — che in un calendario di lavoro
 * convivono con i concerti.
 */
class CalendarImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'calendario';

    protected string $view = 'filament.venue.pages.calendar-import';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Righe dell'anteprima, già formattate nel fuso della città.
     *
     * @var list<array{when: string, title: string, where: string|null, notes: list<string>, cancelled: bool}>
     */
    public array $previewRows = [];

    public ?string $previewError = null;

    public bool $previewed = false;

    public static function getNavigationLabel(): string
    {
        return __('manage.calendar.title');
    }

    public function getTitle(): string
    {
        return __('manage.calendar.title');
    }

    public function getSubheading(): ?string
    {
        return __('manage.calendar.subheading');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('create', [ImportSource::class, CurrentVenue::get()]) ?? false;
    }

    public function mount(): void
    {
        $source = $this->source();

        $this->getForm('form')?->fill([
            'url' => $source?->url,
            'exclude_keywords' => $source !== null
                ? $source->mapping['exclude_keywords'] ?? config('import.exclude_keywords')
                : config('import.exclude_keywords'),
        ]);
    }

    /**
     * La sorgente del locale corrente, se esiste.
     *
     * Un locale ha un calendario solo: chi ne ha due tenga il secondo in
     * redazione, dove l'elenco completo è già previsto. Qui semplicità batte
     * generalità — la pagina si usa in piedi, col telefono in mano.
     */
    public function source(): ?ImportSource
    {
        return ImportSource::query()
            ->where('venue_id', CurrentVenue::get()->getKey())
            ->where('type', ImportSourceType::Ics->value)
            ->latest('id')
            ->first();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('manage.calendar.form.heading'))
                    ->description(__('manage.calendar.form.help'))
                    ->schema([
                        TextInput::make('url')
                            ->label(__('manage.calendar.form.url'))
                            ->helperText(__('manage.calendar.form.url_help'))
                            ->url()
                            ->required()
                            ->maxLength(1000)
                            ->live(debounce: 600)
                            ->afterStateUpdated(function (): void {
                                // Cambiato l'indirizzo, l'anteprima di prima non
                                // descrive più ciò che si sta per accendere.
                                $this->previewed = false;
                                $this->previewRows = [];
                                $this->previewError = null;
                            })
                            ->rule(fn (): callable => function (string $attribute, mixed $value, callable $fail): void {
                                if (! is_string($value) || $value === '') {
                                    return;
                                }

                                $reason = app(ImportUrlGuard::class)->reject($value);

                                if ($reason !== null) {
                                    $fail($reason);
                                }
                            }),

                        TagsInput::make('exclude_keywords')
                            ->label(__('manage.calendar.form.exclude'))
                            ->helperText(__('manage.calendar.form.exclude_help'))
                            ->placeholder(__('manage.calendar.form.exclude_placeholder')),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Guarda cosa entrerebbe, senza scrivere niente.
     */
    public function preview(): void
    {
        $data = $this->getForm('form')?->getState() ?? [];

        $source = $this->draftSource($data);

        $this->previewRows = [];
        $this->previewError = null;

        try {
            $dates = app(ImportRunner::class)->preview($source);

            $this->previewRows = ImportPreviewRows::make($dates, CurrentVenue::timezone());
        } catch (Throwable $exception) {
            $this->previewError = $exception->getMessage();
        }

        $this->previewed = true;
    }

    /**
     * Accende la sorgente, o ne aggiorna una già esistente.
     */
    public function connect(): void
    {
        $data = $this->getForm('form')?->getState() ?? [];

        if (! $this->previewed) {
            Notification::make()
                ->title(__('manage.calendar.notifications.preview_first'))
                ->warning()
                ->send();

            return;
        }

        $venue = CurrentVenue::get();

        $source = $this->source() ?? new ImportSource;

        $source->fill([
            'city_id' => $venue->city_id,
            'venue_id' => $venue->getKey(),
            'type' => ImportSourceType::Ics,
            'url' => $data['url'],
            'mapping' => ['exclude_keywords' => array_values($data['exclude_keywords'] ?? [])],
            'is_active' => true,
        ]);

        $source->save();

        ImportSourceJob::dispatch($source->getKey());

        Notification::make()
            ->title(__('manage.calendar.notifications.connected'))
            ->body(__('manage.calendar.notifications.connected_body'))
            ->success()
            ->send();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('runNow')
                ->label(__('manage.calendar.actions.run_now'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (): bool => $this->source() !== null)
                ->authorize(fn (): bool => $this->source() !== null
                    && auth()->user()?->can('run', $this->source()) === true)
                ->action(function (): void {
                    $source = $this->source();

                    if ($source === null) {
                        return;
                    }

                    $report = app(ImportRunner::class)->run($source);

                    Notification::make()
                        ->title(__('manage.calendar.notifications.run_done'))
                        ->body($report->status() === ImportRunStatus::Failed
                            ? ($report->errorSummary() ?? __('manage.calendar.notifications.run_failed'))
                            : __('import.report.summary', $report->counters()))
                        ->status($report->status() === ImportRunStatus::Failed ? 'danger' : 'success')
                        ->send();
                }),

            Action::make('suspend')
                ->label(fn (): string => $this->source()?->is_active === true
                    ? __('manage.calendar.actions.suspend')
                    : __('manage.calendar.actions.resume'))
                ->icon(Heroicon::OutlinedPause)
                ->color('gray')
                ->visible(fn (): bool => $this->source() !== null)
                ->authorize(fn (): bool => $this->source() !== null
                    && auth()->user()?->can('update', $this->source()) === true)
                ->action(function (): void {
                    $source = $this->source();

                    if ($source === null) {
                        return;
                    }

                    $source->update(['is_active' => ! $source->is_active]);

                    Notification::make()
                        ->title($source->is_active
                            ? __('manage.calendar.notifications.resumed')
                            : __('manage.calendar.notifications.suspended'))
                        ->success()
                        ->send();
                }),

            Action::make('disconnect')
                ->label(__('manage.calendar.actions.disconnect'))
                ->icon(Heroicon::OutlinedLinkSlash)
                ->color('danger')
                ->visible(fn (): bool => $this->source() !== null)
                ->authorize(fn (): bool => $this->source() !== null
                    && auth()->user()?->can('delete', $this->source()) === true)
                ->requiresConfirmation()
                ->modalDescription(__('manage.calendar.actions.disconnect_confirm'))
                ->action(function (): void {
                    $this->source()?->delete();

                    $this->previewed = false;
                    $this->previewRows = [];

                    Notification::make()
                        ->title(__('manage.calendar.notifications.disconnected'))
                        ->success()
                        ->send();
                }),
        ];
    }

    public function render(): View
    {
        return parent::render()->with(['source' => $this->source()]);
    }

    /**
     * Una sorgente non salvata, che serve solo all'anteprima.
     *
     * @param  array<string, mixed>  $data
     */
    private function draftSource(array $data): ImportSource
    {
        $venue = CurrentVenue::get();

        $source = $this->source() ?? new ImportSource;

        $source->fill([
            'city_id' => $venue->city_id,
            'venue_id' => $venue->getKey(),
            'type' => ImportSourceType::Ics,
            'url' => $data['url'],
            'mapping' => ['exclude_keywords' => array_values($data['exclude_keywords'] ?? [])],
        ]);

        $source->setRelation('venue', $venue);

        return $source;
    }
}
