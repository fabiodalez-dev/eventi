<?php

declare(strict_types=1);

return [

    'venue_type' => [
        'bar' => 'Bar',
        'pub' => 'Pub',
        'circolo' => 'Circolo',
        'centro_sociale' => 'Centro sociale',
        'club' => 'Club',
        'teatro' => 'Teatro',
        'cinema' => 'Cinema',
        'libreria' => 'Libreria',
        'associazione' => 'Associazione',
        'galleria' => 'Galleria',
        'spazio_pubblico' => 'Spazio pubblico',
        'ristorante' => 'Ristorante',
        'altro' => 'Altro',
    ],

    'venue_status' => [
        'draft' => 'Bozza',
        'pending' => 'In attesa di approvazione',
        'approved' => 'Approvato',
        'suspended' => 'Sospeso',
        'rejected' => 'Rifiutato',
    ],

    'venue_role' => [
        'owner' => 'Referente',
        'editor' => 'Collaboratore',
    ],

    'venue_plan' => [
        'free' => 'Gratuito',
        'premium' => 'Premium',
    ],

    'application_status' => [
        'pending' => 'In attesa',
        'approved' => 'Approvata',
        'rejected' => 'Rifiutata',
    ],

    'event_status' => [
        'draft' => 'Bozza',
        'pending' => 'In attesa di approvazione',
        'published' => 'Pubblicato',
        'rejected' => 'Rifiutato',
        'cancelled' => 'Annullato',
        'archived' => 'Archiviato',
    ],

    'event_source' => [
        'manual' => 'Inserimento redazionale',
        'venue' => 'Inserito dal locale',
        'submission' => 'Proposto dal pubblico',
        'import_ics' => 'Importato da calendario ICS',
        'import_api' => 'Importato da API',
    ],

    'verification_status' => [
        'unverified' => 'Non verificato',
        'venue_confirmed' => 'Confermato dal locale',
        'editorial_checked' => 'Verificato dalla redazione',
    ],

    'price_type' => [
        'free' => 'Ingresso libero',
        'donation' => 'Offerta libera',
        'ticket' => 'Biglietto',
        'membership' => 'Riservato ai soci',
        'unknown' => 'Prezzo non indicato',
    ],

    /* Preset del filtro data (§11.3). `phrase` è la forma da infilare in un
       titolo: "Concerti gratis stasera a Padova". */
    'date_preset' => [
        'today' => 'Oggi',
        'tonight' => 'Stasera',
        'tomorrow' => 'Domani',
        'weekend' => 'Weekend',
        'week' => 'Questa settimana',
        'starting_soon' => 'Inizia tra poco',
        'ongoing' => 'In corso adesso',
    ],

    'date_preset_phrase' => [
        'today' => 'oggi',
        'tonight' => 'stasera',
        'tomorrow' => 'domani',
        'weekend' => 'questo weekend',
        'week' => 'questa settimana',
        'starting_soon' => 'che iniziano tra poco',
        'ongoing' => 'in corso adesso',
    ],

    'price_filter' => [
        'free' => 'Gratis',
        'donation' => 'Offerta libera',
        'max10' => 'Fino a 10 €',
        'max20' => 'Fino a 20 €',
    ],

    'price_filter_phrase' => [
        'free' => 'gratis',
        'donation' => 'a offerta libera',
        'max10' => 'fino a 10 €',
        'max20' => 'fino a 20 €',
    ],

    'event_sort' => [
        'time' => 'Orario',
        'relevance' => 'Rilevanza',
        'distance' => 'Distanza',
    ],

    'time_of_day' => [
        'day' => 'Di giorno',
        'evening' => 'Di sera',
        'night' => 'Di notte',
    ],

    'occurrence_status' => [
        'scheduled' => 'In programma',
        'cancelled' => 'Annullato',
        'sold_out' => 'Esaurito',
        'postponed' => 'Rinviato',
        'moved' => 'Spostato',
    ],

    'lineup_role' => [
        'live' => 'Live',
        'dj' => 'DJ set',
        'opening' => 'Apertura',
        'special_guest' => 'Ospite speciale',
        'speaker' => 'Relatore',
    ],

    'import_source_type' => [
        'ics' => 'Calendario ICS',
        'json' => 'JSON',
        'rss' => 'Feed RSS',
        'api' => 'API',
        'manual' => 'Manuale',
    ],

    'report_reason' => [
        'wrong_info' => 'Informazioni errate',
        'duplicate' => 'Doppione',
        'spam' => 'Spam',
        'offensive' => 'Contenuto offensivo',
        'cancelled' => 'Evento annullato',
        'copyright' => 'Violazione di copyright',
    ],

    'report_status' => [
        'pending' => 'Da esaminare',
        'reviewing' => 'In esame',
        'resolved' => 'Risolta',
        'dismissed' => 'Archiviata',
    ],

    'notification_channel' => [
        'mail' => 'Email',
        'push' => 'Notifica push',
        'database' => 'Archivio in app',
    ],

    'notification_type' => [
        'event_reminder' => 'Promemoria evento salvato',
        'event_cancelled' => 'Evento annullato',
        'event_moved' => 'Evento spostato',
        'event_sold_out' => 'Biglietti esauriti',
        'venue_digest' => 'Riepilogo settimanale da chi segui',
        'daily_digest' => 'Riepilogo giornaliero',
        'weekend_newsletter' => 'Newsletter del weekend',
        'event_published' => 'Evento pubblicato',
        'event_rejected' => 'Evento rifiutato',
        'venue_inactive' => 'Locale inattivo',
    ],

    'notification_skip_reason' => [
        'account_deleted' => 'Account cancellato',
        'unverified' => 'Indirizzo non verificato',
        'preference_off' => 'Tipologia disattivata',
        'frequency_cap' => 'Tetto giornaliero raggiunto',
        'occurrence_past' => 'Data già passata',
        'occurrence_cancelled' => 'Data annullata',
        'not_saved' => 'Data non più salvata',
        'nothing_to_send' => 'Niente da riepilogare',
        'missing_subject' => 'Contenuto non più disponibile',
    ],

    'notification_status' => [
        'pending' => 'In coda',
        'sent' => 'Inviata',
        'skipped' => 'Saltata',
        'failed' => 'Non riuscita',
        'cancelled' => 'Annullata',
    ],

    'submission_status' => [
        'pending' => 'Da esaminare',
        'approved' => 'Approvata',
        'rejected' => 'Rifiutata',
    ],

    'followable_type' => [
        'venue' => 'Locale',
        'tag' => 'Tag',
        'category' => 'Categoria',
        'event' => 'Evento',
    ],

    'device_platform' => [
        'ios' => 'iOS',
        'android' => 'Android',
        'web' => 'Web',
    ],

    'user_role' => [
        'user' => 'Utente',
        'venue_owner' => 'Referente locale',
        'venue_editor' => 'Collaboratore locale',
        'moderator' => 'Moderatore',
        'admin' => 'Amministratore',
        'super_admin' => 'Amministratore di sistema',
    ],

    'permission' => [
        'venues.view' => 'Vedere i locali',
        'venues.create' => 'Creare locali',
        'venues.update' => 'Modificare i locali',
        'venues.delete' => 'Cancellare i locali',
        'venues.manage_collaborators' => 'Gestire i collaboratori del locale',
        'venues.view_sensitive_data' => 'Vedere i dati sensibili del locale',
        'venues.moderate' => 'Approvare, sospendere o rifiutare i locali',

        'events.view' => 'Vedere gli eventi',
        'events.create' => 'Creare eventi',
        'events.update' => 'Modificare gli eventi',
        'events.delete' => 'Cancellare gli eventi',
        'events.publish' => 'Pubblicare gli eventi',
        'events.moderate' => 'Approvare o rifiutare gli eventi',

        'venue_applications.view' => 'Vedere le richieste di accreditamento',
        'venue_applications.review' => 'Valutare le richieste di accreditamento',

        'reports.view' => 'Vedere le segnalazioni',
        'reports.resolve' => 'Risolvere le segnalazioni',

        'categories.manage' => 'Gestire le categorie',
        'tags.manage' => 'Gestire i tag',
        'tags.approve' => 'Approvare i tag',
        'cities.manage' => 'Gestire le città',

        'import_sources.manage' => 'Gestire le sorgenti di import',

        'notifications.view' => 'Vedere le notifiche programmate',
        'notifications.manage' => 'Annullare le notifiche programmate',

        'users.manage' => 'Gestire gli account e i ruoli',
        'users.impersonate' => 'Navigare il sito come un altro utente',
    ],

    /* Ogni quanto si ripete una serata, detto come lo direbbe chi la organizza
       (§10.4): il gestore non vede mai la sintassi RRULE che sta sotto. */
    'recurrence_frequency' => [
        'daily' => 'Ogni giorno',
        'weekly' => 'Ogni settimana',
        'biweekly' => 'Una settimana sì e una no',
        'monthly' => 'Ogni mese',
    ],

    /* Le tre finestre delle statistiche del locale (§10.5). Una chiave sola:
       i giorni sono un parametro, non tre traduzioni diverse. */
    'stats_period' => [
        'days' => ':days giorni',
    ],

];
