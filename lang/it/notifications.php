<?php

declare(strict_types=1);

/*
 * I testi delle notifiche (§15.4) e delle due pagine che ogni email deve poter
 * raggiungere: la disiscrizione e le preferenze (§15.9).
 *
 * Nessuna di queste stringhe sta nel codice: il tono di una notifica si
 * corregge molto più spesso della logica che la manda, e chi lo corregge non
 * deve aprire una classe PHP per farlo.
 */
return [

    'actions' => [
        'open_event' => 'Apri la scheda evento',
        'open_feed' => 'Apri il mio feed',
        'open_tonight' => 'Guarda cosa c\'è oggi',
        'open_weekend' => 'Guarda il weekend',
        'open_panel' => 'Apri il pannello',
    ],

    'common' => [
        'at_venue' => 'Da :venue, :municipality.',
        'greeting' => 'Ciao :name,',
        'reason' => 'Il motivo: :reason',
    ],

    /*
     * Le tre decisioni sulla scheda di un locale.
     *
     * Il tono cambia con l'esito, ed e' voluto. L'approvazione dice cosa fare
     * adesso; il rifiuto e la sospensione dicono cos'e' successo e come se ne
     * esce — perche' un'email che comunica una porta chiusa senza indicare a
     * chi rivolgersi produce una risposta che qualcuno dovra' comunque
     * leggere.
     */
    'venue_approved' => [
        'subject' => ':venue è online su :product',
        'line' => 'La scheda di :venue è stata approvata: da adesso è visibile sul sito e puoi pubblicare i tuoi eventi.',
        'why' => 'Ricevi questa email perché gestisci questa scheda.',
    ],

    'venue_rejected' => [
        'subject' => 'La richiesta per :venue non è stata accolta',
        'line' => 'Abbiamo esaminato la scheda di :venue e non possiamo pubblicarla così com\'è.',
        'why' => 'Se pensi che ci sia un errore, rispondi a questa email: la leggiamo.',
    ],

    'venue_suspended' => [
        'subject' => ':venue è stato sospeso',
        'line' => 'La scheda di :venue non è più visibile sul sito, e con essa i suoi eventi. È una sospensione, non una cancellazione: si può riattivare.',
        'why' => 'Per riattivarla, rispondi a questa email.',
    ],

    'reminder' => [
        'subject' => ':when: :title',
        'when_tomorrow' => 'Domani',
        'when_hours' => '{1} Fra un\'ora|[2,*] Fra :count ore',
        'line' => 'Comincia :when.',
        'why' => 'Ricevi questo promemoria perché hai salvato questa data.',
    ],

    'cancelled' => [
        'subject' => 'Annullato: :title',
        'heading' => ':title è stato annullato',
        'line' => 'La data del :when non si terrà.',
        'note' => 'Il locale ha aggiunto: :note',
        'why' => 'Ti avvisiamo perché avevi questa data in agenda: gli annullamenti arrivano sempre.',
    ],

    'moved' => [
        'subject' => 'Nuovo orario: :title',
        'heading' => ':title cambia orario',
        'previous' => 'Era in programma :when.',
        'line' => 'Ora comincia :when.',
        'why' => 'Ti avvisiamo perché avevi questa data in agenda: gli spostamenti arrivano sempre.',
    ],

    'sold_out' => [
        'subject' => 'Sold out: :title',
        'heading' => ':title ha esaurito i biglietti',
        'line' => 'La data del :when risulta esaurita.',
        'why' => 'Ricevi questo avviso perché hai salvato questa data.',
    ],

    'venue_digest' => [
        'subject' => 'Novità da chi segui',
        'heading' => 'Le novità della settimana',
        'line' => 'Sono comparse :count nuove date fra locali, generi ed etichette che segui.',
    ],

    'daily_digest' => [
        'subject' => 'Stasera in città',
        'heading' => 'Stasera',
        'line' => ':count date che cominciano stasera.',
    ],

    'weekend' => [
        'subject' => 'Questo weekend',
        'heading' => 'Il tuo weekend',
        'line' => ':count date fra sabato e domenica.',
    ],

    'event_published' => [
        'subject' => 'Pubblicato: :title',
        'heading' => ':title è online',
        'line' => 'La scheda è visibile a tutti e compare nelle liste della città.',
    ],

    'event_rejected' => [
        'subject' => 'Non pubblicato: :title',
        'heading' => ':title non è stato pubblicato',
        'reason' => 'Motivo: :reason',
        'line' => 'Puoi correggere la scheda dal pannello e riproporla.',
    ],

    'venue_inactive' => [
        'subject' => ':venue non pubblica da un po\'',
        'heading' => 'Nessun evento pubblicato di recente',
        'line' => 'Sono passati più di :days giorni dall\'ultima pubblicazione. Una data in programma tiene la scheda viva nelle liste.',
    ],

    'mail' => [
        'link_fallback' => 'Se il pulsante non funziona, copia questo indirizzo nel browser:',
        'unsubscribe' => 'Non voglio più queste email',
        'preferences' => 'Gestisci le notifiche',
        'reason' => [
            'event_reminder' => 'Ricevi questa email perché hai salvato una data su :product.',
            'event_cancelled' => 'Ricevi questa email perché avevi salvato questa data. Gli annullamenti non si disattivano.',
            'event_moved' => 'Ricevi questa email perché avevi salvato questa data. Gli spostamenti non si disattivano.',
            'event_sold_out' => 'Ricevi questa email perché hai salvato questa data.',
            'venue_digest' => 'Ricevi questo riepilogo perché segui locali, generi o etichette su :product.',
            'daily_digest' => 'Ricevi questo riepilogo perché hai acceso il riassunto giornaliero.',
            'weekend_newsletter' => 'Ricevi questa newsletter perché hai dato il consenso a riceverla.',
            'event_published' => 'Ricevi questa email perché gestisci il locale a cui appartiene questo evento.',
            'event_rejected' => 'Ricevi questa email perché gestisci il locale a cui appartiene questo evento.',
            'venue_inactive' => 'Ricevi questa email perché sei il referente di questo locale.',
        ],
    ],

    'preferences' => [
        'title' => 'Le tue notifiche',
        'lead' => 'Da qui decidi cosa ricevere. Non serve accedere: questo indirizzo vale solo per te.',
        'saved' => 'Preferenze aggiornate.',
        'mandatory' => 'Gli avvisi di annullamento e di cambio orario delle date che hai salvato arrivano sempre: sono la ragione per cui salvare una data serve a qualcosa.',
        'submit' => 'Salva le preferenze',
        'account' => 'Hai un account: dal profilo puoi cambiare anche fuso orario, lingua e ore di silenzio.',
    ],

    /*
     * L'interruttore delle notifiche push (§15.6, D54).
     *
     * I messaggi di stato sono quattro perche' quattro sono gli esiti che chi
     * tocca l'interruttore puo' vedere, e ognuno chiede una cosa diversa:
     * fatto, rifiutato (e allora la strada e' nelle impostazioni del browser,
     * non qui), non supportato, andato storto. Un solo «errore» li
     * confonderebbe tutti, e il piu' frequente — il permesso negato — non e'
     * nemmeno un errore.
     */
    'push' => [
        'title' => 'Notifiche sul dispositivo',
        'lead' => 'Attiva questo dispositivo e scegli il canale di consegna qui sopra. In modalità automatica usiamo le email se non hai dispositivi attivi negli ultimi 30 giorni.',
        'toggle' => 'Attiva le notifiche su questo dispositivo',
        'ios' => 'Su iPhone e iPad funzionano solo se aggiungi il sito alla schermata Home.',
        'unavailable' => 'Le notifiche web su questo dispositivo non sono attualmente disponibili. Puoi scegliere Email come canale di consegna.',
        'signed_out' => 'Per attivarle su questo dispositivo devi accedere: da un collegamento ricevuto per email si possono solo spegnere.',
        'on' => 'Attive su questo dispositivo.',
        'off' => 'Spente su questo dispositivo. La consegna dipende dal canale scelto qui sopra.',
        'denied' => 'Il browser ha bloccato le notifiche per questo sito: si riattivano dalle sue impostazioni.',
        'unsupported' => 'Questo browser non supporta le notifiche. Scegli Email per ricevere gli avvisi via posta.',
        'failed' => 'Non è stato possibile attivarle. Riprova più tardi oppure scegli Email come canale di consegna.',
        'enabled' => 'Notifiche attivate su questo dispositivo.',
        'disabled' => 'Notifiche disattivate su questo dispositivo.',
        'unauthenticated' => 'Sessione scaduta: accedi di nuovo.',
    ],

    'unsubscribed' => [
        'title' => 'Fatto',
        'body' => 'Non riceverai più: :type.',
        'mandatory' => 'Questa tipologia non si può disattivare: riguarda le date che hai salvato tu.',
        'undo' => 'Cambia le altre preferenze',
    ],

];
