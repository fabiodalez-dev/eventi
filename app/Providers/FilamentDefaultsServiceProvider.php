<?php

declare(strict_types=1);

namespace App\Providers;

use Filament\Forms\Components\DateTimePicker;
use Filament\Support\Facades\FilamentTimezone;
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
 */
final class FilamentDefaultsServiceProvider extends ServiceProvider
{
    public const TIMEZONE = 'Europe/Rome';

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

        /*
         * Il fuso predefinito vale solo per data e ora insieme. Un orario
         * senza data (l'ora di chiusura della notte di una città, gli orari
         * di apertura di un locale) è un'ora da orologio di casa e si salva
         * com'è scritta: convertirlo lo sposterebbe di un'ora d'inverno e di
         * due d'estate. Un `->timezone()` scritto sul campo vince comunque,
         * perché arriva dopo questa impostazione.
         */
        DateTimePicker::configureUsing(function (DateTimePicker $picker): void {
            $picker->timezone(static fn (DateTimePicker $component): string => $component->hasDate()
                && ! in_array($component->getName(), self::PRECONVERTED_FIELDS, true)
                    ? FilamentTimezone::get()
                    : config()->string('app.timezone'));
        });
    }
}
