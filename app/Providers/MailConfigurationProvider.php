<?php

declare(strict_types=1);

namespace App\Providers;

use App\Settings\MailSettings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Mette in vigore la configurazione della posta scritta dal pannello.
 *
 * **Perche' qui e non in un middleware.** La posta non parte solo dalle
 * richieste web: parte dai lavori in coda, dai comandi schedulati, da
 * `queue:work` che gira per conto suo ogni minuto. Un middleware coprirebbe
 * solo la strada che passa dal browser — e le notifiche, che sono quasi tutto
 * il traffico di posta di questo sito, prenderebbero la configurazione
 * sbagliata proprio dove nessuno sta guardando.
 *
 * **Non si tocca niente se non e' tutto a posto.** `active()` pretende che la
 * configurazione sia accesa E provata: fuori da quel caso questo provider non
 * scrive nulla e la posta esce da `.env` come ha sempre fatto.
 *
 * **Un guasto qui non deve spegnere l'applicazione.** Se il database non
 * risponde — durante una migrazione, con le credenziali cambiate, in un
 * container che parte prima di MariaDB — leggere le impostazioni fallisce.
 * Fallire qui significherebbe una pagina bianca su TUTTO il sito per non aver
 * potuto leggere un host SMTP. Si tiene quella di `.env` e si tira dritto.
 */
class MailConfigurationProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         * `resolving` e non `boot` diretto: le impostazioni si leggono dal
         * database, e chiederle a ogni avvio significherebbe una query in piu'
         * su ogni richiesta e ogni comando — comprese le richieste che non
         * spediscono niente, che sono la quasi totalita'. Cosi' si legge solo
         * quando qualcuno chiede davvero di spedire.
         */
        $this->app->resolving('mail.manager', function (mixed $manager, Application $app): void {
            $this->applica($app);
        });
    }

    private function applica(Application $app): void
    {
        try {
            $impostazioni = $app->make(MailSettings::class);

            if (! $impostazioni->active()) {
                return;
            }
        } catch (Throwable) {
            /* Database irraggiungibile o impostazioni non ancora migrate:
               resta quella di `.env`, che e' esattamente cio' che serve. */
            return;
        }

        $config = $app->make('config');

        $config->set('mail.mailers.smtp', array_merge(
            (array) $config->get('mail.mailers.smtp', []),
            array_filter([
                'transport' => 'smtp',
                'host' => $impostazioni->host,
                'port' => $impostazioni->port,
                'encryption' => $impostazioni->encryption,
                'username' => $impostazioni->username,
                'password' => $impostazioni->password,
            ], static fn (mixed $valore): bool => $valore !== null && $valore !== ''),
        ));

        $config->set('mail.default', 'smtp');

        /*
         * Il mittente si sovrascrive solo se dichiarato: chi configura un
         * server SMTP senza toccare il mittente si aspetta che resti quello di
         * prima, non che diventi vuoto.
         */
        if (is_string($impostazioni->from_address) && $impostazioni->from_address !== '') {
            $config->set('mail.from.address', $impostazioni->from_address);
        }

        if (is_string($impostazioni->from_name) && $impostazioni->from_name !== '') {
            $config->set('mail.from.name', $impostazioni->from_name);
        }
    }
}
