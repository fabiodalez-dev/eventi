<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\NotificationText;
use Illuminate\Support\Arr;

/**
 * L'elenco dei testi delle email: quali sono, come sono raggruppati, e quale
 * fosse l'originale prima che qualcuno lo riscrivesse.
 *
 * **L'elenco lo detta il file di lingua, non la tabella.** Un pannello che
 * mostrasse le righe salvate mostrerebbe solo cio' che e' gia' stato
 * modificato: chi vuole cambiare un'email per la prima volta non troverebbe
 * niente da cambiare. Qui si parte da `lang/it/notifications.php` — che
 * contiene tutti i testi — e la tabella serve solo a dire quali sono stati
 * riscritti.
 */
final class NotificationTextCatalog
{
    /**
     * I gruppi da mostrare, nell'ordine in cui hanno senso per chi legge.
     *
     * `actions`, `common` e `mail` non sono email: sono le briciole comuni —
     * l'etichetta di un pulsante, il piede del messaggio — e stanno insieme
     * in fondo perche' toccarle cambia tutte le email in una volta.
     *
     * @var array<string, string>
     */
    private const GRUPPI = [
        'reminder' => 'Promemoria di un evento',
        'cancelled' => 'Evento annullato',
        'moved' => 'Evento spostato',
        'sold_out' => 'Biglietti esauriti',
        'daily_digest' => 'Riepilogo giornaliero',
        'weekend' => 'Newsletter del fine settimana',
        'venue_digest' => 'Novita di un locale seguito',
        'event_published' => 'Evento pubblicato',
        'event_rejected' => 'Evento rifiutato',
        'venue_approved' => 'Locale approvato',
        'venue_rejected' => 'Locale rifiutato',
        'venue_suspended' => 'Locale sospeso',
        'venue_inactive' => 'Locale fermo da tempo',
        'preferences' => 'Preferenze e disiscrizione',
        'unsubscribed' => 'Dopo la disiscrizione',
        'actions' => 'Etichette dei pulsanti (comuni)',
        'common' => 'Frasi comuni a tutte le email',
        'mail' => 'Intestazione e piede (comuni)',
    ];

    /**
     * I testi originali, letti dal file senza passare per il database.
     *
     * @return array<string, string> chiave completa => testo
     */
    public static function predefiniti(): array
    {
        $file = lang_path('it/notifications.php');

        if (! is_file($file)) {
            return [];
        }

        /** @var array<string, mixed> $righe */
        $righe = require $file;

        return self::appiattisci($righe, 'notifications.');
    }

    /**
     * Un gruppo alla volta, con originale e valore attuale.
     *
     * @return array<string, array{etichetta: string, testi: array<string, array{predefinito: string, attuale: string, modificato: bool}>}>
     */
    public static function perGruppo(): array
    {
        $predefiniti = self::predefiniti();
        $riscritti = NotificationText::tutte();
        $risultato = [];

        foreach (self::GRUPPI as $gruppo => $etichetta) {
            $prefisso = "notifications.{$gruppo}.";
            $testi = [];

            foreach ($predefiniti as $chiave => $originale) {
                if (! str_starts_with($chiave, $prefisso)) {
                    continue;
                }

                $testi[$chiave] = [
                    'predefinito' => $originale,
                    'attuale' => $riscritti[$chiave] ?? $originale,
                    'modificato' => isset($riscritti[$chiave]),
                ];
            }

            if ($testi !== []) {
                $risultato[$gruppo] = ['etichetta' => $etichetta, 'testi' => $testi];
            }
        }

        return $risultato;
    }

    /**
     * Le variabili che un testo puo' usare, lette dal testo stesso.
     *
     * Si ricavano dall'originale e non da un elenco scritto a mano: un elenco
     * a parte invecchia in silenzio, e chi riscrive un testo affidandosi a una
     * variabile che non esiste piu' se ne accorge quando l'email e' partita
     * con dentro `:title` scritto per esteso.
     *
     * @return list<string>
     */
    public static function variabili(string $testo): array
    {
        preg_match_all('/:([a-z_]+)/', $testo, $trovate);

        /** @var list<string> */
        return array_values(array_unique($trovate[1]));
    }

    /**
     * @param  array<string, mixed>  $righe
     * @return array<string, string>
     */
    private static function appiattisci(array $righe, string $prefisso): array
    {
        $piatte = [];

        foreach (Arr::dot($righe) as $chiave => $valore) {
            if (is_string($valore)) {
                $piatte[$prefisso.$chiave] = $valore;
            }
        }

        return $piatte;
    }
}
