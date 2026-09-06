<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\UserRole;
use App\Mail\MailConfigurationTest;
use App\Models\User;
use App\Settings\MailSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * La configurazione del server di posta, per chi amministra il sistema.
 *
 * **Perche' esiste.** In produzione la posta esce dal `sendmail` del server:
 * funziona, ma un messaggio spedito da un hosting condiviso senza SPF ne' DKIM
 * del mittente finisce nella posta indesiderata con una regolarita' che si
 * nota. Cambiare fornitore significava mettere mano al `.env` via SSH — cosa
 * che chi amministra il sito non deve essere costretto a fare.
 *
 * **Solo super amministratore.** Chi puo' scrivere qui puo' far uscire posta a
 * nome del sito, e puo' dirottare su un server proprio i messaggi che il sito
 * spedisce — dentro ci sono collegamenti di accesso e di reimpostazione
 * password. Non e' una preferenza fra le altre.
 *
 * **Si accende solo dopo una prova riuscita.** La casella «usa questa
 * configurazione» resta bloccata finche' un invio vero non e' andato a buon
 * fine con QUESTE credenziali. Senza quel vincolo un refuso nella password
 * spegnerebbe in silenzio tutte le notifiche del sito: i lavori in coda
 * fallirebbero uno a uno e non se ne accorgerebbe nessuno finche' qualcuno non
 * si lamenta di non aver ricevuto un promemoria.
 */
