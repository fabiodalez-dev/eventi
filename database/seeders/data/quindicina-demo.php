<?php

declare(strict_types=1);

/*
 * Catalogo di `demo:quindicina` (FortnightDemoCommand).
 *
 * Quindici giorni di cartellone dimostrativo a partire dal giorno in cui si
 * lancia il comando: eventi nuovi con la loro fotografia, nuove date per gli
 * eventi demo già passati, e attorno tutto ciò che la piattaforma sa mostrare
 * — «ci vado», salvataggi, post, commenti con risposte e reazioni, sondaggi,
 * passaggi con richieste e chat, informazioni pratiche, lista d'attesa.
 *
 * Tutto è fittizio e dichiarato tale, come nella vetrina (`showcase-demo.php`):
 * eventi `is_demo` con l'avviso nella descrizione, persone nel dominio
 * riservato `.invalid` con numeri WhatsApp nell'intervallo +39 000….
 *
 * Le posizioni degli array sono identificativi stabili (`source_ref` degli
 * eventi): si aggiunge in coda, non si riordina.
 *
 * day: 0 = il giorno dell'esecuzione … 14 = due settimane dopo. Le date del
 * giorno stesso ormai passate si saltano. `repeat` aggiunge altre date dello
 * stesso evento (rassegne, repliche): sono quelle su cui nasce il sondaggio.
 * `photo` è la fotografia in `seeders/quindicina-media/`, con i crediti in
 * `credits.json`.
 */
