<?php

declare(strict_types=1);

namespace App\Support\Sponsorship;

/**
 * Riconosce dall'intestazione `User-Agent` i programmi che non sono persone:
 * motori di ricerca, anteprime dei social, browser senza schermo, Lighthouse.
 *
 * **Un filtro scritto in casa e non un pacchetto.** Serve a una cosa sola —
 * non contare come visualizzazione una richiesta che dichiara da sé di essere
 * un programma — e le parole che lo dicono sono poche e stabili. Un elenco di
 * migliaia di firme da aggiornare ogni mese sarebbe una dipendenza in più per
 * togliere qualche decimale.
 *
 * **Non è una difesa contro chi gonfia apposta**: chi vuole passare cambia
 * l'intestazione in un attimo. Contro di lui restano il gettone usa e getta e
 * i tetti per indirizzo e per campagna. Questo filtro toglie il rumore onesto,
 * quello dei programmi che si presentano per quello che sono.
 *
 * Un'intestazione vuota conta come programma: ogni browser e l'app Android
 * (`inCitta-Android/…`) la mandano sempre.
 */
final class BotFilter
{
    /**
     * Le parole che un programma mette nella propria firma. Si cercano senza
     * distinguere maiuscole e minuscole, in qualunque punto della firma.
     *
     * `bot` vale alla fine di una parola (`Googlebot/2.1`, `Slackbot-Link…`,
     * `Twitterbot`), ma non in `CUBOT`, che è una marca di telefoni Android e
     * compare nella firma di persone vere.
     */
    private const PATTERN = '/(?<!cu)bot(?![a-z0-9])|crawl|spider|slurp|headless|lighthouse|pagespeed|facebookexternalhit|embedly|phantomjs|puppeteer|playwright|selenium|wget\/|curl\/|python-requests|go-http-client/i';

    public static function isBot(?string $userAgent): bool
    {
        $firma = trim((string) $userAgent);

        if ($firma === '') {
            return true;
        }

        return preg_match(self::PATTERN, $firma) === 1;
    }
}
