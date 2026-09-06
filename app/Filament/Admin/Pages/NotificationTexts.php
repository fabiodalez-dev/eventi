<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\NotificationText;
use App\Models\User;
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

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'testi-delle-email';

    protected string $view = 'filament.admin.pages.notification-texts';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $utente = Auth::user();

        return $utente instanceof User && $utente->isEditorialStaff();
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

        foreach (NotificationTextCatalog::perGruppo() as $gruppo) {
            foreach ($gruppo['testi'] as $chiave => $testo) {
                $valori[self::campo($chiave)] = $testo['attuale'];
            }
        }

        $this->modulo()->fill($valori);
    }

    public function form(Schema $schema): Schema
    {
        $schede = [];

        foreach (NotificationTextCatalog::perGruppo() as $nome => $gruppo) {
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
        $errors = [];
        foreach (NotificationTextCatalog::predefiniti() as $key => $default) {
            $unknown = array_diff(NotificationTextCatalog::variabili((string) ($valori[self::campo($key)] ?? '')), NotificationTextCatalog::variabili($default));
            if ($unknown !== []) {
                $errors['data.'.self::campo($key)] = __('notification_texts.invalid', ['tokens' => implode(', ', $unknown)]);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $utente = Auth::id();
        $scritti = 0;
        $ripristinati = 0;

        foreach (NotificationTextCatalog::perGruppo() as $gruppo) {
            foreach ($gruppo['testi'] as $chiave => $testo) {
                $nuovo = trim((string) ($valori[self::campo($chiave)] ?? ''));

                /*
                 * Un campo svuotato non salva la stringa vuota: torna
                 * all'originale. Un'email senza oggetto non e' una scelta che
                 * qualcuno faccia apposta, ed e' un guasto che si scopre solo
                 * quando e' partita.
                 */
                if ($nuovo === '' || $nuovo === $testo['predefinito']) {
                    $ripristinati += NotificationText::query()->where('key', $chiave)->delete();

                    continue;
                }

                NotificationText::updateOrCreate(
                    ['key' => $chiave],
                    ['value' => $nuovo, 'updated_by' => $utente],
                );
                $scritti++;
            }
        }

        NotificationText::dimenticaLaCache();

        Notification::make()
            ->title(__('notification_texts.saved', ['scritti' => $scritti, 'ripristinati' => $ripristinati]))
            ->success()
            ->send();
    }

    public function ripristinaTutto(): void
    {
        abort_unless(static::canAccess(), 403);
        NotificationText::query()->delete();
        NotificationText::dimenticaLaCache();
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
        $samples = [];
        foreach (NotificationTextCatalog::predefiniti() as $text) {
            foreach (NotificationTextCatalog::variabili($text) as $variable) {
                $samples[':'.$variable] = __('notification_texts.examples.'.$variable) === 'notification_texts.examples.'.$variable
                    ? '['.$variable.']' : __('notification_texts.examples.'.$variable);
            }
        }

        if ($key === 'notifications.moved.previous') {
            $samples[':when'] = __('notification_texts.previous_example');
        }

        return $samples;
    }

    /**
     * Che pezzo dell'email e' questo testo.
     *
     * Non l'ultima parte della chiave cosi' com'e': «Subject», «When hours»,
     * «Line» sono nomi da programmatore in una pagina che chiede di scrivere
     * in italiano. Cio' che non e' in elenco mantiene la chiave, perche' un
     * nome sbagliato inganna piu' di uno tecnico.
     *
     * @var array<string, string>
     */
    private const PEZZI = [
        'subject' => 'Oggetto',
        'heading' => 'Titolo dentro il messaggio',
        'line' => 'Testo',
        'lines' => 'Testo',
        'why' => 'Perché ricevi questa email',
        'note' => 'Nota',
        'intro' => 'Apertura',
        'outro' => 'Chiusura',
        'empty' => 'Quando non c\'è niente da segnalare',
        'footer' => 'Piede',
        'greeting' => 'Saluto',
        'body' => 'Corpo',
        'cta' => 'Pulsante',
        'title' => 'Titolo',

        // etichette dei pulsanti
        'open_event' => 'Pulsante: apri l\'evento',
        'open_feed' => 'Pulsante: apri il feed',
        'open_tonight' => 'Pulsante: cosa c\'è stasera',
        'open_weekend' => 'Pulsante: il fine settimana',
        'open_panel' => 'Pulsante: apri il pannello',
        'open_venue' => 'Pulsante: apri il locale',

        // frammenti
        'at_venue' => 'Dove si svolge',
        'when_tomorrow' => 'Quando: domani',
        'when_hours' => 'Quando: fra N ore',
        'previous' => 'Com\'era prima',
        'reason' => 'Motivo',
        'mandatory' => 'Avviso: email di servizio',
        'link_fallback' => 'Se il pulsante non funziona',
        'unsubscribe' => 'Disiscrizione',
        'preferences' => 'Preferenze',
    ];

    private static function etichetta(string $chiave): string
    {
        $ultima = (string) last(explode('.', $chiave));

        return self::PEZZI[$ultima] ?? ucfirst(str_replace('_', ' ', $ultima));
    }

    /** Righe proporzionate al testo: un oggetto non ha bisogno di sei righe. */
    private static function righe(string $predefinito): int
    {
        return max(2, min(6, (int) ceil(mb_strlen($predefinito) / 70)));
    }
}
