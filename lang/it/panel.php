<?php

declare(strict_types=1);

/*
 * I testi delle pagine di servizio dei pannelli del locale e
 * dell'organizzatore: statistiche, accesso, profilo, calendario collegato.
 */
return [
    'analytics' => [
        'consent_title' => 'Cosa contano questi numeri',
        'consent_links' => 'Le aperture dei link brevi (quelli che iniziano con /s/) si contano sempre: un totale anonimo per link e per giorno, senza cookie, senza indirizzo IP e senza sapere chi li apre.',
        'consent_views' => 'Visite alle schede, clic e condivisioni si contano solo per chi ha accettato le statistiche nel banner dei cookie: le persone reali sono di più di quelle che vedi.',
        'summary' => 'Riepilogo del periodo',
        'filters_hint' => 'Restringi il report a un locale, un organizzatore, un evento, un canale o a date precise.',
        'empty_title' => 'Ancora nessun dato in questo periodo',
        'empty_body' => 'Prova un periodo più lungo. Le visite compaiono solo da chi ha accettato le statistiche; le aperture dei link brevi si contano sempre.',
        'definitions' => 'Visite e azioni sulle schede si contano con il consenso alle statistiche e non sono persone uniche. Le aperture dei link brevi si contano anche senza consenso, come totale anonimo per giorno. I salvataggi e i follower del periodo sono quelli creati nel periodo e ancora presenti; i follower attuali sono il totale di oggi. Commenti e recensioni mostrano lo stato attuale di quelli creati nel periodo; la media voti usa solo le recensioni approvate. I biglietti sono quelli emessi nel periodo, anche se poi annullati. Gli ingressi seguono la data del check-in. Il canale non restringe visite, prenotazioni o altri dati che non passano da un link. I profili hanno misure proprie: un filtro evento seleziona i profili collegati senza trasformare le loro visite in visite dell’evento. Le date seguono il fuso della città. Le repliche mostrano le misure attribuite a una data precisa e non vanno sommate al totale dell’evento.',
        'not_collected' => 'Il sito non raccoglie IP, identità dei visitatori, provenienza geografica, dispositivo, consegna dei messaggi o acquisti su siti esterni: questi dati non esistono. Lo storico precedente all’attivazione dei contatori non si può ricostruire. «n.d.» indica un dato non disponibile o non autorizzato, non uno zero.',
        'share_notes' => 'I pulsanti WhatsApp, Telegram ed email misurano l’avvio della condivisione, non la consegna del messaggio; «Condividi» misura il completamento o la copia del link, solo con il consenso. Le aperture comprendono le visite ripetute, escludono anteprime, bot riconosciuti e caricamenti anticipati del browser e non sono persone uniche. Un link può essere inoltrato altrove: resta attribuito al canale che lo ha creato.',
        'coverage' => 'La raccolta interna parte dall’attivazione dei contatori: i giorni precedenti senza misure non indicano assenza di visite. Le visite comprendono quelle ripetute e non sono persone uniche.',
        'period' => 'Periodo',
        'profile_hint' => 'Le misure della scheda pubblica, separate da quelle dei singoli eventi.',
        'details_hint' => 'Tutte le azioni sulle schede degli eventi nel periodo.',
        'per_event_hint' => 'I numeri di ogni evento nel periodo. Su telefono la tabella scorre di lato.',
        'daily_table' => 'Giorno per giorno',
        'paid_hint' => 'Le esposizioni e i clic delle sponsorizzazioni, separati dalle visite spontanee.',
        'shares_title' => 'Link brevi e condivisioni',
        'shares_hint' => 'Ogni link breve con le volte in cui è stato condiviso e aperto. Le aperture si contano sempre, le condivisioni solo con il consenso.',
        'shares_open' => 'Apri le statistiche complete',
    ],
    'access' => [
        'role_title' => 'Il tuo ruolo',
        'role_body' => 'Il tuo ruolo in :venue:',
        'owner_can' => 'Puoi modificare la scheda del locale, invitare collaboratori e pubblicare gli eventi.',
        'editor_can' => 'Puoi pubblicare e modificare gli eventi. Scheda del locale e collaboratori li gestisce il referente.',
        'since' => 'Accesso dal :date',
        'danger_title' => 'Zona pericolosa',
        'danger_body' => 'Lasciando il locale perdi subito l’accesso a eventi, statistiche e grafiche. Per tornare serve un nuovo invito.',
        'last_owner' => 'Sei l’unico referente: per lasciare il locale scrivi alla redazione, che deciderà a chi passarlo. Intanto puoi invitare altri collaboratori.',
        'collaborators' => 'Vai ai collaboratori',
    ],
    'organizer_profile' => [
        'title' => 'Profilo pubblico',
        'lead' => 'Nome, responsabile e collaboratori li gestisce la redazione. Qui aggiorni la presentazione e i contatti che compaiono sulla tua scheda.',
        'presentation' => 'Presentazione',
        'presentation_hint' => 'Il testo che apre la tua scheda pubblica.',
        'description' => 'Presentazione',
        'contacts' => 'Contatti pubblici',
        'contacts_hint' => 'Come ti trova chi legge la scheda. Lascia vuoto quello che non vuoi mostrare.',
        'website' => 'Sito web',
        'email' => 'Email pubblica',
        'save' => 'Salva il profilo',
        'saved' => 'Profilo aggiornato',
        'view_public' => 'Guarda la scheda pubblica',
    ],
];
