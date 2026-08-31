<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * I testi delle pagine informative (§11.1, §16).
 *
 * ## Sono testi veri, non segnaposto
 *
 * Descrivono **questo** sito: le colonne che esistono davvero in `users`, i
 * cookie che il codice imposta davvero, il fatto che le coordinate del "vicino
 * a me" non vengono salvate da nessuna parte (§11.7 e §16), che i promemoria
 * arrivano per email e non come notifiche push (D8), che i pagamenti non
 * esistono ancora ma sono previsti (D9). Una frase che descrive una funzione
 * che non c'è è un'informativa falsa, che è peggio di un'informativa assente.
 *
 * ## Idempotente
 *
 * `updateOrCreate` sullo slug, ma **solo per le pagine che non esistono
 * ancora**: rieseguire il seeder in produzione non deve cancellare le
 * correzioni della redazione, che è esattamente la ragione per cui questi testi
 * stanno nel database e non in un file Blade.
 */
class PageSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->pages() as $page) {
            Page::query()->firstOrCreate(['slug' => $page['slug']], $page);
        }
    }

    /**
     * Toglie gli a capo che servono soltanto a tenere corte le righe del
     * **codice sorgente**.
     *
     * Il Markdown li ignora — un paragrafo spezzato su cinque righe si rende
     * comunque come un paragrafo solo — ma chi apre il testo nell'editor del
     * pannello li vede tutti, e appena aggiunge una frase l'impaginazione
     * diventa a bandiera. Il testo che finisce nel database ha quindi un
     * paragrafo per riga, e questo file resta leggibile.
     *
     * Le righe che in Markdown **sono** una riga — titoli e righe di tabella —
     * restano dove sono; le voci di elenco assorbono le proprie continuazioni,
     * che è esattamente ciò che il Markdown farebbe rendendole.
     */
    private function unwrap(string $markdown): string
    {
        $righe = [];
        $aperta = '';

        foreach (explode("\n", $markdown) as $riga) {
            $testo = trim($riga);

            // Righe che in Markdown *sono* una riga: vuote, titoli, tabelle.
            $isolata = $testo === '' || str_starts_with($testo, '#') || str_starts_with($testo, '|');

            // Una voce di elenco o una citazione apre un blocco che le righe
            // seguenti proseguono, esattamente come farebbe il rendering.
            $apreBlocco = ! $isolata && preg_match('/^(?:[-*>]\s|\d+\.\s)/', $testo) === 1;

            if (($isolata || $apreBlocco) && $aperta !== '') {
                $righe[] = $aperta;
                $aperta = '';
            }

            if ($isolata) {
                $righe[] = $testo;

                continue;
            }

            $aperta = $aperta === '' ? $testo : $aperta.' '.$testo;
        }

        if ($aperta !== '') {
            $righe[] = $aperta;
        }

        return trim(implode("\n", $righe))."\n";
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pages(): array
    {
        return [
            [
                'slug' => 'privacy',
                'title' => 'Informativa privacy',
                'excerpt' => 'Quali dati raccogliamo, perché, per quanto tempo e come farli sparire.',
                'seo_description' => 'Informativa sul trattamento dei dati personali: cosa raccoglie questo sito, perché, per quanto tempo e come cancellare il proprio account.',
                'sort_order' => 10,
                'is_published' => true,
                'body' => $this->unwrap($this->privacy()),
            ],
            [
                'slug' => 'cookie',
                'title' => 'Cookie policy',
                'excerpt' => 'I cookie che questo sito usa sono tre, e nessuno serve a profilarti.',
                'seo_description' => 'L’elenco completo dei cookie e delle memorie locali usate da questo sito, a cosa servono e come cambiare la propria scelta.',
                'sort_order' => 20,
                'is_published' => true,
                'body' => $this->unwrap($this->cookies()),
            ],
            [
                'slug' => 'termini',
                'title' => 'Termini di servizio',
                'excerpt' => 'Le regole del sito: cosa si può pubblicare, di chi sono le locandine, cosa può cambiare.',
                'seo_description' => 'Termini e condizioni d’uso: chi può pubblicare eventi, responsabilità sui contenuti, diritti sulle locandine e sugli aggiornamenti del servizio.',
                'sort_order' => 30,
                'is_published' => true,
                'body' => $this->unwrap($this->terms()),
            ],
            [
                'slug' => 'chi-siamo',
                'title' => 'Chi siamo',
                'excerpt' => 'Un calendario degli eventi della città, tenuto aggiornato da chi li organizza.',
                'seo_description' => 'Che cos’è questo sito, chi lo tiene aggiornato, da dove arrivano gli eventi e come si distinguono quelli confermati dai locali.',
                'sort_order' => 40,
                'is_published' => true,
                'body' => $this->unwrap($this->about()),
            ],
            [
                'slug' => 'contatti',
                'title' => 'Contatti',
                'excerpt' => 'Come segnalare un errore, chiedere una correzione o proporre un evento.',
                'seo_description' => 'Come contattarci: segnalare un errore su un evento, chiedere la rimozione di un’immagine, proporre un appuntamento, registrare il proprio locale.',
                'sort_order' => 50,
                'is_published' => true,
                'body' => $this->unwrap($this->contacts()),
            ],
        ];
    }

    /**
     * Chi risponde dei dati. Se `SEO_ORGANIZATION` non è compilata, il titolare
     * è il progetto stesso: meglio un nome generico ma vero che una ragione
     * sociale inventata dentro un documento che deve reggere davanti a
     * un'autorità.
     */
    private function controller(): string
    {
        $organization = trim((string) config('seo.organization.legal_name'));

        return $organization === '' ? (string) config('app.name') : $organization;
    }

    private function contactEmail(): string
    {
        $email = trim((string) config('seo.organization.email'));

        return $email === '' ? (string) config('mail.from.address') : $email;
    }

    private function privacy(): string
    {
        $controller = $this->controller();
        $email = $this->contactEmail();
        $app = (string) config('app.name');

        return <<<MARKDOWN
        Questa pagina spiega quali dati personali questo sito raccoglie, perché lo fa, con chi
        li condivide e per quanto tempo li conserva. È scritta per essere letta, non per essere
        accettata di fretta: se qualcosa non è chiaro, scrivici a **{$email}**.

        ## Chi tratta i dati

        Il titolare del trattamento è **{$controller}**, raggiungibile all'indirizzo **{$email}**.

        ## Se ti limiti a navigare

        Non raccogliamo alcun dato personale, non ti assegniamo alcun identificativo e non ti
        chiediamo niente. Non ci sono cookie pubblicitari, non ci sono pixel di terze parti e
        nessuna pagina di questo sito carica risorse da reti pubblicitarie: i caratteri
        tipografici sono ospitati sul nostro dominio proprio per non far partire una richiesta
        verso un altro server ogni volta che apri una pagina.

        Il server su cui il sito è ospitato registra, come qualunque server web, le richieste
        che riceve: indirizzo IP, momento, pagina richiesta ed esito. Sono registri tecnici,
        servono a diagnosticare guasti e ad accorgersi degli abusi, non vengono usati per
        profilare nessuno e non vengono incrociati con gli account.

        ## Se ti registri

        Raccogliamo il minimo che serve a farti ritrovare le tue cose:

        - **Indirizzo email** — obbligatorio: è la tua identità sul sito ed è dove arrivano i
          promemoria che hai chiesto.
        - **Password** — conservata **solo** come impronta crittografica non reversibile. Non
          esiste da nessuna parte in chiaro, non possiamo leggerla e non possiamo comunicartela:
          se la dimentichi si sostituisce, non si recupera.
        - **Nome** — **facoltativo**. Se non lo scrivi, non lo chiediamo una seconda volta.
        - **Fuso orario e lingua** — servono a mostrarti gli orari giusti e le pagine nella
          lingua giusta. Sono impostati su quelli predefiniti del sito e li puoi cambiare dal
          tuo profilo.
        - **Preferenze di notifica** — quali promemoria vuoi ricevere, a che ora, e in quali ore
          preferisci non essere disturbato.
        - **Le date che salvi e i locali, le categorie e i tag che segui** — sono il servizio,
          non un'analisi del tuo comportamento.

        Non chiediamo data di nascita, sesso, numero di telefono, indirizzo di casa, professione
        né interessi dichiarati. Non abbiamo un profilo pubblicitario di te perché non abbiamo
        pubblicità.

        ## Se salvi eventi senza registrarti

        Le date che metti in agenda **senza account non arrivano mai al server**: restano nella
        memoria locale del tuo browser (`localStorage`), su quel dispositivo e su nessun altro.
        Se poi crei un account, il browser ti chiede di trasferirle: succede solo dopo che hai
        fatto l'accesso, e solo con quel gesto.

        ## Se usi «vicino a me»

        La posizione te la chiede il browser **solo quando premi il pulsante**, mai all'apertura
        del sito. Le coordinate servono a una sola cosa — ordinare gli eventi per distanza — e
        **non vengono mai salvate**: non finiscono in nessuna tabella del database, non entrano
        in alcun profilo e non restano al termine della ricerca. Viaggiano nell'indirizzo della
        pagina che stai guardando e spariscono con esso.

        ## Se proponi un evento o segnali un errore

        Conserviamo quello che hai scritto, il tuo indirizzo email **se hai scelto di lasciarlo**
        — serve solo a poterti rispondere — e l'indirizzo IP da cui è arrivato l'invio. L'IP ha
        una funzione sola: impedire che i moduli pubblici vengano riempiti automaticamente. Non
        viene usato per altro.

        ## Se registri un locale

        Raccogliamo i dati di chi è responsabile della scheda (nome, email, e il telefono se lo
        indichi) e i dati del locale, che sono pubblici per definizione: nome, indirizzo, orari,
        contatti. I dati del referente non sono visibili al pubblico e non lo sono nemmeno ai
        collaboratori che il referente aggiunge.

        ## Perché trattiamo questi dati

        | Cosa | Perché | Base giuridica |
        |---|---|---|
        | Account, salvataggi, promemoria | Fornirti il servizio che hai chiesto | Esecuzione del contratto |
        | Proposte di evento e segnalazioni | Tenere il calendario corretto | Interesse legittimo |
        | Indirizzo IP dei moduli pubblici, registri del server | Sicurezza e difesa dagli abusi | Interesse legittimo |
        | Statistiche anonime di lettura | Capire quali pagine servono davvero | Consenso, revocabile in ogni momento |

        ## Con chi li condividiamo

        Con nessuno, a parte i fornitori tecnici senza i quali il sito non starebbe in piedi: chi
        ospita il server e chi consegna le email. Agiscono come responsabili del trattamento e
        non possono usare i dati per proprio conto.

        **Non vendiamo dati, non li cediamo a inserzionisti e non li usiamo per pubblicità.** Non
        c'è un trasferimento di dati fuori dall'Unione europea nella configurazione attuale del
        servizio.

        ## Per quanto li conserviamo

        - **Account** — finché lo tieni aperto. Quando lo cancelli, i tuoi dati spariscono subito
          (vedi sotto).
        - **Proposte di evento e segnalazioni** — fino alla loro definizione, e comunque non oltre
          il tempo necessario a riconoscere abusi ripetuti.
        - **Registro dei consensi** — per la durata della scelta, perché è la prova che quella
          scelta è stata fatta.
        - **Registro delle azioni di redazione** — 90 giorni.

        ## I tuoi diritti

        Puoi chiedere di **accedere** ai tuoi dati, **correggerli**, **cancellarli**,
        **limitarne** il trattamento, **opporti** a un trattamento fondato sull'interesse
        legittimo e **riceverli** in un formato leggibile da un altro programma. Scrivi a
        **{$email}**: rispondiamo entro trenta giorni. Se pensi che qualcosa non vada, puoi
        rivolgerti al **Garante per la protezione dei dati personali** (garanteprivacy.it).

        ## Come cancellare l'account

        Dal tuo profilo, alla voce **Cancella l'account**. Non serve scriverci e non c'è nessun
        percorso da fare: l'effetto è immediato e comprende i salvataggi, i locali che segui, i
        dispositivi collegati, l'archivio delle notifiche e i promemoria già in coda, che vengono
        annullati invece di partire il giorno dopo verso un indirizzo che non vuole più
        riceverli.

        Quello che resta è una riga senza nome, senza email leggibile e senza preferenze, e
        serve soltanto a non far crollare la cronologia delle modifiche fatte in redazione. Gli
        eventi pubblicati da un locale continuano a esistere perché appartengono al locale, non
        alla persona che li ha inseriti.

        ## Sicurezza

        Il sito è servito solo in HTTPS. Le password sono conservate come impronte non
        reversibili, i moduli sono protetti contro l'invio da altri siti, gli accessi e i moduli
        pubblici hanno un limite di frequenza, e le immagini caricate vengono verificate nel
        contenuto e non solo nel nome. I dati sono salvati ogni giorno e i salvataggi vengono
        provati con un ripristino reale, perché un salvataggio mai riletto non è un salvataggio.

        ## Minori

        {$app} non è rivolto ai minori di quattordici anni e non chiede l'età. Se ci accorgiamo
        che un account appartiene a un minore di quattordici anni lo cancelliamo.

        ## Modifiche

        Se cambiamo qualcosa di sostanziale, questa pagina cambia data e la richiesta di consenso
        torna a comparire. Le versioni precedenti restano disponibili su richiesta.
        MARKDOWN;
    }

    private function cookies(): string
    {
        $email = $this->contactEmail();

        return <<<MARKDOWN
        Questo sito **non usa cookie di profilazione, non ha pubblicità e non condivide nulla con
        reti pubblicitarie.** Quello che segue è l'elenco completo, senza omissioni.

        ## Cookie tecnici, sempre presenti

        | Nome | A cosa serve | Quanto dura |
        |---|---|---|
        | Cookie di sessione | Tenerti collegato mentre navighi e ricordare i messaggi di conferma | Fino alla chiusura della sessione |
        | `XSRF-TOKEN` | Impedire che un altro sito invii moduli al posto tuo | Durata della sessione |
        | `consenso` | Ricordare la scelta che hai fatto qui sotto, così non te la chiediamo a ogni pagina | 180 giorni |

        Sono cookie tecnici: senza, il sito non fa quello che gli chiedi. Per questo non richiedono
        consenso e non si possono disattivare da qui. Puoi comunque cancellarli dalle impostazioni
        del tuo browser, ricominciando da capo.

        ## Memoria locale del browser

        Se metti una data in agenda **senza avere un account**, la salviamo nella memoria locale
        del tuo browser (`localStorage`). Non è un cookie e non viene inviata al server: resta su
        quel dispositivo. È il modo in cui il pulsante funziona al primo click, senza chiederti di
        registrarti. Se poi crei un account, il browser ti propone di trasferire quelle date.

        Nella stessa memoria segniamo che ti abbiamo già proposto una volta di creare un account,
        per non riproportelo a ogni visita.

        ## Statistiche di lettura

        Ci interessa sapere quante volte una pagina è stata aperta, per capire cosa serve
        davvero. Per farlo usiamo — quando è attivo — uno strumento **senza cookie**: non scrive
        niente sul tuo dispositivo, non ti assegna un identificativo, non ti segue da un sito
        all'altro e non permette di ricostruire il percorso di una singola persona. Conta le
        pagine, non i lettori.

        Anche così resta una richiesta verso un altro server, e quindi resta una scelta tua: la
        trovi in fondo a questa pagina e la puoi cambiare quando vuoi.

        ## Quello che non c'è

        Non ci sono cookie pubblicitari, pixel di social network, mappe di calore, registrazioni
        di sessione, test A/B, né strumenti che ti riconoscano su altri siti. I caratteri
        tipografici sono ospitati sul nostro dominio: nemmeno la scelta del carattere fa partire
        una richiesta verso qualcun altro.

        ## Mappe e contenuti esterni

        La mappa degli eventi usa tessere cartografiche servite da un fornitore esterno: quando
        apri la mappa — e solo allora — il tuo browser contatta quel server, che come qualunque
        server vede l'indirizzo IP da cui arriva la richiesta. Nessun cookie viene impostato da
        quella pagina.

        ## Cambiare idea

        Il pannello qui sotto mostra la scelta che hai fatto e ti permette di cambiarla o di
        cancellarla del tutto. Se hai domande, scrivi a **{$email}**.
        MARKDOWN;
    }

    private function terms(): string
    {
        $app = (string) config('app.name');
        $email = $this->contactEmail();

        return <<<MARKDOWN
        Usando {$app} accetti quello che segue. È scritto in italiano corrente perché è pensato
        per essere letto.

        ## Che cos'è questo servizio

        {$app} è un calendario di eventi. Raccoglie appuntamenti pubblici, li ordina per data e
        per luogo e li rende cercabili. **Non vende biglietti, non prende prenotazioni e non
        organizza nulla**: quando un evento ha una biglietteria, il collegamento porta al sito di
        chi la gestisce, e da lì in poi il contratto è con loro.

        ## Chi pubblica, e cosa

        Gli eventi arrivano da tre strade: li inserisce il locale che li organizza, li propone
        chi li conosce, oppure vengono letti da un calendario pubblico che il locale ha
        collegato. Ogni evento porta con sé la propria provenienza, e la scheda distingue quelli
        **confermati dal locale** da quelli che non lo sono ancora.

        Chi pubblica risponde di quello che pubblica. In particolare dichiara che le informazioni
        sono corrette e aggiornate, che l'evento è reale e aperto al pubblico, e che il contenuto
        non è offensivo, discriminatorio, ingannevole o illecito.

        ## Le locandine e le immagini

        **Chi carica un'immagine dichiara di avere il diritto di usarla e di concederci il
        diritto di mostrarla su questo sito**, comprese le anteprime che compaiono nelle liste,
        nelle mappe, nei feed e nelle condivisioni sui social. È una dichiarazione che vale al
        momento del caricamento e a ogni caricamento successivo.

        Se una locandina, una fotografia o un testo pubblicato qui viola un diritto d'autore,
        scrivi a **{$email}** indicando l'indirizzo della pagina e quale contenuto ti riguarda.
        Rimuoviamo il contenuto contestato mentre verifichiamo, non dopo: le verifiche possono
        richiedere giorni, l'esposizione è immediata. Ogni rimozione e ogni ripristino restano
        registrati.

        ## Cosa fai tu

        Puoi consultare il sito, salvare eventi, seguire locali e categorie, iscriverti ai
        promemoria e proporre appuntamenti. Non puoi: inserire eventi inesistenti, usare i moduli
        pubblici per pubblicità, raccogliere automaticamente i contenuti del sito con strumenti
        che ne compromettano il funzionamento, o rivendicare un locale di cui non sei
        responsabile.

        Un account può essere sospeso se una di queste regole viene violata. Te lo diciamo, e ti
        diciamo perché.

        ## Il tuo account

        Sei responsabile delle credenziali con cui accedi. Puoi cancellare l'account quando vuoi
        dal tuo profilo, senza chiederci niente: l'effetto è immediato ed è descritto
        nell'informativa privacy.

        ## Precisione dei dati

        Facciamo il possibile perché orari, prezzi e luoghi siano corretti, ma le informazioni
        arrivano da chi organizza e possono cambiare all'ultimo momento. **Prima di muoverti,
        controlla i canali del locale.** Non rispondiamo di una serata annullata, di un orario
        spostato o di un prezzo diverso da quello indicato.

        ## Servizio gratuito, e cosa potrebbe cambiare

        Oggi la pubblicazione degli eventi è gratuita per tutti. È previsto che in futuro alcune
        forme di visibilità aggiuntiva — la messa in evidenza di un evento o di un locale —
        possano diventare a pagamento per le attività commerciali, restando gratuite per
        associazioni e realtà senza scopo di lucro. Se e quando accadrà, sarà annunciato in
        anticipo, riguarderà solo le funzioni nuove e **non renderà mai a pagamento la
        pubblicazione ordinaria di un evento né la consultazione del calendario**. Un contenuto
        promosso sarà sempre riconoscibile come tale.

        ## Interruzioni

        Il servizio è offerto così com'è. Facciamo manutenzione, aggiorniamo, correggiamo: può
        capitare che per qualche minuto non risponda. Non garantiamo la continuità assoluta e non
        rispondiamo dei danni derivanti da un'interruzione.

        ## Modifiche a questi termini

        Se cambiano in modo sostanziale, questa pagina cambia data e lo segnaliamo sul sito.
        Continuare a usare il servizio dopo la modifica significa accettarla.

        ## Legge applicabile

        Si applica la legge italiana.
        MARKDOWN;
    }

    private function about(): string
    {
        $app = (string) config('app.name');

        return <<<MARKDOWN
        {$app} è un calendario degli eventi della città e della sua provincia: concerti,
        presentazioni, mostre, assemblee, mercatini, proiezioni, serate nei circoli. Un posto
        solo, aggiornato da chi gli eventi li organizza.

        ## Perché esiste

        Perché sapere cosa succede stasera è più difficile di quanto dovrebbe. Le informazioni ci
        sono, ma sono sparse fra decine di pagine social, gruppi di messaggistica e volantini
        appesi: chi non segue già i posti giusti non le trova, e chi organizza deve ripetere lo
        stesso annuncio in cinque posti diversi.

        ## Come funziona

        Ogni locale, circolo, associazione o spazio culturale può avere una scheda e pubblicare i
        propri appuntamenti da solo, anche dal telefono. Chi ha già un calendario pubblico può
        collegarlo e non riscrivere niente: le date arrivano da sole e restano aggiornate.

        Chi non organizza può comunque proporre un evento che conosce: viene letto da una persona
        prima di comparire.

        ## Cosa ci teniamo a fare bene

        - **Dire la verità sulla provenienza.** Un evento confermato dal locale e uno raccolto
          altrove non sono la stessa cosa, e la scheda lo dice.
        - **Funzionare in mano, alla fermata dell'autobus.** Il sito nasce per il telefono e
          continua a funzionare anche senza JavaScript.
        - **Non trattare chi legge come un dato.** Nessuna pubblicità, nessuna profilazione,
          nessun cookie di terze parti, nessuna posizione conservata.
        - **Restituire quello che raccogliamo.** Il calendario si può sottoscrivere dal proprio
          telefono, leggere come feed e incorporare nel sito del proprio locale.

        ## Chi lo tiene in piedi

        Il progetto è curato da una redazione ridotta all'osso, che approva le schede dei locali,
        controlla le proposte e tiene pulita la classificazione. Il resto lo fanno i locali stessi.

        Se vuoi contribuire — segnalando un errore, proponendo un appuntamento o registrando il
        tuo spazio — la pagina dei contatti dice come.
        MARKDOWN;
    }

    private function contacts(): string
    {
        $email = $this->contactEmail();

        return <<<MARKDOWN
        Scriviamo poco e rispondiamo a tutti. L'indirizzo è **{$email}**.

        ## Ho visto un errore su un evento

        Ogni scheda di evento ha in fondo un collegamento **Segnala un errore**: è la strada più
        veloce, perché arriva già con l'indicazione di quale evento stai guardando. Non serve
        avere un account e l'email è facoltativa — la lasci solo se vuoi una risposta.

        ## Organizzo eventi e vorrei pubblicarli

        Registra il tuo locale dalla voce **Registra il tuo locale** nel piè di pagina. Dopo
        l'approvazione ricevi un accesso al pannello con cui pubblicare da solo, anche dal
        telefono. Se hai già un calendario pubblico, si può collegare: le date arrivano da sole.

        ## Conosco un evento che qui non c'è

        Usa **Proponi un evento**. Bastano titolo, data e luogo: al resto pensiamo noi. Le
        proposte vengono lette da una persona prima di essere pubblicate.

        ## Una mia immagine è pubblicata qui e non dovrebbe

        Scrivi a **{$email}** indicando l'indirizzo della pagina e quale contenuto ti riguarda.
        Rimuoviamo il contenuto contestato mentre verifichiamo, non dopo.

        ## Ho una richiesta sui miei dati

        Accesso, correzione, cancellazione, opposizione: stesso indirizzo, **{$email}**.
        Rispondiamo entro trenta giorni. L'informativa privacy spiega tutto nel dettaglio, e la
        cancellazione dell'account si fa da soli dal proprio profilo, senza scriverci.

        ## Tempi

        Non abbiamo un centralino né un orario di sportello: leggiamo i messaggi ogni giorno e
        rispondiamo appena possiamo. Le segnalazioni su un evento in programma nelle prossime ore
        hanno la precedenza su tutto il resto.
        MARKDOWN;
    }
}
