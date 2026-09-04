<?php

declare(strict_types=1);

use App\Support\Redirect\RedirectDalDatabase;
use Symfony\Component\HttpFoundation\Response;

/*
 * Gli indirizzi che non esistono più (vedi la migrazione `redirects`).
 */
return [

    /*
     * Da dove arrivano i reindirizzamenti. Il predefinito del pacchetto legge
     * l'array `redirects` qui sotto: qui li scrivono gli observer quando uno
     * slug cambia e la redazione dal pannello, quindi la sorgente è il
     * database e l'array resta vuoto per sempre.
     */
    'redirector' => RedirectDalDatabase::class,

    /*
     * Solo i 404. Lasciare l'elenco vuoto vorrebbe dire intercettare **ogni**
     * risposta, comprese quelle riuscite: una query in più su ogni pagina del
     * sito per un caso che non esiste.
     */
    'redirect_status_codes' => [
        Response::HTTP_NOT_FOUND,
    ],

    'redirects' => [],

];