return [
    'events' => [
        ['title' => 'Quartetto Malipiero: Haydn e Šostakovič a lume di candela', 'category' => 'musica-dal-vivo', 'venue_types' => ['teatro', 'associazione'], 'day' => 0, 'time' => '20:45', 'price' => 12, 'photo' => 'string-quartet',
            'description' => 'Quattro giovani musicisti del Conservatorio Pollini in un programma che va dal Quartetto «L’allodola» di Haydn all’ottavo di Šostakovič. Sala illuminata solo dalle candele, quaranta posti in tutto.',
            'features' => ['posti-a-sedere', 'foto-e-video-non-consentiti', 'arrivare-in-anticipo'], 'accessible' => true],
        ['title' => 'Aperitivo con il vino novello dei Colli Euganei', 'category' => 'food-e-sagre', 'venue_types' => ['ristorante', 'bar'], 'day' => 0, 'time' => '19:00', 'price' => 10, 'photo' => 'novello-wine',
            'description' => 'Tre cantine dei Colli portano il novello appena spillato, accompagnato da castagne arrostite e sopressa. Il produttore racconta la vendemmia di quest’anno, difficile per la grandine di luglio.',
            'features' => ['riservato-ai-maggiorenni', 'opzioni-vegetariane', 'pagamenti-elettronici'], 'accessible' => true],
        ['title' => 'Stand-up comedy: open mic del mercoledì', 'category' => 'teatro-e-danza', 'venue_types' => ['pub', 'bar', 'club'], 'day' => 0, 'time' => '21:30', 'price' => 0, 'photo' => 'standup-microphone', 'repeat' => [7, 14],
            'description' => 'Sette minuti a testa per chi vuole provare il proprio pezzo davanti a un pubblico vero. Si iscrive chi vuole sul posto, fino a dodici comici. Presenta Giacomo Ruzzante, che chiude con dieci minuti suoi.',
            'features' => ['ingresso-fino-a-esaurimento-posti', 'riservato-ai-maggiorenni'], 'accessible' => false],
        ['title' => 'Cineforum: «Paris, Texas» in versione originale', 'category' => 'cinema', 'venue_types' => ['cinema', 'associazione'], 'day' => 1, 'time' => '21:00', 'price' => 5, 'photo' => 'cinema-projector',
            'description' => 'Il film di Wim Wenders del 1984 in pellicola restaurata, con i sottotitoli in italiano. Introduce la serata un critico del Mattino di Padova, poi chiacchierata nel foyer per chi resta.',
            'features' => ['sottotitoli-disponibili', 'posti-a-sedere', 'evento-in-inglese'], 'accessible' => true],
        ['title' => 'Corso di pasta fresca: bigoli al torchio e ragù d’anatra', 'category' => 'corsi-e-workshop', 'venue_types' => ['ristorante', 'associazione'], 'day' => 1, 'time' => '18:30', 'price' => 35, 'photo' => 'fresh-pasta',
            'description' => 'Si impasta, si passa al torchio di bronzo e si cucina il ragù come lo fanno nelle trattorie della Bassa. Dieci postazioni, grembiule fornito. A fine corso si cena insieme con quello che si è preparato.',
            'features' => ['prenotazione-obbligatoria', 'opzioni-vegetariane'], 'accessible' => true],
        ['title' => 'Concerto: Marta Del Grandi in trio', 'category' => 'musica-dal-vivo', 'venue_types' => ['club', 'centro_sociale', 'circolo'], 'day' => 1, 'time' => '22:00', 'price' => 12, 'photo' => 'indie-concert',
            'description' => 'Canzoni sospese fra folk e elettronica, con chitarre, synth e una voce che riempie la sala. Apre la serata un cantautore padovano al suo primo disco.',
            'features' => ['suoni-ad-alto-volume', 'guardaroba-disponibile'], 'accessible' => false],
        ['title' => 'Mostra fotografica «Padova, i portici di notte»', 'category' => 'arte-e-mostre', 'venue_types' => ['galleria', 'associazione'], 'day' => 2, 'time' => '18:00', 'price' => 0, 'photo' => 'padova-portici', 'repeat' => [3, 4, 9, 10],
            'description' => 'Quaranta scatti in bianco e nero fatti fra l’una e le quattro del mattino sotto i dodici chilometri di portici della città. Inaugurazione con l’autore venerdì alle 18, poi la mostra resta aperta tutti i pomeriggi.',
            'features' => ['foto-consentite', 'ingresso-senza-gradini', 'raggiungibile-con-mezzi-pubblici'], 'accessible' => true],
        ['title' => 'Venerdì soul & funk: vinili dagli anni Settanta', 'category' => 'dj-set-nightlife', 'venue_types' => ['club', 'bar', 'pub'], 'day' => 2, 'time' => '22:30', 'price' => 8, 'photo' => 'vinyl-records', 'repeat' => [9],
            'description' => 'Solo vinili originali, solo groove: Curtis Mayfield, The Meters, Lyn Collins e le rarità italiane di quegli anni. Due dj si alternano fino alla chiusura, pista libera.',
            'features' => ['riservato-ai-maggiorenni', 'suoni-ad-alto-volume', 'guardaroba-disponibile', 'rientro-consentito'], 'accessible' => false],
        ['title' => 'Presentazione: «L’ultima osteria della Specola»', 'category' => 'libri-e-presentazioni', 'venue_types' => ['libreria', 'associazione'], 'day' => 2, 'time' => '18:30', 'price' => 0, 'photo' => 'bookshop-reading',
            'description' => 'Un romanzo ambientato in un’osteria di via Euganea che chiude dopo novant’anni. L’autore legge alcune pagine e dialoga con la libraia. Firma copie a seguire.',
            'features' => ['posti-a-sedere', 'raggiungibile-con-mezzi-pubblici'], 'accessible' => true],
        ['title' => 'Mercatino dell’antiquariato e del vintage', 'category' => 'mercatini', 'venue_types' => ['spazio_pubblico', 'altro'], 'day' => 3, 'time' => '09:00', 'price' => 0, 'photo' => 'antique-market',
            'description' => 'Centoventi espositori fra mobili, stampe, dischi, macchine fotografiche e abiti vintage. Si tratta sul prezzo, come da tradizione. Ristoro con caffè e brioche all’angolo della piazza.',
            'features' => ['allaperto', 'adatto-alle-famiglie', 'animali-ammessi', 'evento-confermato-con-pioggia'], 'accessible' => true, 'family' => true],
        ['title' => 'Laboratorio di ceramica per bambini: le formine dell’Avvento', 'category' => 'bambini-e-famiglie', 'venue_types' => ['associazione', 'galleria', 'libreria'], 'day' => 3, 'time' => '10:30', 'price' => 8, 'photo' => 'kids-clay',
            'description' => 'Con argilla e stampini i bambini dai 5 ai 10 anni preparano decorazioni da appendere all’albero. Le ceramiche vanno in forno e si ritirano la settimana dopo.',
            'features' => ['adatto-alle-famiglie', 'prenotazione-obbligatoria', 'minori-accompagnati', 'fasciatoio'], 'accessible' => true, 'family' => true],
        ['title' => 'Rugby: Petrarca Padova – Rovigo, il derby d’Italia', 'category' => 'sport', 'venue_types' => ['spazio_pubblico', 'altro'], 'day' => 3, 'time' => '15:00', 'price' => 15, 'photo' => 'rugby-match',
            'description' => 'La sfida più antica del rugby italiano torna al Plebiscito. Biglietti in tribuna e in curva, terzo tempo con birra e panini nel piazzale dopo la partita.',
            'features' => ['allaperto', 'controlli-di-sicurezza', 'punti-ristoro', 'evento-confermato-con-pioggia'], 'accessible' => true],
        ['title' => 'Notte elettronica: techno al Magazzino', 'category' => 'dj-set-nightlife', 'venue_types' => ['club', 'centro_sociale'], 'day' => 3, 'time' => '23:30', 'price' => 15, 'photo' => 'techno-club',
            'description' => 'Ospite da Berlino con quattro ore di set, preceduto da due resident del giro padovano. Impianto rinnovato, luci minime, guardaroba fino a chiusura.',
            'features' => ['riservato-ai-maggiorenni', 'suoni-ad-alto-volume', 'luci-stroboscopiche-presenti', 'documento-richiesto', 'controlli-di-sicurezza'], 'accessible' => false],
        ['title' => 'Passeggiata guidata: Padova ebraica e il ghetto', 'category' => 'altro', 'venue_types' => ['spazio_pubblico', 'associazione'], 'day' => 4, 'time' => '10:00', 'price' => 10, 'photo' => 'padova-ghetto',
            'description' => 'Due ore fra le vie del ghetto, la sinagoga di via San Martino e Solferino e il cimitero di via Wiel. Guida abilitata, gruppi di venti persone al massimo.',
            'features' => ['allaperto', 'prenotazione-obbligatoria', 'raggiungibile-con-mezzi-pubblici'], 'accessible' => false],
        ['title' => 'Pranzo della domenica: bollito misto con la pearà', 'category' => 'food-e-sagre', 'venue_types' => ['ristorante', 'circolo'], 'day' => 4, 'time' => '12:30', 'price' => 25, 'photo' => 'bollito-misto',
            'description' => 'Il carrello dei bolliti come una volta: lingua, cotechino, testina e muscolo, con pearà, mostarda e salsa verde. Tavolate condivise, vino della casa compreso.',
            'features' => ['prenotazione-obbligatoria', 'adatto-alle-famiglie'], 'accessible' => true, 'family' => true],
        ['title' => 'Spettacolo di burattini: «Arlecchino e il drago del Brenta»', 'category' => 'bambini-e-famiglie', 'venue_types' => ['teatro', 'associazione'], 'day' => 4, 'time' => '16:00', 'price' => 6, 'photo' => 'puppet-theatre',
            'description' => 'Una compagnia di burattinai di Este con le teste in legno intagliate a mano. Cinquanta minuti di storia, poi i bambini possono andare dietro la baracca e muovere i burattini.',
            'features' => ['adatto-alle-famiglie', 'posti-a-sedere', 'deposito-passeggini', 'fasciatoio'], 'accessible' => true, 'family' => true],
        ['title' => 'Corso di fotografia analogica: sviluppare il bianco e nero', 'category' => 'corsi-e-workshop', 'venue_types' => ['associazione', 'galleria'], 'day' => 5, 'time' => '19:30', 'price' => 40, 'photo' => 'darkroom', 'repeat' => [12],
            'description' => 'In camera oscura dalla pellicola al provino a contatto. Si porta un rullino già scattato, al resto pensa il laboratorio. Due serate, otto partecipanti.',
            'features' => ['prenotazione-obbligatoria'], 'accessible' => false],
        ['title' => 'Lunedì jazz: Francesca Bertazzo Trio', 'category' => 'musica-dal-vivo', 'venue_types' => ['bar', 'pub', 'ristorante'], 'day' => 5, 'time' => '21:00', 'price' => 0, 'photo' => 'jazz-trio', 'repeat' => [12],
            'description' => 'Chitarra, contrabbasso e batteria fra standard di Jobim e composizioni originali. Ingresso libero con consumazione, tavoli su prenotazione telefonica.',
            'features' => ['posti-a-sedere', 'opzioni-vegetariane'], 'accessible' => true],
        ['title' => 'Assemblea pubblica: il futuro del mercato coperto di piazza delle Erbe', 'category' => 'comunita-e-assemblee', 'venue_types' => ['spazio_pubblico', 'circolo', 'associazione'], 'day' => 5, 'time' => '18:00', 'price' => 0, 'photo' => 'public-assembly',
            'description' => 'Banchisti, residenti e assessorato al commercio discutono gli orari estesi e i nuovi banchi gastronomici sotto il Salone. Interventi di tre minuti, si iscrive chi vuole parlare.',
            'features' => ['posti-a-sedere', 'raggiungibile-con-mezzi-pubblici', 'interprete-lis'], 'accessible' => true],
        ['title' => 'Teatro: «Il Ruzante in osteria», commedia in dialetto pavano', 'category' => 'teatro-e-danza', 'venue_types' => ['teatro'], 'day' => 6, 'time' => '21:00', 'price' => 15, 'photo' => 'theatre-comedy', 'repeat' => [7],
            'description' => 'Tre atti unici di Angelo Beolco portati in scena da una compagnia di Piove di Sacco, con musica dal vivo e sottotitoli in italiano sopra il palco.',
            'features' => ['posti-a-sedere', 'sottotitoli-disponibili', 'guardaroba-disponibile'], 'accessible' => true],
        ['title' => 'Flash mob e corteo contro la violenza sulle donne', 'category' => 'politica-e-attivismo', 'venue_types' => ['spazio_pubblico'], 'day' => 7, 'time' => '18:00', 'price' => 0, 'photo' => 'women-march',
            'description' => 'Nella Giornata internazionale, ritrovo in piazza dei Signori con le scarpe rosse, poi corteo fino a Prato della Valle. Interventi dei centri antiviolenza e dei collettivi universitari.',
            'features' => ['allaperto', 'adatto-alle-famiglie', 'raggiungibile-con-mezzi-pubblici'], 'accessible' => true],
        ['title' => 'Reading: Alda Merini letta da tre attrici', 'category' => 'libri-e-presentazioni', 'venue_types' => ['libreria', 'teatro', 'associazione'], 'day' => 7, 'time' => '20:30', 'price' => 0, 'photo' => 'poetry-reading',
            'description' => 'Le poesie della Merini accompagnate da violoncello, nella sera del 25 novembre. Ingresso libero con raccolta fondi per lo sportello antiviolenza del quartiere.',
            'features' => ['posti-a-sedere', 'ingresso-fino-a-esaurimento-posti'], 'accessible' => true],
        ['title' => 'Quiz night al pub: sei round, squadre da quattro', 'category' => 'altro', 'venue_types' => ['pub', 'bar'], 'day' => 8, 'time' => '21:00', 'price' => 5, 'photo' => 'pub-quiz', 'repeat' => [1],
            'description' => 'Cultura generale, musica, Padova e una manche di immagini. La squadra vincitrice beve gratis per la serata, la peggiore sceglie la canzone di chiusura.',
            'features' => ['posti-a-sedere', 'prenotazione-obbligatoria'], 'accessible' => false],
        ['title' => 'Danza contemporanea: «Corpi d’acqua»', 'category' => 'teatro-e-danza', 'venue_types' => ['teatro'], 'day' => 8, 'time' => '20:45', 'price' => 18, 'photo' => 'contemporary-dance',
            'description' => 'Sei danzatori e una vasca d’acqua al centro del palco: uno spettacolo sulla laguna e sui fiumi del Veneto, con musiche elettroniche composte dal vivo.',
            'features' => ['posti-a-sedere', 'foto-e-video-non-consentiti', 'luci-stroboscopiche-presenti'], 'accessible' => true],
        ['title' => 'Mercatino di Natale in Prato della Valle', 'category' => 'mercatini', 'venue_types' => ['spazio_pubblico'], 'day' => 9, 'time' => '17:00', 'price' => 0, 'photo' => 'christmas-market', 'repeat' => [10, 11, 13, 14],
            'description' => 'Sessanta casette di legno con artigianato, dolci e vin brulé lungo l’Isola Memmia. Il primo giorno accensione delle luci e coro gospel alle 18, poi aperto ogni pomeriggio fino all’Epifania.',
            'features' => ['allaperto', 'adatto-alle-famiglie', 'punti-ristoro', 'deposito-passeggini', 'evento-confermato-con-pioggia'], 'accessible' => true, 'family' => true],
        ['title' => 'Concerto gospel: Padova Gospel Choir', 'category' => 'musica-dal-vivo', 'venue_types' => ['teatro', 'spazio_pubblico'], 'day' => 9, 'time' => '21:00', 'price' => 10, 'photo' => 'gospel-choir',
            'description' => 'Quaranta voci e una band di cinque elementi per il concerto che apre il periodo natalizio. Il ricavato va alla mensa delle cucine popolari.',
            'features' => ['posti-a-sedere', 'adatto-alle-famiglie'], 'accessible' => true, 'family' => true],
        ['title' => 'Torneo di scacchi rapid aperto a tutti', 'category' => 'sport', 'venue_types' => ['circolo', 'associazione', 'bar'], 'day' => 10, 'time' => '14:30', 'price' => 10, 'photo' => 'chess-tournament',
            'description' => 'Sette turni a quindici minuti più cinque secondi per mossa. Valido per il rating rapid, ma ci si può iscrivere anche senza tessera. Premi in libri per i primi tre e per il miglior under 16.',
            'features' => ['posti-a-sedere', 'area-tranquilla', 'prenotazione-obbligatoria'], 'accessible' => true],
        ['title' => 'Sagra di Santa Caterina: castagne, vin brulé e musica popolare', 'category' => 'food-e-sagre', 'venue_types' => ['spazio_pubblico', 'circolo'], 'day' => 10, 'time' => '17:30', 'price' => 0, 'photo' => 'roasted-chestnuts',
            'description' => 'Le castagne cotte nella padella forata sul fuoco a legna, frittelle e vin brulé. Alle 20 suona un gruppo di musica popolare veneta con fisarmonica e organetto.',
            'features' => ['allaperto', 'adatto-alle-famiglie', 'punti-ristoro', 'solo-contanti'], 'accessible' => true, 'family' => true],
        ['title' => 'Concerto: The Zen Circus, tour acustico', 'category' => 'musica-dal-vivo', 'venue_types' => ['teatro', 'club'], 'day' => 10, 'time' => '21:30', 'price' => 25, 'photo' => 'rock-band',
            'description' => 'La band pisana in versione acustica, con i brani dell’ultimo disco e i classici riarrangiati per chitarre e archi. Posti in piedi in platea, numerati in galleria.',
            'features' => ['biglietto-nominativo', 'controlli-di-sicurezza', 'guardaroba-disponibile', 'suoni-ad-alto-volume'], 'accessible' => true],
        ['title' => 'Mattinata al planetario: il cielo d’inverno', 'category' => 'bambini-e-famiglie', 'venue_types' => ['associazione', 'altro', 'spazio_pubblico'], 'day' => 11, 'time' => '10:30', 'price' => 7, 'photo' => 'planetarium',
            'description' => 'Sotto la cupola si riconoscono Orione, le Pleiadi e Sirio, poi si costruisce una piccola mappa del cielo da portare a casa. Adatto dai 6 anni.',
            'features' => ['adatto-alle-famiglie', 'posti-a-sedere', 'prenotazione-obbligatoria'], 'accessible' => true, 'family' => true],
        ['title' => 'Corsa podistica delle Mura: 10 km sul tracciato cinquecentesco', 'category' => 'sport', 'venue_types' => ['spazio_pubblico'], 'day' => 11, 'time' => '09:30', 'price' => 12, 'photo' => 'city-run',
            'description' => 'Partenza da Porta Savonarola e giro completo delle mura veneziane, con passaggio sui bastioni restaurati. Anche una camminata non competitiva da 5 km per famiglie.',
            'features' => ['allaperto', 'adatto-alle-famiglie', 'acqua-potabile-disponibile', 'animali-ammessi'], 'accessible' => false, 'family' => true],
        ['title' => 'Prima di Avvento: concerto d’organo agli Eremitani', 'category' => 'musica-dal-vivo', 'venue_types' => ['spazio_pubblico', 'teatro'], 'day' => 11, 'time' => '17:00', 'price' => 0, 'photo' => 'church-organ',
            'description' => 'Bach, Buxtehude e i corali dell’Avvento suonati sull’organo restaurato. Ingresso libero fino a esaurimento posti, offerta libera per il restauro degli affreschi.',
            'features' => ['posti-a-sedere', 'ingresso-fino-a-esaurimento-posti', 'area-tranquilla'], 'accessible' => true],
        ['title' => 'Cineforum: «La grande bellezza» dieci anni dopo', 'category' => 'cinema', 'venue_types' => ['cinema', 'associazione'], 'day' => 12, 'time' => '20:30', 'price' => 5, 'photo' => 'cinema-hall',
            'description' => 'Il film di Sorrentino rivisto in sala, con un’introduzione sul modo in cui ha cambiato lo sguardo del cinema italiano su Roma. Dibattito facoltativo dopo la proiezione.',
            'features' => ['posti-a-sedere', 'sottotitoli-disponibili'], 'accessible' => true],
        ['title' => 'Workshop di illustrazione: il taccuino di viaggio', 'category' => 'corsi-e-workshop', 'venue_types' => ['libreria', 'galleria', 'associazione'], 'day' => 12, 'time' => '18:30', 'price' => 20, 'photo' => 'sketchbook-drawing',
            'description' => 'Matita, acquerello e penna per raccontare un luogo in poche pagine. Un’illustratrice padovana mostra i suoi taccuini e guida gli esercizi. Materiali compresi.',
            'features' => ['prenotazione-obbligatoria', 'posti-a-sedere'], 'accessible' => true],
        ['title' => 'Presentazione: «Il Bo e i suoi fantasmi», storie dell’Università', 'category' => 'libri-e-presentazioni', 'venue_types' => ['libreria', 'associazione'], 'day' => 13, 'time' => '18:00', 'price' => 0, 'photo' => 'old-university',
            'description' => 'Ottocento anni di università raccontati attraverso le leggende: la cattedra di Galileo, il teatro anatomico, Elena Cornaro Piscopia. Con lo storico autore del libro.',
            'features' => ['posti-a-sedere', 'raggiungibile-con-mezzi-pubblici'], 'accessible' => true],
        ['title' => 'Karaoke anni Novanta', 'category' => 'dj-set-nightlife', 'venue_types' => ['pub', 'bar', 'club'], 'day' => 13, 'time' => '22:00', 'price' => 0, 'photo' => 'karaoke-night',
            'description' => 'Solo canzoni dal 1990 al 1999, italiane e straniere. Tre microfoni, testi sullo schermo grande e una giuria improvvisata che assegna un cocktail alla migliore esibizione.',
            'features' => ['riservato-ai-maggiorenni', 'suoni-ad-alto-volume'], 'accessible' => false],
        ['title' => 'Mostra: «Giotto e la luce», visita serale alla Cappella degli Scrovegni', 'category' => 'arte-e-mostre', 'venue_types' => ['galleria', 'spazio_pubblico', 'associazione'], 'day' => 13, 'time' => '19:30', 'price' => 16, 'photo' => 'giotto-fresco',
            'description' => 'Turno serale con una storica dell’arte che racconta il ciclo degli affreschi e il restauro dell’illuminazione. Quindici minuti di acclimatazione prima di entrare, come per ogni visita.',
            'features' => ['prenotazione-obbligatoria', 'biglietto-nominativo', 'foto-e-video-non-consentiti', 'arrivare-in-anticipo'], 'accessible' => true],
        ['title' => 'Cena sociale con raccolta fondi per la palestra popolare', 'category' => 'comunita-e-assemblee', 'venue_types' => ['centro_sociale', 'circolo'], 'day' => 14, 'time' => '20:00', 'price' => 15, 'photo' => 'community-dinner',
            'description' => 'Pasta e fagioli, verdure al forno e torta di mele, cucinato dai volontari. Il ricavato serve a comprare i tappeti nuovi per i corsi gratuiti di boxe e judo.',
            'features' => ['opzioni-vegane', 'opzioni-vegetariane', 'adatto-alle-famiglie'], 'accessible' => true, 'family' => true],
        ['title' => 'Concerto: Orchestra di Padova e del Veneto, Brahms e Mendelssohn', 'category' => 'musica-dal-vivo', 'venue_types' => ['teatro'], 'day' => 14, 'time' => '20:45', 'price' => 22, 'photo' => 'symphony-orchestra',
            'description' => 'La Quarta di Brahms e il Concerto per violino di Mendelssohn, solista una giovane violinista vincitrice del Premio Paganini. Prova aperta agli studenti al mattino.',
            'features' => ['posti-a-sedere', 'guardaroba-disponibile', 'foto-e-video-non-consentiti'], 'accessible' => true],
        ['title' => 'Arrampicata indoor: serata di prova per principianti', 'category' => 'sport', 'venue_types' => ['altro', 'associazione', 'circolo'], 'day' => 6, 'time' => '19:00', 'price' => 10, 'photo' => 'climbing-gym',
            'description' => 'Due ore in parete con gli istruttori della palestra, imbrago e scarpette compresi. Si comincia dal boulder, poi chi vuole prova una via con la corda dall’alto.',
            'features' => ['prenotazione-obbligatoria', 'acqua-potabile-disponibile'], 'accessible' => false],
        ['title' => 'Mercato contadino a chilometro zero', 'category' => 'mercatini', 'venue_types' => ['spazio_pubblico'], 'day' => 3, 'time' => '08:30', 'price' => 0, 'photo' => 'farmers-market', 'repeat' => [10],
            'description' => 'Ortaggi di stagione, formaggi delle malghe dell’Altopiano, miele dei Colli e pane cotto a legna. Ogni sabato mattina, banco delle zucche e dei radicchi in primo piano.',
            'features' => ['allaperto', 'adatto-alle-famiglie', 'animali-ammessi', 'solo-contanti'], 'accessible' => true, 'family' => true],
    ],

    /*
     * Persone aggiunte a quelle della vetrina: con più voci le schede e le
     * chat non ripetono sempre gli stessi nomi. Stesse garanzie: indirizzo
     * `.invalid`, numero +39 000…, nessun dispositivo, avvisi solo in app.
     */
    'people' => [
        ['handle' => 'beatrice_cecchinato', 'name' => 'Beatrice Cecchinato', 'bio' => 'Violinista in un’orchestra amatoriale. Concerti di classica e cene lunghe.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'filippo_rossetto', 'name' => 'Filippo Rossetto', 'bio' => 'Allenatore di minirugby, tifoso del Petrarca da sempre.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'giorgia_pegoraro', 'name' => 'Giorgia Pegoraro', 'bio' => 'Dottoranda in astrofisica. Planetari, osservatori e birre artigianali.', 'visibility' => 'public', 'featured' => true],
        ['handle' => 'stefano_babetto', 'name' => 'Stefano Babetto', 'bio' => 'Cuoco in pensione, ancora a caccia della pearà perfetta.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'anna_zecchinato', 'name' => 'Anna Zecchinato', 'bio' => 'Educatrice al nido. Weekend tra laboratori, burattini e parchi.', 'visibility' => 'members', 'featured' => false],
        ['handle' => 'lorenzo_bettio', 'name' => 'Lorenzo Bettio', 'bio' => 'Techno, elettronica e notti lunghe. Il lunedì non rispondo.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'camilla_furlan', 'name' => 'Camilla Furlan', 'bio' => 'Attivista in un collettivo femminista universitario. Libri, cortei, assemblee.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'enrico_zago', 'name' => 'Enrico Zago', 'bio' => 'Scacchista da circolo, giocatore di quiz al pub, perdente con dignità.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'silvia_milan', 'name' => 'Silvia Milan', 'bio' => 'Storica dell’arte, accompagno gruppi agli Scrovegni e al Palazzo della Ragione.', 'visibility' => 'public', 'featured' => true],
        ['handle' => 'giovanni_scarpa', 'name' => 'Giovanni Scarpa', 'bio' => 'Veneziano trapiantato a Padova. Jazz, spritz e ponti da attraversare.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'laura_bergamin', 'name' => 'Laura Bergamin', 'bio' => 'Corro ogni mattina, la sera vado a teatro. In mezzo lavoro in banca.', 'visibility' => 'members', 'featured' => false],
        ['handle' => 'michele_tonello', 'name' => 'Michele Tonello', 'bio' => 'Fotografo analogico, sviluppo ancora in bagno. Mercatini per obiettivi vecchi.', 'visibility' => 'public', 'featured' => false],
    ],

    /*
     * I testi con cui le persone parlano delle date, per categoria. Il
     * comando li distribuisce sulle date della quindicina e li abbina in modo
     * deterministico: una seconda esecuzione ritrova gli stessi abbinamenti.
     * Domande e risposte sono quelle che si leggono davvero sotto un evento.
     */
    'comments' => [
        'musica-dal-vivo' => [
            ['A che ora attacca davvero il concerto? Sul manifesto c’è scritto solo l’orario di apertura.', ['Di solito mezz’ora dopo, il gruppo spalla attacca puntuale.', 'L’ultima volta hanno cominciato alle dieci e un quarto.']],
            ['Ci sono posti a sedere o si sta tutti in piedi?', ['In piedi davanti, qualche sgabello lungo il bancone in fondo.']],
            ['Li ho visti in estate, dal vivo sono un’altra cosa rispetto al disco. Consigliatissimi.', ['Concordo, il bassista da solo vale il biglietto.']],
            ['Si riesce a comprare il biglietto alla porta o conviene prenderlo prima?', ['Alla porta c’erano ancora posti l’ultima volta, ma per sicurezza prenderei prima.']],
        ],
        'dj-set-nightlife' => [
            ['C’è dress code o si entra tranquilli?', ['Nessun dress code, scarpe comode che si balla.']],
            ['Fino a che ora si va avanti?', ['Chiusura alle quattro, ma il meglio è verso le due.', 'Confermo, alle due la pista è piena.']],
            ['Qualcuno sa se il guardaroba è a pagamento?', ['Due euro, ma ti ridanno il cartellino plastificato, non perderlo.']],
        ],
        'teatro-e-danza' => [
            ['Dura tanto? Devo prendere l’ultimo autobus delle 23:30.', ['Un’ora e venti senza intervallo, ce la fai.']],
            ['Adatto a un ragazzo di dodici anni?', ['Direi di sì, niente di impressionante e si ride parecchio.']],
            ['Visto alla prima, il finale mi ha lasciato a bocca aperta. Andateci.', ['Ci vado sabato, non dire altro!']],
        ],
        'cinema' => [
            ['Il film è in originale con sottotitoli o doppiato?', ['Originale sottotitolato, l’hanno scritto anche nella scheda.']],
            ['Dopo la proiezione c’è il dibattito? Mi interessa sentire il critico.', ['Sì, una mezz’ora nel foyer per chi resta.']],
            ['Si può entrare a film iniziato?', ['Meglio di no, chiudono le porte dopo i primi dieci minuti.']],
        ],
        'arte-e-mostre' => [
            ['Si possono fare foto dentro?', ['Senza flash sì, me l’hanno detto all’ingresso.']],
            ['Quanto tempo serve per vederla con calma?', ['Un’ora abbondante se leggi tutte le didascalie.', 'Io ci ho messo quaranta minuti, ma ci torno.']],
            ['C’è una visita guidata o si gira da soli?', ['Il sabato alle 17 c’è la guida, gli altri giorni da soli con l’audioguida.']],
        ],
        'libri-e-presentazioni' => [
            ['Si trova il libro in vendita sul posto?', ['Sì, la libreria ne ha preso parecchie copie.']],
            ['L’autore firma le copie anche a chi ha già il libro?', ['Certo, portalo pure.']],
            ['Ho letto il libro in due giorni, non vedo l’ora di sentire cosa racconta.', ['Stessa cosa, il capitolo sull’osteria è bellissimo.']],
        ],
        'politica-e-attivismo' => [
            ['Da dove parte esattamente il corteo?', ['Dalla piazza, lato Torre dell’Orologio. Ci troviamo lì alle 17:45.']],
            ['Ci si può unire anche a metà percorso?', ['Sì, il percorso è tutto pubblicato, ci si accoda dove si vuole.']],
            ['Porto anche i bambini, il percorso è tranquillo?', ['Tranquillissimo, ci sono sempre tante famiglie.']],
        ],
        'sport' => [
            ['Dove si ritirano i pettorali?', ['Al gazebo vicino alla partenza, dalle 8 in poi.']],
            ['C’è un posto dove lasciare la borsa?', ['Deposito borse gratuito vicino agli spogliatoi.']],
            ['Ci si può iscrivere il giorno stesso?', ['Fino a mezz’ora prima, ma pagando due euro in più.', 'Confermo, l’anno scorso mi sono iscritto la mattina.']],
        ],
        'food-e-sagre' => [
            ['C’è qualcosa per chi è vegetariano?', ['Sì, c’è sempre un piatto di verdure e i formaggi.']],
            ['Serve prenotare o si arriva e ci si siede?', ['Il sabato sera conviene prenotare, a pranzo si trova posto.']],
            ['Il vin brulé dell’anno scorso era fantastico, spero sia lo stesso signore.', ['È lui, l’ho visto montare il banco ieri.']],
            ['Si paga con la carta o solo contanti?', ['Solo contanti ai banchi, carta alla cassa centrale.']],
        ],
        'mercatini' => [
            ['C’è parcheggio vicino?', ['In piazza Rabin a pagamento, oppure il park and ride con il tram.']],
            ['Vendono anche dischi in vinile?', ['Due o tre banchi, uno ha parecchio jazz italiano.']],
            ['Se piove si fa lo stesso?', ['Sì, quasi tutti i banchi hanno il gazebo.']],
        ],
        'corsi-e-workshop' => [
            ['Serve portare qualcosa o il materiale è compreso?', ['Tutto compreso, porta solo la voglia.']],
            ['Va bene anche per chi non ha mai fatto niente del genere?', ['Assolutamente, io ero alla prima volta e me la sono cavata.']],
            ['Ci sono ancora posti liberi?', ['Ne restavano tre stamattina.']],
        ],
        'bambini-e-famiglie' => [
            ['Da che età è adatto? Mio figlio ha quattro anni.', ['Dai quattro anni va benissimo, è tutto molto interattivo.']],
            ['Si può entrare con il passeggino?', ['Sì, c’è anche un angolo per lasciarli.']],
            ['I genitori restano con i bambini o li lasciano agli educatori?', ['Restano, ma un po’ in disparte.', 'Noi siamo rimasti in fondo alla sala.']],
        ],
        'comunita-e-assemblee' => [
            ['Si può intervenire anche senza essersi iscritti prima?', ['Sì, ci si iscrive sul posto all’inizio.']],
            ['Ci sarà qualcuno del Comune?', ['Hanno confermato l’assessore e due tecnici.']],
            ['Dopo l’assemblea pubblicano un verbale?', ['Di solito sì, sul sito del consiglio di quartiere.']],
        ],
        'altro' => [
            ['Qualcuno ci va da solo? Mi va di venire ma non conosco nessuno.', ['Io ci vado da sola, ci si trova all’ingresso.', 'Anche io, scrivimi pure.']],
            ['Quanto dura più o meno?', ['Un paio d’ore abbondanti.']],
            ['Ci sono stata il mese scorso, organizzazione perfetta.', ['Buono a sapersi, grazie!']],
        ],
    ],

    /* Post sulle date: [intento, testo]. Intenti: attend, recommend. */
    'posts' => [
        ['attend', 'Finalmente una serata così in città. Ci vediamo lì!'],
        ['attend', 'Preso il biglietto, se qualcuno vuole venire scrivetemi.'],
        ['attend', 'Ci vado con due amiche, siamo quelle con la sciarpa gialla.'],
        ['recommend', 'Se non lo conoscete andateci: l’ho visto in primavera ed è stato uno dei momenti più belli dell’anno.'],
        ['recommend', 'Locale piccolo, arrivate presto. Ne vale la pena.'],
        ['attend', 'Primo weekend libero dopo un mese di turni: questa non me la perdo.'],
        ['recommend', 'Perfetto anche per chi porta i bambini, l’organizzazione è attentissima.'],
        ['attend', 'Ci vado in bici, se qualcuno parte dall’Arcella ci si trova al ponte.'],
        ['recommend', 'Una delle cose migliori del cartellone di queste due settimane.'],
        ['attend', 'Segnato in calendario da settimane. Chi c’è?'],
        ['recommend', 'Prezzo onesto, gente simpatica e si sente benissimo.'],
        ['attend', 'Vado dopo il lavoro, arrivo un po’ in ritardo ma arrivo.'],
    ],

    /* Commenti ai post: risposte brevi fra persone. */
    'post_comments' => [
        'Ci sono anche io, ci vediamo all’ingresso!',
        'Grazie della dritta, non lo conoscevo.',
        'Vengo volentieri, a che ora ci troviamo?',
        'L’anno scorso c’ero, confermo tutto.',
        'Se avanza un posto in macchina fammi sapere.',
        'Mi hai convinto, prendo il biglietto stasera.',
        'Peccato, quel giorno lavoro. Raccontaci com’è andata!',
        'Porto anche mia sorella, le piacerà.',
    ],

    /* Zone di partenza dei passaggi, con fermate possibili. */
    'ride_zones' => [
        ['Arcella, piazzale Azzurri d’Italia', ['Stazione']],
        ['Guizza, capolinea del tram', ['Santa Croce']],
        ['Stazione di Padova, lato via Tommaseo', ['Piazzale Stanga']],
        ['Sacra Famiglia, sagrato della chiesa', []],
        ['Chiesanuova, piazza della chiesa', ['Brentella']],
        ['Mortise, parcheggio del supermercato', ['San Lazzaro']],
        ['Selvazzano, piazza Guido Puchetti', ['Tencarola']],
        ['Abano Terme, viale delle Terme', ['Montegrotto']],
        ['Vigonza, piazza Zanella', ['Busa']],
        ['Rubano, municipio', ['Sarmeola']],
    ],

    'ride_notes' => [
        'Parto puntuale, avvisatemi se fate tardi.',
        'Macchina grande, c’è posto anche per un passeggino.',
        'Rientro subito dopo la fine, niente tappe.',
        'Si divide la benzina, quello che vi sembra giusto.',
        'Musica a scelta del passeggero, giuro.',
        'Posso fare una deviazione breve se è sulla strada.',
        'Non fumatori, grazie. Si può portare il cane piccolo.',
        'Ho un seggiolino per bambini nel bagagliaio.',
    ],

    'ride_request_notes' => [
        'Ciao! Siamo in due, va bene?',
        'Posso salire alla fermata intermedia?',
        'Ciao, arrivo puntuale. Grazie mille!',
        null,
        'Avrei uno zaino grande, c’è spazio?',
        'Torno anche con te se fai il ritorno.',
    ],

    'ride_messages' => [
        ['driver', 'Ciao! Ci vediamo al punto di ritrovo, ho una Panda grigia.'],
        ['passenger', 'Perfetto, sarò lì cinque minuti prima.'],
        ['driver', 'Ti scrivo appena parto, così sai quando arrivo.'],
        ['passenger', 'Grazie, per la benzina ti do cinque euro, va bene?'],
        ['driver', 'Va benissimo, a dopo!'],
    ],

    'ride_reviews' => [
        [5, 'Puntuale e gentilissimo, macchina pulita. Ci riprovo volentieri.'],
        [5, 'Viaggio tranquillo e tante chiacchiere. Consigliato.'],
        [4, 'Tutto bene, solo qualche minuto di ritardo ma avvisato per tempo.'],
        [5, 'Mi ha riportata fino sotto casa dopo il concerto. Grazie davvero.'],
        [4, 'Guida prudente, musica ottima.'],
        [5, 'Precisa e simpatica, si parte all’ora detta.'],
    ],
];
