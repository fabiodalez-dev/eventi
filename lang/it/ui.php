<?php

declare(strict_types=1);

/*
 * Interfaccia comune: intestazione, navigazione, piè di pagina, stati.
 *
 * Il nome del prodotto non compare mai qui: arriva sempre da
 * config('app.name') e viene passato come :app dove serve (D10).
 */
return [

    'skip_to_content' => 'Vai al contenuto',
    'breadcrumb' => 'Percorso',

    'cta' => [
        'label' => 'Partecipa al progetto',
        /* Va a capo dove sta il ritorno a capo: e' un titolo da manifesto, e
           la spezzatura fa parte del disegno. */
        'title' => 'Organizzi eventi?',
        'lead' => 'Organizzi qualcosa in città? Proponi la data e finisce in questo elenco. Gestisci un locale? Prenditi la tua pagina e pubblica da solo, senza passare da nessuno.',
    ],

    /*
     * L'apertura. Il titolo ha una parola in evidenza — quella che nel
     * riferimento e' in giallo-verde — e per questo e' spezzato in due chiavi
     * invece di essere una frase sola.
     */
    'hero' => [
        'title' => "A :city succede\n:accent.\nTu scegli cosa.",
        'title_light' => "A :city\nsuccede :accent.\nTu scegli cosa.",
        'title_accent' => 'tutto',
        'lead' => 'Concerti, serate, mostre, teatro, mercati, sport. Tutto quello che apre le porte in città, in un posto solo. Filtra per stasera, per zona, per quanto vuoi spendere — e vai.',
        'explore' => 'Esplora l\'unico evento|Esplora :count eventi',
        'open_map' => 'Apri la mappa',
        'open_map_full' => 'Apri la mappa a schermo intero',
    ],

    /*
     * La fascia dei numeri sotto l'apertura: sono le misure di §1 del piano,
     * quelle con cui si risponde a «apro il sito e trovo qualcosa da fare?».
     */
    'stats' => [
        'label' => 'Il catalogo in numeri',
        'week' => 'Eventi nei prossimi 7 giorni',
        'venues' => 'Locali sulla mappa',
        'categories' => 'Categorie con date in programma',
        'updated' => 'Dall\'ultimo aggiornamento',
        'updated_ago' => 'aggiornato :ago',
        'in_town' => 'un evento in programma|:count eventi in programma',
    ],

    'header' => [
        'submit_event' => 'Aggiungi evento',
        'home' => 'Torna alla pagina iniziale di :app',
        'city_label' => 'Città',
        'change_city' => 'Cambia città',
        'today_is' => 'Oggi è :date',
        'search_placeholder' => 'Cerca un evento, un locale, un artista',
        'search_label' => 'Cerca fra gli eventi di :city',
        'tagline' => 'Cosa fare stasera a :city',
    ],

    'nav' => [
        'label' => 'Navigazione principale',
        'home' => 'Home',
        'events' => 'Eventi',
        'today' => 'Oggi',
        'tonight' => 'Stasera',
        'tomorrow' => 'Domani',
        'weekend' => 'Weekend',
        'free' => 'Gratis',
        'map' => 'Mappa',
        'calendar' => 'Calendario',
        'venues' => 'Locali',
        'search' => 'Cerca',
        'saved' => 'Salvati',
        'account' => 'Il mio profilo',
    ],

    /*
     * La vista degli elenchi sul telefono (`<x-card-layout-toggle>`).
     */
    'card_layout' => [
        'label' => 'Vista',
        'full' => 'Card grandi',
        'grid' => 'Griglia a due colonne',
        'list' => 'Elenco compatto',
    ],

    /*
     * Il nastro scorrevole della testata: dice quante date ci sono stasera e
     * quali stanno per cominciare. Sono frasi al plurale variabile perche' con
     * una sola data «1 eventi» sarebbe sciatto proprio nel punto piu' visibile
     * del sito.
     */
    'ticker' => [
        'tonight' => '{1} Un evento stasera in città|[2,*] :count eventi stasera in città',
        'free_today' => '{1} Un evento gratuito oggi|[2,*] :count eventi gratuiti oggi',
        'added_today' => '{1} Un evento aggiunto oggi|[2,*] :count eventi aggiunti oggi',
    ],

    'footer' => [
        'label' => 'Piè di pagina',
        'about_title' => 'Il progetto',
        'about_body' => ':app raccoglie gli eventi di :city e provincia in un posto solo, aggiornati dai locali che li organizzano.',
        'discover_title' => 'Scopri',
        'venues_title' => 'Per i locali',
        /* Le voci di questo gruppo sono i titoli delle pagine pubblicate
           (tabella `pages`), non stringhe: la redazione le rinomina senza
           passare da qui. */
        'legal_title' => 'Informazioni',
        'submit_event' => 'Proponi un evento',
        'register_venue' => 'Registra il tuo locale',
        'venue_login' => 'Accedi al pannello del locale',
        'feeds_title' => 'Restare aggiornati',
        'calendar_feed' => 'Calendario iCal',
        'rss_feed' => 'Feed RSS',
        'newsletter' => 'Newsletter del weekend',
        'map_attribution' => 'Dati cartografici © :osm contributors, licenza :license',
        'osm' => 'OpenStreetMap',
        'odbl' => 'ODbL',
        'copyright' => '© :year :app',
    ],

    'pagination' => [
        'label' => 'Paginazione',
        'previous' => 'Precedente',
        'next' => 'Successiva',
        'page' => 'Pagina :page',
        'page_of' => 'Pagina :page di :last',
        'go_to_page' => 'Vai alla pagina :page',
        'current_page' => 'Pagina corrente, pagina :page',
        'showing' => 'Da :first a :last di :total risultati',
    ],

    'empty_state' => [
        'default_title' => 'Non c\'è niente da mostrare',
    ],

    'errors' => [
        '404_title' => 'Questa pagina non esiste',
        '404_body' => 'Il link potrebbe essere vecchio, oppure l\'evento è stato rimosso.',
        '500_title' => 'Qualcosa si è rotto da parte mia',
        '500_body' => 'Riprova fra qualche minuto.',
        'back_home' => 'Torna alla home',
    ],

    'theme' => [
        'light' => 'Tema chiaro',
        'dark' => 'Tema scuro',
    ],

    /* Le pagine di servizio (`<x-service-page>` e i suoi componenti). */
    'service' => [
        'back_profile' => 'Il mio profilo',
        'form_errors_title' => 'C\'è un dato da correggere|Ci sono :count dati da correggere',
    ],

    /* La pagina campione dei componenti di servizio (solo sviluppo e test). */
    'design_sample' => [
        'title' => 'Pagine di servizio',
        'subtitle' => 'Tutti i pezzi con cui si costruiscono area personale, moduli, biglietti, community e passaggi.',
        'eyebrow' => 'Sistema grafico',
        'back' => 'Prima pagina',
        'tabs_label' => 'Sezioni della pagina campione',
        'tab_components' => 'Componenti',
        'tab_forms' => 'Moduli',
        'tab_lists' => 'Elenchi',
        'tab_numbers' => 'Numeri',
        'primary' => 'Azione principale',
        'secondary' => 'Azione secondaria',
        'danger' => 'Elimina',
        'ghost' => 'Collegamento',
        'small' => 'Piccolo',
        'notice_info_title' => 'Da sapere',
        'notice_info_body' => 'Un avviso informativo: spiega qualcosa prima che serva.',
        'notice_success_body' => 'Modifiche salvate.',
        'notice_alert_title' => 'Attenzione',
        'notice_alert_body' => 'Questa azione non si può annullare.',
        'buttons_title' => 'Pulsanti',
        'buttons_description' => 'Pieno per l\'azione principale, con bordo per le alternative, rosso per ciò che distrugge, solo testo per la navigazione secondaria.',
        'form_title' => 'Un modulo',
        'form_description' => 'Un campo per riga, l\'errore accanto al campo, i pulsanti in fondo.',
        'field_name' => 'Nome',
        'field_name_hint' => 'Come vuoi comparire.',
        'field_email' => 'Email',
        'field_notes' => 'Note',
        'save' => 'Salva',
        'cancel' => 'Annulla',
        'rows_title' => 'Righe in una card',
        'row_saved' => 'I miei salvataggi',
        'row_saved_hint' => 'Le date che hai messo da parte.',
        'row_tickets' => 'I miei biglietti',
        'row_new' => 'Nuovo',
        'row_person' => 'Giulia Rossi',
        'row_person_hint' => '12 partecipazioni',
        'follow' => 'Segui',
        'events_title' => 'Eventi con locandina',
        'events_description' => 'Ogni elenco di eventi mostra la locandina, o un segnaposto della stessa misura.',
        'segmented_label' => 'Quali eventi',
        'upcoming' => 'Prossimi',
        'past' => 'Passati',
        'calendar' => 'Calendario',
        'confirmed' => 'Confermata',
        'details' => 'Dettagli',
        'cancel_booking' => 'Annulla',
        'no_poster' => 'Riga piccola, senza locandina',
        'empty_title' => 'Nessun evento in programma',
        'empty_description' => 'Quando ci saranno date future compariranno qui.',
        'stats_title' => 'Numeri',
        'stat_confirmed' => 'Confermati',
        'stat_confirmed_hint' => 'su 50 posti',
        'stat_waitlist' => 'In lista d\'attesa',
        'stat_checked_in' => 'Entrati',
        'stat_capacity' => 'Posti',
        'danger_title' => 'Zona pericolosa',
        'danger_description' => 'Le azioni che non si possono annullare stanno in una card a parte.',
        'leave' => 'Lascia il locale',
    ],

    /* Le colonne comuni dei pannelli. */
    'panel' => [
        'poster' => 'Locandina',
    ],

    /* La pagina «Aspetto». */
    'appearance_page' => [
        'title' => 'Aspetto',
        'subtitle' => 'Scegli la luce giusta per i tuoi eventi.',
        'theme_title' => 'Tema del sito',
        'theme_description' => 'La scelta vale su tutte le pagine del sito.',
        'back_events' => 'Torna agli eventi',
    ],

    /* L'avviso che compare quando l'elenco degli eventi segue i tuoi interessi. */
    'content_filter' => [
        'notice' => 'Stai esplorando gli eventi secondo i tuoi interessi.',
        'change' => 'Modifica o mostra tutto',
    ],

];
