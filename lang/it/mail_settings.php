<?php

declare(strict_types=1);

return [
    'title' => 'Configurazione della posta',
    'lead' => 'Da quale server escono i messaggi che il sito manda: promemoria, collegamenti di accesso, reimpostazioni di password.',

    'status' => [
        'env' => 'La posta esce dalla configurazione del server',
        'env_detail' => 'Trasporto «:trasporto», mittente :mittente. È quella scritta nel file di configurazione: qui sotto non c\'è ancora niente in vigore.',
        'custom' => 'La posta esce dalla configurazione scritta qui',
        'custom_detail' => 'Server :host sulla porta :porta.',
        'stale_check' => 'Attenzione: la prova riuscita riguarda una configurazione diversa da quella scritta adesso. Rifalla prima di attivare.',
    ],

    'server' => [
        'title' => 'Server di posta',
        'lead' => 'Le coordinate SMTP del fornitore. La password viene conservata cifrata.',
    ],

    'sender' => [
        'title' => 'Mittente',
        'lead' => 'Quello che chi riceve vede scritto. Se il dominio non corrisponde a quello autorizzato dal fornitore, i messaggi finiscono nella posta indesiderata.',
    ],

    'activation' => [
        'title' => 'Attivazione',
        'lead' => 'Finché è spenta, la posta continua a uscire come prima. È anche il modo di tornare indietro senza cancellare niente.',
    ],

    'fields' => [
        'host' => 'Server',
        'port' => 'Porta',
        'port_help' => 'Di solito 587 con TLS, 465 con SSL.',
        'encryption' => 'Cifratura',
        'encryption_none' => 'Nessuna',
        'username' => 'Utenza',
        'password' => 'Password',
        'password_help' => 'Conservata cifrata. Lasciandola vuota si cancella.',
        'from_address' => 'Indirizzo del mittente',
        'from_name' => 'Nome del mittente',
        'enabled' => 'Usa questa configurazione',
        'enabled_help' => 'La prova è riuscita con queste credenziali: puoi attivarla.',
        'enabled_locked' => 'Prima manda un messaggio di prova: finché non arriva davvero, questa casella resta bloccata. Un refuso qui spegnerebbe in silenzio tutte le notifiche del sito.',
    ],

    'actions' => [
        'test' => 'Manda un messaggio di prova',
        'save' => 'Salva',
    ],

    'test' => [
        'subject' => 'Prova di configurazione — :product',
        'sent' => 'Messaggio spedito a :email',
        'sent_body' => 'Se arriva, la configurazione funziona e puoi attivarla. Se non arriva entro qualche minuto, controlla anche la posta indesiderata.',
        'failed' => 'Il server ha rifiutato il messaggio',
        'no_recipient' => 'Il tuo account non ha un indirizzo email: non saprei dove mandare la prova.',
        'mail_title' => 'La configurazione funziona',
        'mail_body' => 'Questo messaggio è partito dal server di posta che hai appena configurato. Se lo stai leggendo, le credenziali sono corrette e puoi attivare la configurazione dal pannello.',
        'mail_when' => 'Spedito il :quando.',
        'mail_footer' => 'Messaggio automatico di prova: nessuno l\'ha ricevuto oltre a te.',
    ],

    'saved' => 'Configurazione salvata',
    'saved_active' => 'È in vigore: i prossimi messaggi usciranno da questo server.',
    'saved_inactive' => 'Resta spenta: la posta continua a uscire come prima.',
];
