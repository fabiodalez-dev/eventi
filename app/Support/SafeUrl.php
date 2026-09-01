<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Un indirizzo da mettere in un `href`, o niente.
 *
 * **Perché serve.** Blade sfugge il contenuto di un attributo, e questo evita
 * che qualcuno chiuda le virgolette e scriva markup — ma non impedisce che
 * l'indirizzo stesso sia `javascript:...`. Quel valore, dentro un `href`,
 * esegue codice al clic: non è un'iniezione di HTML, è un URL che fa quello
 * che gli URL di quello schema fanno.
 *
 * Il campo lo compila chi gestisce un locale o propone un evento. Non è
 * necessariamente ostile — ma è comunque testo che arriva da fuori e finisce
 * in un attributo che il browser esegue.
 *
 * **Cosa passa**: `http` e `https`, e nient'altro. Non `mailto:` e non `tel:`
 * — quelli hanno i loro punti nel codice, dove il valore è un indirizzo di
 * posta o un numero e non un URL generico. Non gli indirizzi relativi:
 * qui si tratta sempre di destinazioni esterne, e un `/qualcosa` che arriva
 * dai dati non è una cosa che vogliamo seguire.
 *
 * **Dove va usato**: ovunque un URL che arriva dal database finisca in un
 * `href`. La difesa sta in uscita e non solo in ingresso, perché i dati già
 * salvati sono passati da validazioni di ieri.
 */
final class SafeUrl
{
    /**
     * L'indirizzo se è sicuro da mettere in un `href`, altrimenti `null`.
     *
     * Chi chiama non deve disegnare il collegamento quando riceve `null`: un
     * `<a href="">` porta alla pagina stessa, che è peggio di un collegamento
     * assente.
     */
    public static function href(mixed $url): ?string
    {
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        /*
         * Lo schema si legge con `parse_url` e non con una ricerca di testo:
         * `java\tscript:` e `JaVaScRiPt:` sono lo stesso schema per il
         * browser, e un confronto ingenuo li lascia passare entrambi.
         * `parse_url` invece normalizza, e su un valore che non riesce a
         * interpretare restituisce `false` — che qui vale «no».
         */
        $schema = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($schema)) {
            return null;
        }

        return in_array(mb_strtolower($schema), ['http', 'https'], strict: true) ? $url : null;
    }
}
