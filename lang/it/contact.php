<?php

return [
    'title' => 'Contatta', 'settings' => 'Messaggi dalla pagina',
    /* La sezione del pannello: il titolo dice di cosa si parla, la domanda cosa si sceglie. */
    'mode_question' => 'Vuoi ricevere messaggi dalla pagina?',
    'modes' => ['disabled' => 'No, non voglio messaggi', 'members' => 'Sì, solo da chi ha un profilo', 'everyone' => 'Sì, da chiunque'],
    'recipient' => 'A quale email te li mando', 'private' => 'Non compare sul sito.',
    'captcha_missing' => 'Per far scrivere anche chi non ha un profilo serve la verifica anti-spam reCAPTCHA, che gli amministratori impostano nella configurazione. Chi ha un profilo può già scrivere.',
    'members_only' => 'Per ora posso farti scrivere solo da chi ha un profilo su :app.',
    'name' => 'Il tuo nome', 'email' => 'La tua email', 'message' => 'Il tuo messaggio', 'send' => 'Invia messaggio',
    'sent' => 'Messaggio inviato. Riceverai l’eventuale risposta al tuo indirizzo email.',
    'login' => 'Accedi per inviare un messaggio', 'privacy' => 'Nome, email e messaggio saranno inviati al destinatario per risponderti.',
    'captcha' => 'Completa la verifica reCAPTCHA e riprova.', 'subject' => 'Nuovo messaggio per :name',
    'guest_browser' => 'Apri il modulo con verifica anti-spam',
    'sender_daily_limit' => 'Hai già scritto più volte a questo recapito oggi. Riprova domani.',
    'target_daily_limit' => 'Questo recapito ha ricevuto troppi messaggi oggi. Riprova domani.',
    /* Doppio opt-in del recapito: finché non è confermato il modulo pubblico non compare. */
    'confirmation' => [
        'confirmed' => 'Confermato: :email riceve i messaggi del modulo pubblico.',
        'pending' => 'In attesa di conferma: chi legge :email deve aprire il collegamento ricevuto per email, valido :days giorni. Finché il recapito non è confermato, il modulo contatti non compare sulla pagina pubblica.',
        'resend' => 'Reinvia la conferma',
        'resent' => 'Email di conferma spedita a :email.',
        'failed' => 'Non è stato possibile spedire l’email di conferma. Riprova tra qualche minuto.',
        'limit' => 'Oggi sono già partite troppe email di conferma per questa scheda o per questo indirizzo. Riprova domani.',
        'mail_subject' => 'Conferma il recapito dei messaggi per :name',
        'mail_intro' => 'Su :product questo indirizzo è stato indicato per ricevere i messaggi che il pubblico scrive a :name dal modulo contatti. Conferma che questo indirizzo riceve i messaggi per :name.',
        'mail_action' => 'Conferma l’indirizzo',
        'mail_expires' => 'Il collegamento vale :days giorni.',
        'mail_ignore' => 'Se non sai di cosa si tratta, ignora questa email: senza conferma a questo indirizzo non arriva nessun messaggio.',
        'page_title' => 'Conferma del recapito',
        'page_pending' => 'Confermi che questo indirizzo riceve i messaggi del modulo contatti per :name?',
        'page_submit' => 'Sì, confermo',
        'page_not_you' => 'Se non sai di cosa si tratta, chiudi questa pagina: senza conferma non arriva nessun messaggio.',
        'page_confirmed' => 'Fatto: da ora i messaggi del modulo contatti per :name arrivano a questo indirizzo.',
        'page_invalid' => 'Questo collegamento non vale più: il recapito di :name è stato cambiato. Chi gestisce la scheda può spedire una nuova conferma dal pannello.',
    ],
];
