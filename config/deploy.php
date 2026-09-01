<?php

declare(strict_types=1);

/*
 * Dove stanno gli strumenti che il rilascio usa (`deploy:pull`).
 *
 * **Perché non basta il PATH.** Il rilascio parte da un processo PHP — dal web
 * o da un comando — e quel processo non legge il profilo della shell. Sulla
 * shared hosting node sta nella home dell'utente e ci finisce nel PATH solo
 * per le sessioni interattive: `npm` esiste, si vede scrivendolo a mano, e il
 * rilascio non lo trova. Stessa storia per PHP, che in shell è 8.3 e per
 * l'applicazione è 8.4.
 *
 * Vuoto significa «cercalo nei posti soliti»: questi valori servono a
 * un'installazione fuori dall'ordinario, per dichiararlo una volta invece di
 * far indovinare il codice.
 */
return [

    'php_binary' => env('DEPLOY_PHP_BINARY'),

    'npm_binary' => env('DEPLOY_NPM_BINARY'),

    'composer_binary' => env('DEPLOY_COMPOSER_BINARY'),

];
