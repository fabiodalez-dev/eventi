<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\NotificationText;
use App\Settings\NewsletterSettings;
use App\Support\Features;
use App\Support\NotificationTextCatalog;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

class Newsletter extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.admin.pages.newsletter';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('super_admin') === true;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.system');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $values = app(NewsletterSettings::class)->toArray();
        $values['enabled'] = Features::newsletterActive();
        foreach (self::texts() as $field => $key) {
            $values[$field] = NotificationText::tutte()[$key] ?? NotificationTextCatalog::predefiniti()[$key];
        }
        $this->getSchema('form')->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(1)->components([
            Section::make('Invio della newsletter')->description('Solo agli utenti con email verificata e consenso newsletter. Gli eventi seguono gli interessi e i locali seguiti; le categorie nascoste sono escluse. Senza eventi pertinenti non viene inviata alcuna email.')->schema([
                Toggle::make('enabled')->label('Newsletter attiva')->helperText('Disattivare ferma anche gli invii già programmati e conserva i consensi.'),
                Select::make('weekday')->label('Giorno di invio')->options([1 => 'Lunedì', 2 => 'Martedì', 3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato', 7 => 'Domenica'])->required()->in([1, 2, 3, 4, 5, 6, 7]),
                TextInput::make('time')->label('Ora di invio')->type('time')->required()->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->helperText('Nel fuso orario del destinatario, rispettando le sue ore di silenzio. Giorno e ora valgono per le nuove programmazioni; quelle già in coda conservano il proprio orario.'),
                TextInput::make('max_items')->label('Numero massimo di eventi')->numeric()->integer()->minValue(1)->maxValue(30)->required(),
            ]),
            Section::make('Testi della newsletter')->description('I testi vengono usati anche per gli invii già programmati. I campi vuoti ripristinano il testo originale.')->schema([
                TextInput::make('subject')->label('Oggetto')->maxLength(200),
                TextInput::make('heading')->label('Titolo')->maxLength(200),
                Textarea::make('line')->label('Testo introduttivo')->rows(4)->maxLength(2000)->helperText('Puoi usare :count per il numero di eventi.'),
                TextInput::make('action')->label('Testo del pulsante')->maxLength(120),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $values = $this->getSchema('form')->getState();
        $defaults = NotificationTextCatalog::predefiniti();
        foreach (self::texts() as $field => $key) {
            $unknown = array_diff(NotificationTextCatalog::variabili((string) ($values[$field] ?? '')), NotificationTextCatalog::variabili($defaults[$key]));
            if ($unknown !== []) {
                throw ValidationException::withMessages(['data.'.$field => 'Variabili non riconosciute: '.implode(', ', $unknown)]);
            }
        }
        DB::transaction(function () use ($values, $defaults): void {
            $settings = app(NewsletterSettings::class);
            $settings->weekday = (int) $values['weekday'];
            $settings->time = $values['time'];
            $settings->max_items = (int) $values['max_items'];
            $settings->save();
            Feature::for(Features::globalScope())->activate(Features::NEWSLETTER, (bool) $values['enabled']);
            foreach (self::texts() as $field => $key) {
                $value = trim((string) ($values[$field] ?? ''));
                if ($value === '' || $value === $defaults[$key]) {
                    NotificationText::where('key', $key)->delete();
                } else {
                    NotificationText::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => auth()->id()]);
                }
            }
        });
        NotificationText::dimenticaLaCache();
        Notification::make()->title('Impostazioni newsletter salvate')->success()->send();
    }

    /** @return array<string, string> */
    private static function texts(): array
    {
        return ['subject' => 'notifications.weekend.subject', 'heading' => 'notifications.weekend.heading', 'line' => 'notifications.weekend.line', 'action' => 'notifications.actions.open_weekend'];
    }
}