class MailConfiguration extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'configurazione-posta';

    protected string $view = 'filament.admin.pages.mail-configuration';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Il nome del trasporto usato solo dall'invio di prova: vive accanto agli
     * altri e non ne sostituisce nessuno.
     */
    private const MAILER_DI_PROVA = 'configurazione-in-prova';

    public static function canAccess(): bool
    {
        $utente = Auth::user();

        return $utente instanceof User && $utente->hasRole(UserRole::SuperAdmin->value);
    }

    public static function getNavigationLabel(): string
    {
        return __('mail_settings.title');
    }

    public function getTitle(): string
    {
        return __('mail_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('mail_settings.lead');
    }

    public function mount(): void
    {
        $impostazioni = app(MailSettings::class);

        $this->modulo()->fill([
            'enabled' => $impostazioni->enabled,
            'host' => $impostazioni->host,
            'port' => $impostazioni->port,
            'encryption' => $impostazioni->encryption,
            'username' => $impostazioni->username,
            'password' => $impostazioni->password,
            'from_address' => $impostazioni->from_address,
            'from_name' => $impostazioni->from_name,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(1)
            ->statePath('data')
            ->components([
                Section::make(__('mail_settings.server.title'))
                    ->description(__('mail_settings.server.lead'))
                    ->schema([
                        TextInput::make('host')
                            ->label(__('mail_settings.fields.host'))
                            ->placeholder('smtp.example.com')
                            ->maxLength(255),

                        TextInput::make('port')
                            ->label(__('mail_settings.fields.port'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(65535)
                            ->helperText(__('mail_settings.fields.port_help')),

                        Select::make('encryption')
                            ->label(__('mail_settings.fields.encryption'))
                            ->options([
                                'tls' => 'TLS / STARTTLS',
                                'ssl' => 'SSL',
                            ])
                            ->placeholder(__('mail_settings.fields.encryption_none')),

                        TextInput::make('username')
                            ->label(__('mail_settings.fields.username'))
                            ->maxLength(255),

                        TextInput::make('password')
                            ->label(__('mail_settings.fields.password'))
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText(__('mail_settings.fields.password_help')),
                    ])
                    ->columns(2),

                Section::make(__('mail_settings.sender.title'))
                    ->description(__('mail_settings.sender.lead'))
                    ->schema([
                        TextInput::make('from_address')
                            ->label(__('mail_settings.fields.from_address'))
                            ->email()
                            ->maxLength(255),

                        TextInput::make('from_name')
                            ->label(__('mail_settings.fields.from_name'))
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make(__('mail_settings.activation.title'))
                    ->description(__('mail_settings.activation.lead'))
                    ->schema([
                        Toggle::make('enabled')
                            ->label(__('mail_settings.fields.enabled'))
                            /*
                             * Il blocco e' il cuore della pagina: si accende
                             * solo dopo una prova riuscita con queste stesse
                             * credenziali. Cambiare host o password azzera la
                             * verifica e richiude la casella.
                             */
                            ->disabled(fn (): bool => ! $this->verificata())
                            ->helperText(fn (): string => $this->verificata()
                                ? __('mail_settings.fields.enabled_help')
                                : __('mail_settings.fields.enabled_locked')),
                    ]),
            ]);
    }

    /**
     * Se la configurazione che c'e' **nel modulo adesso** è quella con cui la
     * prova e' riuscita.
     *
     * Si guarda il modulo e non le impostazioni salvate: chi ha appena
     * cambiato l'host senza salvare deve vedere subito la casella richiudersi,
     * non scoprirlo dopo aver premuto «salva».
     */
    private function verificata(): bool
    {
        $salvate = app(MailSettings::class);

        if ($salvate->verified_fingerprint === null) {
            return false;
        }

        $corrente = $this->impronataDelModulo();

        return hash_equals($salvate->verified_fingerprint, $corrente);
    }

    private function impronataDelModulo(): string
    {
        /** @var array<string, mixed> $dati */
        $dati = $this->data;

        return hash('sha256', implode('|', [
            (string) ($dati['host'] ?? ''),
            (string) ($dati['port'] ?? ''),
            (string) ($dati['encryption'] ?? ''),
            (string) ($dati['username'] ?? ''),
            (string) ($dati['password'] ?? ''),
        ]));
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('prova')
                ->label(__('mail_settings.actions.test'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->action('inviaProva'),

            Action::make('salva')
                ->label(__('mail_settings.actions.save'))
                ->action('salva'),
        ];
    }

    /**
     * L'invio di prova, con le credenziali che stanno nel modulo — non con
     * quelle salvate.
     *
     * E' l'unico modo di provare qualcosa che non e' ancora in vigore: la
     * configurazione si scrive in una copia della configurazione viva, si
     * spedisce, e si rimette com'era. Se il messaggio arriva, l'impronta viene
     * registrata e la casella di attivazione si apre.
     */
    public function inviaProva(): void
    {
        /** @var array<string, mixed> $dati */
        $dati = $this->modulo()->getState();

        $destinatario = Auth::user()?->email;

        if (! is_string($destinatario) || $destinatario === '') {
            Notification::make()->danger()->title(__('mail_settings.test.no_recipient'))->send();

            return;
        }

        /*
         * **Un trasporto temporaneo accanto agli altri, non al posto loro.**
         *
         * La prima versione sovrascriveva `mail.default` e buttava via il
         * gestore in cache per forzarlo a ricostruirsi. Funzionava, ma per un
         * singolo invio metteva le mani sullo stato di tutta l'applicazione, e
         * il ripristino dipendeva da un `finally` che qualcuno prima o poi
         * avrebbe spostato. In prova distruggeva perfino `Mail::fake()`, e il
         * test non riusciva piu' a vedere il messaggio che verificava.
         *
         * Registrare un mailer con un nome proprio e spedire con quello non
         * tocca niente di cio' che usano gli altri.
         */
        Config::set('mail.mailers.'.self::MAILER_DI_PROVA, array_filter([
            'transport' => 'smtp',
            'host' => $dati['host'] ?? null,
            'port' => $dati['port'] ?? null,
            'encryption' => $dati['encryption'] ?? null,
            'username' => $dati['username'] ?? null,
            'password' => $dati['password'] ?? null,
            /* Un invio di prova che resta appeso mezzo minuto sembra un
               blocco: meglio un errore netto in dieci secondi. */
            'timeout' => 10,
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));

        $mittente = [];

        if (is_string($dati['from_address'] ?? null) && $dati['from_address'] !== '') {
            $mittente['address'] = $dati['from_address'];
        }

        if (is_string($dati['from_name'] ?? null) && $dati['from_name'] !== '') {
            $mittente['name'] = $dati['from_name'];
        }

        try {
            $messaggio = Mail::mailer(self::MAILER_DI_PROVA)->to($destinatario);

            $prova = new MailConfigurationTest;

            if ($mittente !== []) {
                $prova->from(
                    $mittente['address'] ?? config()->string('mail.from.address'),
                    $mittente['name'] ?? null,
                );
            }

            $messaggio->send($prova);

            $impostazioni = app(MailSettings::class);
            $impostazioni->verified_fingerprint = $this->impronataDelModulo();
            $impostazioni->verified_at = now()->toDateTimeString();
            $impostazioni->save();

            Notification::make()
                ->success()
                ->title(__('mail_settings.test.sent', ['email' => $destinatario]))
                ->body(__('mail_settings.test.sent_body'))
                ->persistent()
                ->send();
        } catch (Throwable $e) {
            /*
             * Il messaggio del server per esteso, non una frase generica:
             * «autenticazione fallita», «certificato non valido» e «host
             * irraggiungibile» richiedono tre rimedi diversi, e chi sta
             * configurando deve poterli distinguere senza aprire i registri.
             */
            Notification::make()
                ->danger()
                ->title(__('mail_settings.test.failed'))
                ->body($e->getMessage())
                ->persistent()
                ->send();
        }
    }

    public function salva(): void
    {
        /** @var array<string, mixed> $dati */
        $dati = $this->modulo()->getState();

        $impostazioni = app(MailSettings::class);

        $impostazioni->host = $this->testo($dati['host'] ?? null);
        $impostazioni->port = is_numeric($dati['port'] ?? null) ? (int) $dati['port'] : null;
        $impostazioni->encryption = $this->testo($dati['encryption'] ?? null);
        $impostazioni->username = $this->testo($dati['username'] ?? null);
        $impostazioni->password = $this->testo($dati['password'] ?? null);
        $impostazioni->from_address = $this->testo($dati['from_address'] ?? null);
        $impostazioni->from_name = $this->testo($dati['from_name'] ?? null);

        /*
         * L'accensione non si fida di cio' che arriva dal modulo: la casella
         * puo' essere disabilitata a schermo, ma una richiesta costruita a mano
         * arriverebbe lo stesso. La condizione si ricontrolla qui.
         */
        $impostazioni->enabled = ($dati['enabled'] ?? false) === true && $impostazioni->verified();

        $impostazioni->save();

        Notification::make()
            ->success()
            ->title(__('mail_settings.saved'))
            ->body($impostazioni->active()
                ? __('mail_settings.saved_active')
                : __('mail_settings.saved_inactive'))
            ->send();
    }

    /**
     * Lo schema di questa pagina.
     *
     * `$this->form` funziona a runtime per una scorciatoia magica, ma non e'
     * dichiarato da nessuna parte: l'analisi statica non sa che esista, e
     * aveva ragione a dirlo. Si chiede per nome, come fa il resto del
     * progetto.
     */
    private function modulo(): Schema
    {
        $modulo = $this->getSchema('form');

        abort_if($modulo === null, 500);

        return $modulo;
    }

    private function testo(mixed $valore): ?string
    {
        return is_string($valore) && trim($valore) !== '' ? trim($valore) : null;
    }
}
