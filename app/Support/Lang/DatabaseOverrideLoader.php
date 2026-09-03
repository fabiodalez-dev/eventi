<?php

declare(strict_types=1);

namespace App\Support\Lang;

use App\Models\NotificationText;
use Illuminate\Support\Arr;
use Illuminate\Translation\FileLoader;

/**
 * Il caricatore di traduzioni, con le riscritture del pannello sopra il file.
 *
 * **Perché qui e non in `MessageFactory`.** I testi delle email si comporranno
 * sempre con `__('notifications.…')`, sparse in centinaia di righe. Passare da
 * qui invece che da ognuna di quelle chiamate ha tre effetti che nessun'altra
 * strada dà insieme:
 *
 * 1. le variabili (`:title`, `:when`) continuano a funzionare, perché a
 *    sostituirle è Laravel dopo di noi, sul testo che gli restituiamo;
 * 2. il file resta il valore predefinito — una tabella vuota, o irraggiungibile,
 *    lascia le email esattamente come sono oggi;
 * 3. ogni testo aggiunto in futuro nasce gia' modificabile, senza altro lavoro
 *    e senza che qualcuno debba ricordarsene.
 *
 * **Solo il gruppo `notifications`.** Il resto dei file di lingua — le
 *  etichette del pannello, i messaggi di errore — non si tocca da qui: sono
 * migliaia di righe che nessuno vuole modificare a caldo, e caricarle tutte
 * dal database renderebbe piu' lento ogni disegno di pagina per una funzione
 * che serve a quindici testi.
 */
final class DatabaseOverrideLoader extends FileLoader
{
    private const GRUPPO = 'notifications';

    /**
     * @return array<string, mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        /** @var array<string, mixed> $righe */
        $righe = parent::load($locale, $group, $namespace);

        if ($group !== self::GRUPPO || $namespace !== null && $namespace !== '*') {
            return $righe;
        }

        foreach (NotificationText::tutte() as $chiave => $testo) {
            /* Le chiavi sono salvate per intero — `notifications.reminder.subject`
               — perche' e' cosi' che si scrivono nel codice e cosi' vanno
               cercate. Qui pero' siamo gia' dentro il gruppo, quindi la parte
               iniziale va tolta: lasciarla creerebbe una voce annidata
               `notifications.notifications.reminder`, che nessuno leggerebbe
               mai e che non darebbe alcun errore. */
            $prefisso = self::GRUPPO.'.';

            if (! str_starts_with($chiave, $prefisso)) {
                continue;
            }

            Arr::set($righe, substr($chiave, strlen($prefisso)), $testo);
        }

        return $righe;
    }
}
