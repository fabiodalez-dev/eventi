<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Enums\UserRole;
use App\Mail\MailConfigurationTest;
use App\Models\User;
use App\Settings\MailSettings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * L'invio di prova della configurazione della posta, per il pannello (pagina
 * «Configurazione della posta») e per l'app della redazione: solo il super
 * admin, che può far uscire posta a nome del sito.
 *
 * Stava dentro `App\Filament\Admin\Pages\MailConfiguration::inviaProva`. Il
 * pannello prova le credenziali scritte nel modulo, anche non salvate; l'app
 * non scrive credenziali (decisione 5 del piano della redazione) e prova
 * quelle salvate. In tutti e due i casi, se il messaggio parte, l'impronta di
 * quella configurazione viene registrata e la casella «usa questa
 * configurazione» si può accendere.
 */
final class MailConfigurationTester
{
    /**
     * Il nome del trasporto usato solo dall'invio di prova: vive accanto agli
     * altri e non ne sostituisce nessuno.
     */
    public const MAILER = 'configurazione-in-prova';

    public static function canManage(?User $user): bool
    {
        return $user instanceof User && $user->hasRole(UserRole::SuperAdmin->value);
    }

    /**
     * L'impronta di host, porta, cifratura, utenza e password: la stessa di
     * `MailSettings::fingerprint()` quando i valori sono quelli salvati.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fingerprint(array $values): string
    {
        return hash('sha256', implode('|', [
            self::text($values['host'] ?? ''),
            self::text($values['port'] ?? ''),
            self::text($values['encryption'] ?? ''),
            self::text($values['username'] ?? ''),
            self::text($values['password'] ?? ''),
        ]));
    }

    /**
     * I valori salvati, nella forma del modulo del pannello. Contengono la
     * password: servono solo a spedire, non escono mai in una risposta.
     *
     * @return array<string, mixed>
     */
    public static function savedValues(): array
    {
        $settings = app(MailSettings::class);

        return [
            'host' => $settings->host,
            'port' => $settings->port,
            'encryption' => $settings->encryption,
            'username' => $settings->username,
            'password' => $settings->password,
            'from_address' => $settings->from_address,
            'from_name' => $settings->from_name,
        ];
    }

    /**
     * Spedisce il messaggio di prova a `$recipient` con la configurazione di
     * `$values` e, se parte, registra l'impronta. Un errore del server di
     * posta esce com'è: «autenticazione fallita», «certificato non valido» e
     * «host irraggiungibile» chiedono tre rimedi diversi.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws Throwable
     */
    public static function send(array $values, string $recipient): void
    {
        /*
         * **Un trasporto temporaneo accanto agli altri, non al posto loro.**
         * Registrare un mailer con un nome proprio e spedire con quello non
         * tocca niente di ciò che usano gli altri (né `Mail::fake()` nei test).
         */
        Config::set('mail.mailers.'.self::MAILER, array_filter([
            'transport' => 'smtp',
            'host' => $values['host'] ?? null,
            'port' => $values['port'] ?? null,
            'encryption' => $values['encryption'] ?? null,
            'username' => $values['username'] ?? null,
            'password' => $values['password'] ?? null,
            /* Un invio di prova che resta appeso mezzo minuto sembra un
               blocco: meglio un errore netto in dieci secondi. */
            'timeout' => 10,
        ], static fn (mixed $v): bool => $v !== null && $v !== ''));

        $sender = [];

        if (is_string($values['from_address'] ?? null) && $values['from_address'] !== '') {
            $sender['address'] = $values['from_address'];
        }

        if (is_string($values['from_name'] ?? null) && $values['from_name'] !== '') {
            $sender['name'] = $values['from_name'];
        }

        $message = Mail::mailer(self::MAILER)->to($recipient);

        $test = new MailConfigurationTest;

        if ($sender !== []) {
            $test->from(
                $sender['address'] ?? config()->string('mail.from.address'),
                $sender['name'] ?? null,
            );
        }

        $message->send($test);

        $settings = app(MailSettings::class);
        $settings->verified_fingerprint = self::fingerprint($values);
        $settings->verified_at = now()->toDateTimeString();
        $settings->save();
    }

    /**
     * Il dettaglio da mostrare quando la prova non parte. Il messaggio del
     * server di posta per esteso («autenticazione fallita», «certificato non
     * valido», «host irraggiungibile» chiedono tre rimedi diversi), ma solo
     * per un errore del trasporto: qualunque altro guasto (il database al
     * salvataggio dell'impronta, un percorso del server) va nel registro
     * degli errori e a chi prova arriva una frase generica.
     */
    public static function failureDetail(Throwable $e): string
    {
        if ($e instanceof TransportExceptionInterface) {
            return $e->getMessage();
        }

        report($e);

        return (string) __('mail_settings.test.internal_error');
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
