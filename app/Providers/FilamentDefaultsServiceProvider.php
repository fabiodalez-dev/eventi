<?php

declare(strict_types=1);

namespace App\Providers;

use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Support\View\Components\ModalComponent;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Le impostazioni predefinite dei pannelli, uguali per redazione, locali e
 * organizzatori.
 *
 * **Il fuso.** Il database e l'applicazione lavorano in UTC; chi usa i
 * pannelli legge e scrive l'ora di casa. Senza un fuso predefinito ogni
 * colonna «Creato il», ogni data di pubblicazione programmata e ogni campo
 * data e ora senza `->timezone()` mostravano e salvavano l'UTC: un «21:30»
 * scritto in redazione diventava le 22:30 o le 23:30 sul sito. Le date delle
 * serate continuano a dichiarare il fuso della propria città (che vince su
 * questo); questo è il valore per tutto il resto.
 *
 * **Tabelle, filtri e finestre.** I valori di partenza che ogni elenco dei
 * pannelli eredita, anche nelle pagine che nessuno ha ridisegnato: un elenco
 * che dichiara il suo vince sempre. Pulsanti «Filtri» e «Colonne» con la
 * parola, filtri in un pannello laterale che sul telefono non esce dallo
 * schermo, uno stato vuoto che non dice «Nessun risultato» con la X, date di
 * ripiego in italiano («gio 19 nov 2026, 01:34»). Una finestra non si chiude
 * più con un clic fuori: chi stava scrivendo il motivo di un rifiuto lo
 * perdeva.
 */
final class FilamentDefaultsServiceProvider extends ServiceProvider
{
    public const TIMEZONE = 'Europe/Rome';

    /** Le date senza un formato dichiarato: «gio 19 nov 2026». */
    public const DATE_FORMAT = 'D j M Y';

    /** Data e ora senza un formato dichiarato: «gio 19 nov 2026, 01:34». */
    public const DATE_TIME_FORMAT = 'D j M Y, H:i';

    /**
     * Campi che ricevono già l'ora locale, convertita a mano da chi riempie il
     * modulo (`EventTicketingActions`): restano nel fuso dell'applicazione,
     * altrimenti la conversione si farebbe due volte e la finestra mostrerebbe
     * un'ora in più. Si toglie da qui quando quei campi dichiarano il proprio
     * `->timezone()`.
     */
    public const PRECONVERTED_FIELDS = ['booking_opens_at', 'booking_closes_at', 'cancellation_closes_at'];

    public function boot(): void
    {
        FilamentTimezone::set(self::TIMEZONE);

        /* Le viste comuni dei pannelli (`event-row`, `stat-cards`) si usano
           anche come componenti: `<x-pannelli::event-row>`. */
        Blade::anonymousComponentPath(resource_path('views/filament/partials'), 'pannelli');

        /*
         * Il fuso predefinito vale solo per data e ora insieme. Un orario
         * senza data (l'ora di chiusura della notte di una città, gli orari
         * di apertura di un locale) è un'ora da orologio di casa e si salva
         * com'è scritta: convertirlo lo sposterebbe di un'ora d'inverno e di
         * due d'estate. Un `->timezone()` scritto sul campo vince comunque,
         * perché arriva dopo questa impostazione.
         */
        Table::configureUsing(static function (Table $table): void {
            $table
                ->filtersLayout(FiltersLayout::Modal)
                ->filtersTriggerAction(static fn (Action $action): Action => $action
                    ->button()
                    ->label(__('pannelli-impianto.table.filters'))
                    ->slideOver()
                    /* Il numero sul pulsante si vede solo quando c'è un filtro
                       attivo: il tema nasconde il badge con zero. */
                    ->extraAttributes(static fn (): array => ['data-active-filters' => (string) (int) $action->getBadge()], merge: true))
                ->columnManagerTriggerAction(static fn (Action $action): Action => $action
                    ->button()
                    ->label(__('pannelli-impianto.table.columns')))
                ->defaultSortOptionLabel(static fn (): string => __('pannelli-impianto.table.sort_choose'))
                ->emptyStateIcon(Heroicon::OutlinedInbox)
                ->emptyStateHeading(static fn (): string => __('pannelli-impianto.table.empty_heading'))
                ->emptyStateDescription(static fn (): string => __('pannelli-impianto.table.empty_description'))
                ->defaultDateDisplayFormat(self::DATE_FORMAT)
                ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT);
        });

        Schema::configureUsing(static function (Schema $schema): void {
            $schema
                ->defaultDateDisplayFormat(self::DATE_FORMAT)
                ->defaultDateTimeDisplayFormat(self::DATE_TIME_FORMAT);
        });

        ModalComponent::closedByClickingAway(false);

        DateTimePicker::configureUsing(function (DateTimePicker $picker): void {
            $picker->timezone(static fn (DateTimePicker $component): string => $component->hasDate()
                && ! in_array($component->getName(), self::PRECONVERTED_FIELDS, true)
                    ? FilamentTimezone::get()
                    : config()->string('app.timezone'));
        });
    }
}
