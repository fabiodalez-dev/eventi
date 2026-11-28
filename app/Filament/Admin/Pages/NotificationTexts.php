<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Settings\NotificationTextEditor;
use App\Support\NotificationTextCatalog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * I testi delle email, riscrivibili senza toccare il codice.
 *
 * **Si salvano solo le differenze.** Un testo lasciato come lo si e' trovato
 * non finisce nel database: cosi' «ripristina» significa cancellare una riga,
 * e i testi che nessuno ha mai toccato continuano a seguire il file di lingua
 * anche quando quello cambia. Il contrario — copiare tutto nel database la
 * prima volta — congelerebbe per sempre i testi al giorno del salvataggio.
 *
 * Le chiavi non si vedono e non si scelgono: chi scrive un'email pensa a cosa
 * dire, non a `notifications.reminder.subject`.
 */
class NotificationTexts extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'testi-delle-email';

    protected string $view = 'filament.admin.pages.notification-texts';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Questi testi partono dal nostro indirizzo verso tutti gli iscritti, piede
     * comune compreso: li riscrive l'amministrazione, non il moderatore, che
     * cura i contenuti degli altri.
     */
    public static function canAccess(): bool
    {
        $utente = Auth::user();

        return NotificationTextEditor::canEdit($utente instanceof User ? $utente : null);
    }

    /**
     * Il catalogo per gruppi, senza le chiavi riservate: quelle della
     * newsletter si scrivono dalla pagina Newsletter, che è
     * dell'amministratore di sistema (`NotificationTextEditor::reserved`).
     *
     * @return array<string, array{etichetta: string, testi: array<string, array{predefinito: string, attuale: string, modificato: bool}>}>
     */
    private static function gruppi(): array
    {
        return NotificationTextEditor::groups(self::utente());
    }

    private static function utente(): ?User
    {
        $utente = Auth::user();

        return $utente instanceof User ? $utente : null;
    }

    public static function getNavigationLabel(): string
    {
        return __('notification_texts.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.system');
    }

    public function getTitle(): string
    {
        return __('notification_texts.title');
    }

    public function getSubheading(): ?string
    {
        return __('notification_texts.lead');
    }

    public function mount(): void
    {
        $valori = [];

        foreach (self::gruppi() as $gruppo) {
            foreach ($gruppo['testi'] as $chiave => $testo) {
                $valori[self::campo($chiave)] = $testo['attuale'];
            }
        }

        $this->modulo()->fill($valori);
    }

    public function form(Schema $schema): Schema
    {
        $schede = [];

        foreach (self::gruppi() as $gruppo) {
            $campi = [];

            foreach ($gruppo['testi'] as $chiave => $testo) {
                $variabili = NotificationTextCatalog::variabili($testo['predefinito']);

                $campi[] = Textarea::make(self::campo($chiave))
                    ->label(self::etichetta($chiave))
                    ->rows(self::righe($testo['predefinito']))
                    ->live(onBlur: true)
                    ->aboveContent(array_map(fn (string $variable) => Action::make('insert_'.$variable)
                        ->label(__('notification_texts.insert', ['token' => __('notification_texts.tokens.'.$variable).' (:'.$variable.')']))
                        ->button()->size('xs')->color('gray')
                        ->action(function (Textarea $component) use ($variable): void {
                            $component->state(rtrim((string) $component->getState()).' :'.$variable);
                        }), $variabili))
                    ->helperText(fn (Get $get) => view('filament.admin.pages.notification-text-help', [
                        'original' => $testo['predefinito'],
                        'preview' => strtr((string) $get(self::campo($chiave)), self::samples($chiave)),
                    ]))
                    ->columnSpanFull();
            }

            $schede[] = Tab::make($gruppo['etichetta'])->schema([
                Section::make($gruppo['etichetta'])
                    ->description(__('notification_texts.group_lead'))
                    ->schema($campi),
            ]);
        }

        return $schema->columns(1)
            ->statePath('data')
            ->components([
                Tabs::make('testi')->columnSpanFull()->persistTabInQueryString()->tabs($schede),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('salva')
                ->label(__('notification_texts.actions.save'))
                ->action('salva'),

            Action::make('ripristina')
                ->label(__('notification_texts.actions.reset_all'))
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('notification_texts.actions.reset_all_confirm'))
                ->action('ripristinaTutto'),
        ];
    }

    public function salva(): void
    {
        abort_unless(static::canAccess(), 403);
        /** @var array<string, string> $valori */
        $valori = $this->modulo()->getState();
        $testi = [];
        foreach (array_keys(NotificationTextCatalog::predefiniti()) as $key) {
            $testi[$key] = (string) ($valori[self::campo($key)] ?? '');
        }
        $errors = [];
        foreach (NotificationTextEditor::unknownVariables(self::utente(), $testi) as $key => $unknown) {
            $errors['data.'.self::campo($key)] = NotificationTextEditor::invalidMessage($unknown);
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $utente = self::utente();
        abort_if($utente === null, 403);
        $esito = NotificationTextEditor::save($utente, $testi);

        Notification::make()
            ->title(__('notification_texts.saved', ['scritti' => $esito['scritti'], 'ripristinati' => $esito['ripristinati']]))
            ->success()
            ->send();
    }

    public function ripristinaTutto(): void
    {
        abort_unless(static::canAccess(), 403);
        NotificationTextEditor::resetAll(self::utente());
        $this->mount();

        Notification::make()->title(__('notification_texts.reset_done'))->success()->send();
    }

    /**
     * Lo schema di questa pagina.
     *
     * `$this->form` funziona a runtime per una scorciatoia magica, ma non e'
     * dichiarato da nessuna parte: l'analisi statica non sa che esista, e ha
     * ragione a dirlo. Si chiede per nome, come nel resto del progetto.
     */
    private function modulo(): Schema
    {
        $modulo = $this->getSchema('form');

        abort_if($modulo === null, 500);

        return $modulo;
    }

    /** Il nome del campo nel modulo: i punti confonderebbero Livewire. */
    private static function campo(string $chiave): string
    {
        return str_replace('.', '__', $chiave);
    }

    /** @return array<string, string> */
    private static function samples(string $key): array
    {
        return NotificationTextEditor::samples($key);
    }

    private static function etichetta(string $chiave): string
    {
        return NotificationTextEditor::label($chiave);
    }

    /** Righe proporzionate al testo: un oggetto non ha bisogno di sei righe. */
    private static function righe(string $predefinito): int
    {
        return NotificationTextEditor::rows($predefinito);
    }
}
