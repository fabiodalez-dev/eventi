<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * La configurazione della posta, modificabile dal pannello (§ ruoli: Super
 * Admin, impostazioni di sistema).
 *
 * **Perche' serve.** In produzione la posta esce dal `sendmail` del server:
 * funziona, ma un messaggio che parte da un hosting condiviso senza SPF ne'
 * DKIM del mittente finisce nella posta indesiderata con una regolarita' che
 * si nota. Cambiare fornitore SMTP significava mettere mano al `.env` sul
 * server via SSH — cosa che chi amministra il sito non deve essere costretto
 * a fare.
 *
 * **`enabled` e' separato dal resto, e non e' un dettaglio.** Si possono
 * scrivere e conservare le credenziali senza che siano in vigore: finche'
 * quella casella e' spenta la posta continua a uscire come prima. Ed e' anche
 * il modo di tornare indietro senza cancellare niente, che serve nel momento
 * peggiore — quando qualcosa e' appena andato storto.
 *
 * **`verified_at` e' il permesso di accendere.** Vale per l'impronta della
 * configurazione con cui l'invio di prova e' riuscito: cambiare host o
 * password lo azzera, e la casella `enabled` torna a essere inaccessibile
 * finche' non si riprova. Senza questo vincolo un refuso nella password
 * spegnerebbe in silenzio TUTTE le notifiche del sito — quelle in coda
 * fallirebbero una a una e non se ne accorgerebbe nessuno finche' qualcuno
 * non si lamenta di non aver ricevuto un promemoria.
 */
class MailSettings extends Settings
{
    /** Se la configurazione qui dentro deve sostituire quella di `.env`. */
    public bool $enabled;

    public ?string $host;

    public ?int $port;

    /** `tls`, `ssl`, oppure niente. */
    public ?string $encryption;

    public ?string $username;

    public ?string $password;

    /** Il mittente: quello che chi riceve vede scritto nel messaggio. */
    public ?string $from_address;

    public ?string $from_name;

    /**
     * L'impronta della configurazione con cui l'ultimo invio di prova e'
     * riuscito, e quando. Sono due campi e non uno perche' la data da sola
     * direbbe «una prova e' andata bene» senza dire *di che cosa*.
     */
    public ?string $verified_fingerprint;

    public ?string $verified_at;

    public static function group(): string
    {
        return 'mail';
    }

    /**
     * La password non sta in chiaro nel database.
     *
     * Le credenziali di un server di posta valgono quanto quelle di un
     * account: chi le ottiene puo' spedire a nome del sito. Cifrarle a riposo
     * non protegge da chi ha la `APP_KEY`, ma protegge da tutto il resto — un
     * dump del database finito nel posto sbagliato, un backup letto da chi non
     * doveva, una query di diagnostica copiata in una chat.
     *
     * @return array<int, string>
     */
    public static function encrypted(): array
    {
        return ['password'];
    }

    /**
     * L'impronta di cio' che determina se la posta parte: host, porta,
     * cifratura, utenza e password.
     *
     * Il mittente non ci entra di proposito. Cambiarlo non puo' rompere la
     * connessione al server, e obbligare a rifare la prova per aver corretto
     * un nome visualizzato sarebbe fastidio senza contropartita.
     */
    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [
            (string) $this->host,
            (string) $this->port,
            (string) $this->encryption,
            (string) $this->username,
            (string) $this->password,
        ]));
    }

    /**
     * Se questa configurazione e' stata provata **cosi' com'e' adesso**.
     *
     * Confronta l'impronta, non la data: una prova riuscita ieri su un altro
     * host non dice niente su quello di oggi.
     */
    public function verified(): bool
    {
        return $this->verified_fingerprint !== null
            && hash_equals($this->verified_fingerprint, $this->fingerprint());
    }

    /**
     * Se e' in vigore davvero: accesa **e** provata.
     *
     * Le due condizioni stanno insieme in un metodo solo perche' chiunque le
     * legga separate prima o poi ne dimentica una.
     */
    public function active(): bool
    {
        return $this->enabled && $this->verified() && $this->host !== null && $this->host !== '';
    }
}
