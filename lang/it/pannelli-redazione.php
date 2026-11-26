<?php

declare(strict_types=1);

/*
 * Testi della redazione resi leggibili: riepilogo e elenchi più usati.
 * Ogni riquadro del riepilogo dice in una frase cosa aspetta una decisione.
 */
return [
    'riepilogo' => [
        'pending_events' => '{0} Nessun evento da guardare: la coda è vuota.|{1} Un evento aspetta che tu lo pubblichi o lo rifiuti.|[2,*] :count eventi aspettano che tu li pubblichi o li rifiuti.',
        'pending_venues' => '{0} Nessun locale in attesa di conferma.|{1} Un locale aspetta la tua conferma prima di andare online.|[2,*] :count locali aspettano la tua conferma prima di andare online.',
        'pending_applications' => '{0} Nessuna richiesta da leggere.|{1} Una persona chiede di gestire un locale o di iscriverne uno.|[2,*] :count persone chiedono di gestire un locale o di iscriverne uno.',
        'open_reports' => '{0} Nessuna segnalazione da guardare.|{1} Una segnalazione del pubblico è da guardare.|[2,*] :count segnalazioni del pubblico sono da guardare.',
    ],
    'proposte' => [
        'when' => 'Quando',
        'from' => 'Proposta da :name',
        'empty_heading' => 'Nessuna proposta da guardare',
        'empty_description' => 'Quando qualcuno propone un evento dal sito, lo trovi qui da approvare o rifiutare.',
    ],
    'segnalazioni' => [
        'received' => 'Arrivata',
        'empty_heading' => 'Nessuna segnalazione',
        'empty_description' => 'Quando qualcuno segnala un evento, un locale o un commento, lo trovi qui.',
        'saved' => 'Esito della segnalazione salvato.',
        'deleted' => 'Segnalazione eliminata.',
    ],
    'richieste' => [
        'empty_heading' => 'Nessuna richiesta',
        'empty_description' => 'Qui arrivano le persone che vogliono iscrivere un locale o gestirne uno già presente.',
    ],
    'organizzatori' => [
        'active' => 'Attivo',
        'inactive' => 'Non ancora attivo',
        'empty_heading' => 'Nessun organizzatore',
        'empty_description' => 'Un organizzatore è un gruppo o un collettivo che fa eventi in più posti. Lo crei con «Nuovo».',
    ],
    // Troppe password sbagliate allo sblocco della redazione nell'app (`EditorialUnlockLockout`).
    'sblocco_fermato' => [
        'subject_owner' => 'Redazione di :product: accesso dall\'app fermato',
        'owner_intro' => 'Sul telefono collegato al tuo account la password per aprire la Redazione è stata sbagliata :count volte in un giorno. Per sicurezza la sessione dell\'app su quel telefono è stata chiusa.',
        'owner_action' => 'Se eri tu, rientra nell\'app con email e password. Se non eri tu, cambia subito la password dal sito: qualcuno ha in mano un telefono con il tuo account aperto.',
        'subject_admin' => 'Redazione di :product: sessione dell\'app di :account revocata',
        'admin_intro' => 'Sull\'account :account la password per aprire la Redazione nell\'app è stata sbagliata :count volte in un giorno (ultimo indirizzo :ip). La sessione dell\'app di quel telefono è stata revocata.',
        'admin_action' => 'I tentativi sono nel registro di sicurezza (redazione_sblocco_fallito, redazione_token_revocato). La persona è stata avvisata per email.',
    ],
];
