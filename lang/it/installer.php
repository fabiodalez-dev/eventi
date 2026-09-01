<?php

declare(strict_types=1);

return [

    /*
     * Il wizard di installazione (D42). Ogni testo che compare nelle sette
     * schermate passa da qui, come ogni altro testo del progetto: l'installer
     * non è un'eccezione alla regola solo perché lo si legge una volta sola.
     */

    'title' => 'Installazione',
    'app' => 'inCittà',
    'progress' => 'Passo :number di :total',
    'skip_notice' => 'Sei tornato al primo passo non ancora completato.',

    'actions' => [
        'continue' => 'Continua',
        'back' => 'Torna indietro',
        'retry' => 'Riprova',
        'recheck' => 'Ricontrolla',
        'run' => 'Esegui questa operazione',
        'finish' => 'Concludi l’installazione',
        'open_site' => 'Vai al sito',
        'open_admin' => 'Entra nel pannello',
        'copy_hint' => 'Riga da eseguire in un terminale, sul server:',
    ],

    'steps' => [
        'requisiti' => [
            'title' => 'Benvenuto',
            'intro' => 'Prima di cominciare controllo che il server abbia tutto quello che serve. Ciò che manca è indicato qui sotto, con accanto come rimediare.',
        ],
        'database' => [
            'title' => 'Database',
            'intro' => 'Le coordinate del database. Le provo davvero prima di scrivere qualsiasi cosa: se qualcosa non torna lo scopriamo adesso e non a metà installazione.',
        ],
        'applicazione' => [
            'title' => 'Applicazione',
            'intro' => 'Come si chiama il sito, a quale indirizzo risponde e da dove parte la posta.',
        ],
        'citta' => [
            'title' => 'Città',
            'intro' => 'La città di cui il sito racconta gli eventi. Senza, il sito non ha niente da mostrare: se ne potranno aggiungere altre dal pannello.',
        ],
        'amministratore' => [
            'title' => 'Amministratore',
            'intro' => 'L’account con cui entrerai nel pannello di redazione. È l’unico che esisterà a installazione finita.',
        ],
        'esecuzione' => [
            'title' => 'Esecuzione',
            'intro' => 'Le operazioni da fare, una alla volta. Ognuna si può ripetere senza danno: se una si ferma, si riprende da lì.',
        ],
        'fine' => [
            'title' => 'Fatto',
            'intro' => 'Il sito è installato. Restano due righe da mettere nel cron: senza quelle, metà delle funzioni non parte.',
        ],
    ],

    'requirements' => [
        'blocked' => 'Manca qualcosa senza cui il sito non funzionerebbe. Risolvi le voci in rosso, poi ricontrolla.',
        'blocking_heading' => 'Indispensabili',
        'advisory_heading' => 'Da sapere',
        'advisory_intro' => 'Queste voci non fermano l’installazione. Ognuna dice quale funzione perderesti.',
        'status' => [
            'ok' => 'a posto',
            'avviso' => 'avviso',
            'bloccante' => 'manca',
        ],
        'items' => [
            'php' => [
                'label' => 'PHP :required o superiore (adesso: :current)',
                'hint' => 'Su cPanel la versione si cambia in MultiPHP Manager. Su altri pannelli cerca “versione PHP”. Ricordati di cambiarla anche per la riga di comando, non solo per il web.',
            ],
            'extension' => [
                'label' => 'Estensione :extension',
                'hint' => 'Senza questa estensione l’applicazione non parte. Su cPanel si accende da MultiPHP INI Editor, sezione estensioni; su un server tuo con il pacchetto php-:extension.',
            ],
            'image_engine' => [
                'label' => 'Un motore per le immagini (imagick oppure gd)',
                'hint' => 'Senza nessuno dei due le locandine non si possono ridimensionare, e le locandine sono il prodotto. Accendi imagick, o almeno gd, dalle estensioni PHP.',
            ],
            'writable_directory' => [
                'label' => 'Cartella :path scrivibile',
                'hint' => 'Ho provato a sistemare i permessi da solo e non ci sono riuscito. Va fatto a mano, con il comando qui sotto.',
            ],
            'writable_env' => [
                'label' => 'File :path scrivibile',
                'hint' => 'L’installatore esiste per scrivere questo file: senza permesso di scrittura non può fare nulla. Se il file non esiste ancora, deve essere scrivibile la cartella che lo conterrà.',
            ],
            'imagick' => [
                'label' => 'Estensione imagick',
                'hint' => 'Senza imagick uso gd: il sito funziona, ma non accetterà locandine in HEIC e non produrrà varianti AVIF. Si può accendere anche dopo, cambiando IMAGE_DRIVER nel file .env.',
            ],
            'optional_exif' => [
                'label' => 'Estensione exif',
                'hint' => 'Senza exif le foto scattate col telefono restano ruotate come sono state salvate, invece che come sono state viste.',
            ],
            'optional_zip' => [
                'label' => 'Estensione zip',
                'hint' => 'Senza zip i backup automatici non si creeranno: l’archivio si costruisce con ZipArchive. Tutto il resto funziona.',
            ],
            'symlink' => [
                'label' => 'Collegamenti simbolici disponibili',
                'hint' => 'La funzione symlink() è disattivata. Le immagini caricate non si vedranno finché la cartella public/storage non viene creata a mano.',
            ],
            'upload_size' => [
                'label' => ':directive almeno :required (adesso: :current)',
                'hint' => 'Le locandine possono arrivare a :required. Con il valore attuale i file più grandi verranno rifiutati dal server prima ancora di arrivare al sito. Su cPanel si cambia da MultiPHP INI Editor.',
            ],
        ],
    ],

    'database' => [
        'fields' => [
            'db_host' => 'host',
            'db_port' => 'porta',
            'db_database' => 'nome del database',
            'db_username' => 'utente',
            'db_password' => 'password',
        ],
        'labels' => [
            'db_host' => 'Host',
            'db_port' => 'Porta',
            'db_database' => 'Nome del database',
            'db_username' => 'Utente',
            'db_password' => 'Password',
        ],
        'help' => [
            'db_host' => 'Quasi sempre 127.0.0.1. Su alcuni hosting è un indirizzo dedicato: lo trovi accanto al database nel pannello.',
            'db_port' => '3306 per MySQL e MariaDB, salvo diversa indicazione del tuo hosting.',
            'db_database' => 'Solo lettere, cifre e trattini bassi. Se non esiste provo a crearlo io.',
            'db_password' => 'Lasciala vuota solo se l’utente non ne ha una.',
        ],
        'created' => 'Il database :database non esisteva e l’ho creato.',
        'errors' => [
            'unreachable' => 'Nessuna risposta dal server del database a quell’indirizzo e su quella porta. Controlla host e porta: sulla maggior parte degli hosting condivisi il valore giusto è 127.0.0.1.',
            'credentials' => 'Il server del database risponde, ma rifiuta utente e password. Controllali nel pannello dell’hosting: su cPanel e Plesk il nome dell’utente ha quasi sempre un prefisso, e va scritto per intero.',
            'database_missing' => 'Le credenziali vanno bene, ma il database non esiste e questo utente non ha il permesso di crearlo. Crealo dal pannello dell’hosting con esattamente questo nome, poi riprova.',
            'database_refused' => 'Il server risponde e le credenziali vanno bene, ma questo utente non può usare quel database. Nel pannello dell’hosting verifica che l’utente sia associato al database con tutti i permessi.',
            'database_name_invalid' => 'Il nome del database può contenere solo lettere, cifre e trattini bassi.',
        ],
    ],

    'application' => [
        'fields' => [
            'app_name' => 'nome del sito',
            'app_url' => 'indirizzo',
            'mail_mailer' => 'invio della posta',
            'mail_host' => 'server SMTP',
            'mail_port' => 'porta SMTP',
            'mail_scheme' => 'cifratura',
            'mail_username' => 'utente SMTP',
            'mail_password' => 'password SMTP',
            'mail_from_address' => 'indirizzo mittente',
            'ops_alert_email' => 'email per gli allarmi',
        ],
        'labels' => [
            'app_name' => 'Nome del sito',
            'app_url' => 'Indirizzo del sito',
            'mail_mailer' => 'Invio della posta',
            'mail_host' => 'Server SMTP',
            'mail_port' => 'Porta SMTP',
            'mail_scheme' => 'Cifratura',
            'mail_username' => 'Utente SMTP',
            'mail_password' => 'Password SMTP',
            'mail_from_address' => 'Indirizzo mittente',
            'ops_alert_email' => 'Email per gli allarmi di esercizio',
        ],
        'help' => [
            'app_name' => 'Compare nell’intestazione, nei messaggi e nelle anteprime condivise.',
            'app_url' => 'Con https:// davanti, senza barra finale. L’ho precompilato con l’indirizzo da cui stai leggendo.',
            'mail_mailer' => 'Con “registro nel log” il sito non spedisce niente e scrive i messaggi in storage/logs: va bene per provare, non per andare in linea.',
            'mail_scheme' => 'Lascia “automatica” salvo che il tuo provider chieda la porta 465.',
            'mail_from_address' => 'Se la lasci vuota uso l’email dell’amministratore.',
            'ops_alert_email' => 'Ci arrivano backup falliti, controlli di stato rossi e comandi programmati che hanno smesso di girare. Se resta vuota, nessun allarme parte.',
        ],
        'mailers' => [
            'log' => 'Registra nel log, non spedisce',
            'smtp' => 'Server SMTP',
        ],
        'schemes' => [
            'auto' => 'Automatica (consigliata)',
            'smtps' => 'TLS implicito (porta 465)',
        ],
        'production_note' => 'APP_ENV e APP_DEBUG non li chiedo: li scrivo io su “produzione” e “spento”. Con il debug acceso, una pagina di errore mostrerebbe a chiunque passi le credenziali del database.',
    ],

    'city' => [
        'fields' => [
            'name' => 'nome',
            'slug' => 'indirizzo',
            'province_code' => 'sigla della provincia',
            'province_name' => 'provincia',
            'region' => 'regione',
            'timezone' => 'fuso orario',
            'center_lat' => 'latitudine',
            'center_lng' => 'longitudine',
            'radius_km' => 'raggio',
        ],
        'labels' => [
            'name' => 'Nome della città',
            'slug' => 'Indirizzo (facoltativo)',
            'province_code' => 'Sigla della provincia',
            'province_name' => 'Nome della provincia',
            'region' => 'Regione',
            'timezone' => 'Fuso orario',
            'center_lat' => 'Latitudine del centro',
            'center_lng' => 'Longitudine del centro',
            'radius_km' => 'Raggio in chilometri',
        ],
        'help' => [
            'slug' => 'Lascialo vuoto e lo ricavo dal nome. È il pezzo di indirizzo che comparirà negli URL.',
            'province_code' => 'Due lettere, come PD o MI.',
            'timezone' => 'Determina che cosa significa “stasera”: tutte le date del sito si leggono in questo fuso.',
            'coordinates' => 'Le coordinate servono a “vicino a me” e alla mappa. Non interrogo alcun servizio esterno per trovarle: cercale tu con il collegamento qui sotto e incollale nei due campi.',
            'radius_km' => 'Fin dove si spinge il sito attorno al centro. Trenta chilometri è un buon punto di partenza.',
        ],
        'osm' => [
            'link' => 'Cerca le coordinate su OpenStreetMap',
            'note' => 'Il collegamento parte solo se lo apri tu. Cerca il comune, poi copia latitudine e longitudine che compaiono nell’indirizzo della pagina, nella forma #map=12/45.4064/11.8768.',
        ],
    ],

    'admin' => [
        'fields' => [
            'name' => 'nome',
            'email' => 'email',
            'password' => 'password',
        ],
        'labels' => [
            'name' => 'Nome e cognome',
            'email' => 'Email',
            'password' => 'Password',
            'password_confirmation' => 'Ripeti la password',
        ],
        'help' => [
            'email' => 'È anche il nome utente con cui entrerai nel pannello.',
            'password' => 'Almeno otto caratteri. Conservala dove conservi le altre: non c’è nessuno che possa reimpostartela finché la posta non è configurata.',
        ],
    ],

    'tasks' => [
        'env' => [
            'label' => 'Scrittura del file .env',
            'description' => 'Le risposte diventano configurazione. Il file si scrive in un colpo solo e resta leggibile al solo proprietario.',
            'failed' => 'Non sono riuscito a scrivere il file .env. Rendilo scrivibile e riprova: il file di prima è rimasto intatto.',
        ],
        'migrazioni' => [
            'label' => 'Creazione delle tabelle',
            'description' => 'Le migrazioni costruiscono lo schema del database.',
            'failed' => 'Le migrazioni si sono fermate. Il motivo esatto è in storage/logs/laravel.log. Le cause più comuni: l’utente del database non ha il permesso di creare tabelle, oppure il database non è vuoto.',
        ],
        'verifica-tabelle' => [
            'label' => 'Verifica delle tabelle',
            'description' => 'Controllo che le tabelle attese ci siano davvero, confrontandole con quelle che le migrazioni dichiarano di creare.',
            'failed' => 'Mancano :count tabelle, fra cui :tables. Le migrazioni non sono arrivate in fondo: riprova, e se si ferma di nuovo guarda il log.',
        ],
        'dati-di-base' => [
            'label' => 'Dati di base',
            'description' => 'Ruoli, permessi, categorie, etichette e pagine legali. Nessun dato dimostrativo.',
            'failed' => 'Il caricamento dei dati di base si è fermato. Il dettaglio è nel log.',
        ],
        'citta-e-amministratore' => [
            'label' => 'Città e amministratore',
            'description' => 'La città che hai descritto e l’account con cui entrerai.',
            'failed' => 'Non sono riuscito a creare la città o l’amministratore. Il dettaglio è nel log.',
            'slug_changed' => 'L’indirizzo della città era già in uso: la città risponde a :slug.',
        ],
        'collegamento-storage' => [
            'label' => 'Collegamento delle immagini',
            'description' => 'Il collegamento public/storage, senza cui le immagini caricate non si vedono.',
            'warning' => 'Il collegamento public/storage non si è potuto creare: su questo server symlink() è disabilitata. Le immagini caricate non si vedranno finché non lo crei a mano con «php artisan storage:link», oppure finché non chiedi all’hosting di abilitare quella funzione.',
        ],
        'cache' => [
            'label' => 'Cache della configurazione',
            'description' => 'La configurazione si compila in un solo file: è ciò che rende ogni richiesta più leggera.',
            'warning' => 'La cache della configurazione non si è creata. Il sito funziona lo stesso, un po’ più lentamente: puoi riprovare più tardi con «php artisan config:cache».',
        ],
    ],

    'run' => [
        'pending' => 'in attesa',
        'done' => 'fatta',
        'current' => 'tocca a questa',
        'note' => 'Ogni operazione è una richiesta a sé: se il server ha un limite di tempo stretto, nessuna singola operazione lo incontra. Ripeterne una già fatta non causa danni.',
        'lock_failed' => 'Tutto è andato a buon fine, ma non sono riuscito a scrivere il marcatore in :path. Rendi scrivibile quella cartella e premi di nuovo: senza il marcatore, questa procedura resterebbe aperta a chiunque.',
    ],

    'done' => [
        'heading' => 'Il sito è installato',
        'summary' => 'Che cosa ho configurato',
        'labels' => [
            'app_name' => 'Nome del sito',
            'app_url' => 'Indirizzo',
            'database' => 'Database',
            'city' => 'Città',
            'admin_email' => 'Amministratore',
            'mail' => 'Posta in uscita',
            'image_driver' => 'Motore immagini',
        ],
        'mail' => [
            'log' => 'nessun invio, i messaggi finiscono nel log',
            'smtp' => 'server SMTP',
        ],
        'cron' => [
            'heading' => 'Due righe di cron, da mettere a mano',
            'intro' => 'Questo è l’unico pezzo che una procedura via web non può fare al posto tuo. Senza queste due righe non partono lo scheduler, le code, le notifiche, l’import dei calendari e i backup: il sito si vede, ma non fa niente da solo. Su cPanel si aggiungono da «Cron jobs», ogni minuto.',
        ],
        'integrations' => [
            'heading' => 'Rimasto spento, di proposito',
            'intro' => 'Queste funzioni nascono spente perché richiedono un servizio esterno. Si accendono riempiendo le variabili nel file .env e rieseguendo «php artisan config:cache». Le istruzioni complete sono in docs/RUNBOOK.md.',
            'items' => [
                'turnstile' => 'Turnstile — la protezione anti-abuso dei moduli pubblici',
                'sentry' => 'Sentry — il tracciamento degli errori',
                'analytics' => 'Analitica senza cookie — Plausible o Umami',
                's3' => 'S3 — la copia dei backup fuori da questo server',
            ],
        ],
        'warnings' => 'Da sistemare quando puoi',
        'secrets' => 'Nel file .env ho generato la chiave dell’endpoint di stato (OPS_HEALTH_TOKEN): serve al monitor di raggiungibilità e non è mostrata qui apposta.',
    ],

    'diagnosis' => [
        'title' => 'Il sito risulta installato, ma il database non risponde',
        'intro' => 'Il marcatore di installazione c’è, quindi questa procedura non riparte: reinstallare sopra un sistema esistente cancellerebbe dei dati. Il database però non è raggiungibile o non è completo. Ecco cosa ho trovato.',
        'problems' => [
            'connection' => 'La connessione al database non si apre. Verifica che il servizio sia acceso e che le credenziali in .env siano ancora valide: una password del database ruotata nel pannello dell’hosting e non aggiornata qui dà esattamente questo sintomo.',
            'schema' => 'La connessione si apre, ma il database è vuoto: non c’è nemmeno la tabella delle migrazioni. Se è il database sbagliato, correggi DB_DATABASE in .env; se è quello giusto ed è stato svuotato, va ripristinato da un backup.',
            'tables' => 'Il database c’è ma alcune tabelle mancano: :tables. Di solito significa migrazioni non applicate dopo un aggiornamento.',
        ],
        'commands' => 'Da eseguire sul server, nella cartella dell’applicazione:',
        'lock' => 'Il marcatore che tiene chiusa questa procedura è :path.',
    ],

];
