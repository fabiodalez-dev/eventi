<?php

declare(strict_types=1);

/*
 * Catalogo di `demo:showcase` (ShowcaseDemoCommand).
 *
 * Tutto è fittizio e dichiarato tale: gli eventi portano l'avviso nella
 * descrizione e `is_demo`, i profili lo dicono nella bio, gli indirizzi email
 * stanno nel dominio riservato `.invalid` (RFC 2606) e i numeri WhatsApp in un
 * intervallo italiano non assegnabile (+39 000…).
 *
 * Le posizioni degli array sono identificativi stabili: `source_ref` degli
 * eventi e gli incroci di post e commenti le usano. Si aggiunge in coda, non
 * si riordina, o una seconda esecuzione non riconosce più ciò che ha creato.
 *
 * day: 0 = lunedì della settimana prossima … 6 = domenica.
 */
return [
    'events' => [
        ['title' => 'Lunedì d’essai: «Le notti di Cabiria» in copia restaurata', 'category' => 'cinema', 'venue_types' => ['cinema', 'associazione'], 'day' => 0, 'time' => '20:30', 'price' => 6,
            'description' => 'Il capolavoro di Fellini torna in sala nella copia restaurata, con una breve introduzione sul restauro e sulla Roma di fine anni Cinquanta. Versione originale con sottotitoli.',
            'features' => ['sottotitoli-disponibili', 'posti-a-sedere'], 'accessible' => true],
        ['title' => 'Presentazione: «Il fiume sotto la città», storie del Bacchiglione', 'category' => 'libri-e-presentazioni', 'venue_types' => ['libreria', 'associazione'], 'day' => 0, 'time' => '18:30', 'price' => 0,
            'description' => 'Un libro di racconti e fotografie sui canali di Padova, quelli visibili e quelli coperti. L’autrice dialoga con uno storico della città; segue un piccolo rinfresco.',
            'features' => ['posti-a-sedere', 'raggiungibile-con-mezzi-pubblici'], 'accessible' => true],
        ['title' => 'Jam session blues del lunedì', 'category' => 'musica-dal-vivo', 'venue_types' => ['pub', 'bar'], 'day' => 0, 'time' => '21:30', 'price' => 0,
            'description' => 'Palco aperto a chi suona blues: backline fornita, si porta solo lo strumento. La house band apre la serata e accompagna chi sale a suonare.',
            'features' => [], 'accessible' => false],
        ['title' => 'Laboratorio di fotografia notturna in centro storico', 'category' => 'corsi-e-workshop', 'venue_types' => ['associazione', 'galleria'], 'day' => 1, 'time' => '19:00', 'price' => 15,
            'description' => 'Due ore tra le piazze del centro per imparare esposizioni lunghe, cavalletto e luci artificiali. Va bene qualsiasi fotocamera, anche lo smartphone. Posti limitati a dodici persone.',
            'features' => ['prenotazione-obbligatoria', 'allaperto'], 'accessible' => false],
        ['title' => 'Prove aperte: «Il servitore di due padroni» riletto oggi', 'category' => 'teatro-e-danza', 'venue_types' => ['teatro'], 'day' => 1, 'time' => '21:00', 'price' => 10,
            'description' => 'Una compagnia giovane apre le prove della sua versione di Goldoni: maschere, musica elettronica e un Arlecchino rider. A fine serata il regista risponde alle domande del pubblico.',
            'features' => ['posti-a-sedere', 'guardaroba-disponibile'], 'accessible' => true],
        ['title' => 'Assemblea di quartiere sulla nuova ciclabile dell’Arcella', 'category' => 'comunita-e-assemblee', 'venue_types' => ['spazio_pubblico', 'circolo', 'associazione'], 'day' => 1, 'time' => '18:30', 'price' => 0,
            'description' => 'Tecnici del Comune e residenti a confronto sul tracciato proposto, con le mappe del progetto a disposizione. Si raccolgono osservazioni scritte da inviare agli uffici.',
            'features' => ['posti-a-sedere', 'raggiungibile-con-mezzi-pubblici'], 'accessible' => true],
        ['title' => 'Letture ad alta voce per piccoli lettori (3–6 anni)', 'category' => 'bambini-e-famiglie', 'venue_types' => ['libreria', 'associazione'], 'day' => 2, 'time' => '17:00', 'price' => 0,
            'description' => 'Tre albi illustrati letti a più voci, con tappeto e cuscini per i più piccoli. Gli adulti accompagnatori restano in sala. Al termine ogni bambino può disegnare il proprio finale.',
            'features' => ['adatto-alle-famiglie', 'minori-accompagnati', 'deposito-passeggini', 'fasciatoio'], 'accessible' => true, 'family' => true],
        ['title' => 'Quartetto d’archi sotto le stelle: Haydn e Piazzolla', 'category' => 'musica-dal-vivo', 'venue_types' => ['circolo', 'teatro'], 'day' => 2, 'time' => '20:45', 'price' => 12,
            'description' => 'Un quartetto di giovani diplomati al conservatorio accosta Haydn ai tanghi di Piazzolla. In caso di pioggia il concerto si sposta nella sala interna.',
            'features' => ['posti-a-sedere', 'evento-confermato-con-pioggia'], 'accessible' => true],
        ['title' => 'Vernissage: «Luce di laguna», fotografie di acqua e nebbia', 'category' => 'arte-e-mostre', 'venue_types' => ['galleria', 'associazione'], 'day' => 2, 'time' => '18:30', 'price' => 0,
            'description' => 'Inaugurazione di una mostra di fotografia in bianco e nero sulla laguna d’inverno. Il fotografo accompagna i visitatori tra le stampe; la mostra resta aperta per tre settimane.',
            'features' => ['foto-consentite', 'ingresso-senza-gradini'], 'accessible' => true],
        ['title' => 'Cena a tema: bigoli e baccalà della tradizione veneta', 'category' => 'food-e-sagre', 'venue_types' => ['ristorante'], 'day' => 3, 'time' => '19:30', 'price' => 35,
            'description' => 'Menù in quattro portate costruito sui piatti di casa: bigoli in salsa, baccalà alla vicentina con polenta, dolce della tradizione. Vini dei Colli Euganei in abbinamento.',
            'features' => ['prenotazione-obbligatoria', 'opzioni-vegetariane', 'pagamenti-elettronici'], 'accessible' => false],
        ['title' => 'Aperitivo jazz con il Trio Specola', 'category' => 'musica-dal-vivo', 'venue_types' => ['bar', 'pub'], 'day' => 3, 'time' => '19:00', 'price' => 0,
            'description' => 'Pianoforte, contrabbasso e batteria per un aperitivo lungo, tra standard e composizioni originali. Ingresso libero con consumazione.',
            'features' => ['punti-ristoro'], 'accessible' => false],
        ['title' => 'Incontro pubblico: abitare in città da studenti', 'category' => 'politica-e-attivismo', 'venue_types' => ['associazione', 'centro_sociale', 'circolo'], 'day' => 3, 'time' => '18:00', 'price' => 0,
            'description' => 'Affitti, contratti e diritti: associazioni studentesche e sindacato inquilini raccontano i casi più frequenti e cosa si può fare. Spazio alle domande dal pubblico.',
            'features' => ['posti-a-sedere'], 'accessible' => true],
        ['title' => 'Venerdì elettronico: house fino a tardi', 'category' => 'dj-set-nightlife', 'venue_types' => ['club'], 'day' => 4, 'time' => '22:30', 'price' => 12,
            'description' => 'Tre dj della scena del Nordest si alternano in consolle tra deep house e ritmi più veloci. Guardaroba al piano terra, ingresso riservato ai maggiorenni.',
            'features' => ['riservato-ai-maggiorenni', 'guardaroba-disponibile', 'suoni-ad-alto-volume'], 'accessible' => false],
        ['title' => 'Danza contemporanea: «Corpi in transito»', 'category' => 'teatro-e-danza', 'venue_types' => ['teatro'], 'day' => 4, 'time' => '21:00', 'price' => 18,
            'description' => 'Cinque danzatori su musica dal vivo raccontano partenze e arrivi, stazioni e attese. Spettacolo di cinquanta minuti senza intervallo, adatto anche ai ragazzi.',
            'features' => ['posti-a-sedere', 'posti-riservati-per-persone-con-disabilita'], 'accessible' => true],
        ['title' => 'Reading di poesia con violoncello', 'category' => 'libri-e-presentazioni', 'venue_types' => ['circolo', 'libreria', 'associazione'], 'day' => 4, 'time' => '19:00', 'price' => 0,
            'description' => 'Poeti del territorio leggono testi inediti accompagnati da un violoncello. Al termine microfono aperto per chi vuole leggere qualcosa di proprio.',
            'features' => ['posti-a-sedere'], 'accessible' => true],
        ['title' => 'Mercatino dell’antiquariato minore', 'category' => 'mercatini', 'venue_types' => ['spazio_pubblico'], 'day' => 5, 'time' => '09:00', 'price' => 0,
            'description' => 'Oltre sessanta espositori tra ceramiche, cartoline, libri usati, dischi e piccoli mobili. Si svolge all’aperto dalla mattina al tramonto.',
            'features' => ['allaperto', 'raggiungibile-con-mezzi-pubblici', 'animali-ammessi'], 'accessible' => true],
        ['title' => 'Corsa non competitiva lungo le mura cinquecentesche', 'category' => 'sport', 'venue_types' => ['spazio_pubblico'], 'day' => 5, 'time' => '09:30', 'price' => 5,
            'description' => 'Due percorsi, cinque e dieci chilometri, lungo il tracciato delle mura. Ristoro all’arrivo e maglietta ai primi duecento iscritti. Si corre anche con la pioggia.',
            'features' => ['allaperto', 'acqua-potabile-disponibile', 'evento-confermato-con-pioggia'], 'accessible' => false],
        ['title' => 'Laboratorio di aquiloni per famiglie', 'category' => 'bambini-e-famiglie', 'venue_types' => ['associazione', 'spazio_pubblico'], 'day' => 5, 'time' => '15:30', 'price' => 8,
            'description' => 'Canne, carta velina e spago: in un pomeriggio ogni famiglia costruisce il proprio aquilone e prova a farlo volare. Materiali inclusi, età consigliata dai cinque anni.',
            'features' => ['adatto-alle-famiglie', 'minori-accompagnati', 'allaperto'], 'accessible' => true, 'family' => true],
        ['title' => 'Concerto indie rock: tre band della scena veneta', 'category' => 'musica-dal-vivo', 'venue_types' => ['centro_sociale', 'club', 'circolo'], 'day' => 5, 'time' => '21:00', 'price' => 10,
            'description' => 'Tre gruppi usciti quest’anno con il primo disco, da Padova, Treviso e Bassano. Banchetto con dischi e magliette; apertura porte mezz’ora prima.',
            'features' => ['suoni-ad-alto-volume', 'pagamenti-elettronici'], 'accessible' => false],
        ['title' => 'Notte vinile: soul e funk su dischi originali', 'category' => 'dj-set-nightlife', 'venue_types' => ['club', 'bar'], 'day' => 5, 'time' => '23:00', 'price' => 10,
            'description' => 'Solo vinili, dalle etichette di Detroit e Memphis ai 45 giri italiani degli anni Settanta. Pista piccola e suono caldo, fino alle tre.',
            'features' => ['riservato-ai-maggiorenni', 'suoni-ad-alto-volume'], 'accessible' => false],
        ['title' => 'Passeggiata guidata tra portici e botteghe storiche', 'category' => 'altro', 'venue_types' => ['spazio_pubblico', 'associazione'], 'day' => 6, 'time' => '10:30', 'price' => 7,
            'description' => 'Due ore a piedi con una guida abilitata, dalle piazze ai portici meno frequentati, con soste in tre botteghe artigiane ancora attive. Partenza puntuale.',
            'features' => ['allaperto', 'prenotazione-obbligatoria'], 'accessible' => false],
        ['title' => 'Mercato contadino e degustazione di formaggi dei Colli', 'category' => 'food-e-sagre', 'venue_types' => ['spazio_pubblico', 'ristorante'], 'day' => 6, 'time' => '11:00', 'price' => 0,
            'description' => 'Produttori dei Colli Euganei e della Bassa con verdura di stagione, miele e formaggi. Alle undici degustazione guidata di tre stagionature.',
            'features' => ['allaperto', 'opzioni-vegetariane', 'solo-contanti'], 'accessible' => true],
        ['title' => 'Coro polifonico: canti popolari del Veneto', 'category' => 'musica-dal-vivo', 'venue_types' => ['teatro', 'circolo'], 'day' => 6, 'time' => '17:00', 'price' => 0,
            'description' => 'Trenta voci per un repertorio di canti di lavoro, di montagna e di osteria, con brevi racconti sulla loro origine. Ingresso libero fino a esaurimento posti.',
            'features' => ['posti-a-sedere', 'ingresso-fino-a-esaurimento-posti'], 'accessible' => true],
        ['title' => 'Documentari sul Brenta: proiezione e incontro con i registi', 'category' => 'cinema', 'venue_types' => ['cinema', 'associazione'], 'day' => 6, 'time' => '18:30', 'price' => 5,
            'description' => 'Due cortometraggi documentari sul fiume e su chi ci vive e lavora. Dopo la proiezione i registi raccontano le riprese e rispondono alle domande.',
            'features' => ['posti-a-sedere', 'sottotitoli-disponibili'], 'accessible' => true],
        ['title' => 'Laboratorio di scrittura: raccontare la propria città', 'category' => 'corsi-e-workshop', 'venue_types' => ['libreria', 'associazione'], 'day' => 6, 'time' => '15:00', 'price' => 20,
            'description' => 'Un pomeriggio di esercizi di scrittura a partire da luoghi, voci e ricordi di Padova. Si lavora in piccoli gruppi; non serve esperienza.',
            'features' => ['prenotazione-obbligatoria', 'posti-a-sedere'], 'accessible' => true],
    ],

    'people' => [
        ['handle' => 'giulia_bassan', 'name' => 'Giulia Bassan', 'bio' => 'Fotografa per passione, sempre a caccia di concerti piccoli e mostre fuori dai giri soliti.', 'visibility' => 'public', 'featured' => true],
        ['handle' => 'marco_zanon', 'name' => 'Marco Zanon', 'bio' => 'Ingegnere di giorno, bassista la sera. Ai concerti sto in fondo, vicino al mixer.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'elena_rizzato', 'name' => 'Elena Rizzato', 'bio' => 'Maestra e mamma di due. Cerco eventi dove i bambini siano davvero i benvenuti.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'davide_pavan', 'name' => 'Davide Pavan', 'bio' => 'Cinema d’essai, libri usati e bici. Padova la giro tutta sui pedali.', 'visibility' => 'public', 'featured' => true],
        ['handle' => 'sara_boscolo', 'name' => 'Sara Boscolo', 'bio' => 'Studio lettere. Reading, teatro, qualunque cosa abbia un microfono aperto.', 'visibility' => 'members', 'featured' => false],
        ['handle' => 'luca_menegazzo', 'name' => 'Luca Menegazzo', 'bio' => 'Organizzo cene con gli amici e poi recensisco tutto, anche il pane.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'chiara_fontana', 'name' => 'Chiara Fontana', 'bio' => 'Danzatrice, insegno contemporaneo ai ragazzi. Vado a vedere tutto ciò che si muove.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'andrea_callegaro', 'name' => 'Andrea Callegaro', 'bio' => 'Dj il venerdì, archivista il lunedì. Collezionista di vinili soul.', 'visibility' => 'public', 'featured' => true],
        ['handle' => 'francesca_trevisan', 'name' => 'Francesca Trevisan', 'bio' => 'Architetta. Mi interessano i quartieri, le piazze e chi le abita.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'matteo_gallo', 'name' => 'Matteo Gallo', 'bio' => 'Corro lungo le mura all’alba, poi colazione in piazza.', 'visibility' => 'members', 'featured' => false],
        ['handle' => 'alessia_toffanin', 'name' => 'Alessia Toffanin', 'bio' => 'Illustratrice. Mercatini, gallerie e laboratori creativi sono casa mia.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'nicola_carraro', 'name' => 'Nicola Carraro', 'bio' => 'Volontario in un circolo di quartiere. Assemblee, feste di strada, tornei di briscola.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'martina_schiavon', 'name' => 'Martina Schiavon', 'bio' => 'Scrivo di musica per una webzine locale. Se c’è una band nuova, ci sono.', 'visibility' => 'public', 'featured' => true],
        ['handle' => 'paolo_marcato', 'name' => 'Paolo Marcato', 'bio' => 'Pensionato curioso: visite guidate, cori e storia della città.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'valentina_bortolami', 'name' => 'Valentina Bortolami', 'bio' => 'Infermiera a turni: quando sono libera voglio musica dal vivo e buon cibo.', 'visibility' => 'members', 'featured' => false],
        ['handle' => 'riccardo_sartori', 'name' => 'Riccardo Sartori', 'bio' => 'Papà a tempo pieno nel weekend. Aquiloni, parchi e laboratori per piccoli.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'irene_moro', 'name' => 'Irene Moro', 'bio' => 'Cinefila: rassegne, festival e documentari. Mi trovate in terza fila.', 'visibility' => 'public', 'featured' => false],
        ['handle' => 'tommaso_lazzaro', 'name' => 'Tommaso Lazzaro', 'bio' => 'Fuori sede da Bari, scopro Padova un evento alla volta.', 'visibility' => 'public', 'featured' => false],
    ],

    /*
     * [persona, evento, intento, testo]. L'evento è l'indice in `events`
     * oppure `investor:N`, la N-esima data futura del catalogo investitori
     * (`events:investor-demo`) in ordine di inizio: se quel catalogo manca,
     * il post si salta e il resoconto lo dice.
     */
    'posts' => [
        [3, 0, 'attend', 'Cabiria sul grande schermo non capita spesso. Io ci sono, terza fila come sempre: chi viene?'],
        [16, 0, 'recommend', 'Il restauro è bellissimo, l’ho visto a un festival in primavera. Se non l’avete mai visto in sala, è l’occasione.'],
        [4, 1, 'attend', 'Ho letto due racconti in anteprima: quello sulla Riviera dei Ponti Romani vale da solo la serata.'],
        [1, 2, 'attend', 'Porto il basso. Se qualcuno suona l’armonica ci mettiamo d’accordo prima.'],
        [0, 3, 'attend', 'Finalmente un corso di notturna in centro! Porto il cavalletto piccolo, se a qualcuno serve lo presto.'],
        [6, 4, 'recommend', 'Ho visto un pezzo delle prove la settimana scorsa: l’Arlecchino rider fa ridere davvero, e non è solo una trovata.'],
        [8, 5, 'attend', 'Se abitate in zona venite: le osservazioni scritte contano più di quanto si pensi.'],
        [11, 5, 'recommend', 'Al circolo ne parliamo da mesi. Portate le vostre foto dei punti pericolosi, aiutano i tecnici.'],
        [2, 6, 'attend', 'I miei due ci vanno ogni volta che possono. Ambiente tranquillo, perfetto anche per i più timidi.'],
        [15, 6, 'recommend', 'Letture fatte benissimo e nessuno che ti guarda storto se il piccolo si alza e cammina.'],
        [12, 7, 'recommend', 'Haydn e Piazzolla nella stessa sera: accostamento coraggioso, e a sentire le prove funziona.'],
        [13, 7, 'attend', 'Musica da camera all’aperto: mi porto il cuscino per la sedia.'],
        [10, 8, 'attend', 'Le stampe in bianco e nero della laguna d’inverno sono proprio il mio genere. Ci vediamo all’inaugurazione.'],
        [0, 8, 'recommend', 'Ho visto alcune foto in anteprima: la nebbia resa così è difficilissima. Da non perdere.'],
        [5, 9, 'attend', 'Bigoli in salsa e baccalà: ho già prenotato per quattro. Poi vi dico com’era il pane.'],
        [14, 9, 'recommend', 'Ci sono stata l’anno scorso a una cena simile: porzioni vere e vini scelti bene.'],
        [1, 10, 'recommend', 'Il Trio Specola ha un contrabbassista che vale il viaggio. Aperitivo perfetto per il giovedì.'],
        [17, 11, 'attend', 'Da fuori sede è un tema che mi tocca da vicino. Vado a sentire, magari imparo come leggere un contratto.'],
        [7, 12, 'attend', 'Suono il secondo set. Chi passa, venga a salutare in consolle.'],
        [6, 13, 'recommend', 'Una delle compagnie più interessanti in giro adesso. Cinquanta minuti che passano in un attimo.'],
        [4, 14, 'attend', 'Microfono aperto alla fine: forse leggo una cosa mia. Forse.'],
        [10, 15, 'attend', 'Sabato mattina al mercatino: cerco cartoline vecchie di Padova, se le vedete fatemi un fischio.'],
        [9, 16, 'attend', 'Dieci chilometri sulle mura, poi colazione. Chi corre il cinque parte con me e poi mi aspetta.'],
        [15, 17, 'attend', 'Aquilone numero tre in arrivo: quello dell’anno scorso vola ancora.'],
        [12, 18, 'recommend', 'Tre band nuove in una sera sola: la seconda è quella da tenere d’occhio, fidatevi.'],
        [7, 19, 'recommend', 'Solo vinili, niente chiavette. Se amate il soul non c’è posto migliore in città sabato notte.'],
        [13, 20, 'attend', 'Le botteghe dei portici sono la memoria della città. Porto anche mia nipote.'],
        [5, 21, 'recommend', 'I formaggi dei Colli meritano la gita: la degustazione delle undici è fatta con passione.'],
        [13, 22, 'recommend', 'Canti che cantava mio nonno. Chi ha voglia di un pomeriggio diverso, vada.'],
        [16, 23, 'attend', 'Documentari sul Brenta e registi in sala: domenica sera sono lì.'],
        [17, 24, 'attend', 'Voglio provare a raccontare la mia Padova da fuori sede. Un po’ di paura, ma ci provo.'],
        [3, 'investor:0', 'recommend', 'Segnato in agenda: sembra proprio il genere di serata che mancava in città.'],
        [8, 'investor:1', 'attend', 'Ci vado con due colleghe dello studio. Qualcun altro si aggrega?'],
        [11, 'investor:2', 'recommend', 'Me ne hanno parlato bene al circolo, lo passo a chi cerca qualcosa di diverso nel weekend.'],
        [14, 'investor:3', 'attend', 'Per una volta sono libera dai turni: ci sono!'],
        [2, 'investor:4', 'recommend', 'Adatto anche a chi ha bambini? Da quello che leggo sì, lo consiglio alle altre mamme della classe.'],
        [9, 'investor:5', 'attend', 'Ci vado dopo l’allenamento, se qualcuno è in zona ci vediamo lì.'],
    ],

    /*
     * [post, persona, testo, risposta a (indice in questo elenco) o null].
     * Le risposte stanno sempre dopo il commento a cui rispondono.
     */
    'post_comments' => [
        [0, 16, 'Vengo anch’io! Terza fila confermata, tienimi un posto.', null],
        [0, 0, 'Posso unirmi? Non l’ho mai visto in sala.', null],
        [0, 3, 'Certo Giulia, ci troviamo davanti all’ingresso dieci minuti prima.', 1],
        [3, 12, 'Se c’è un batterista libero mi piacerebbe sentirvi, passo a fine serata.', null],
        [4, 10, 'Io ho solo lo smartphone, va bene lo stesso?', null],
        [4, 0, 'Sì, il corso lo dice chiaramente: basta anche il telefono. Porta una powerbank!', 4],
        [6, 11, 'Ci sono anch’io, porto la mappa con i punti critici di via Tiziano Aspetti.', null],
        [6, 17, 'Io abito all’Arcella da un mese: posso venire anche se non sono residente?', null],
        [6, 8, 'Certo, è aperta a tutti. Anzi, serve anche il punto di vista di chi ci è arrivato da poco.', 7],
        [8, 15, 'Vale anche per i due anni o sono troppo piccoli?', null],
        [8, 2, 'Il mio piccolo ha iniziato a due anni e mezzo: se sta seduto dieci minuti va benissimo.', 9],
        [10, 1, 'Piazzolla per archi è una cosa seria. Ci sarò.', null],
        [14, 14, 'Com’era il pane alla fine? Lo voglio sapere!', null],
        [14, 5, 'Ve lo dico venerdì, promesso. Aspettative altissime.', 12],
        [16, 12, 'Confermo, ottimo trio. Il pianista arrangia benissimo gli standard.', null],
        [18, 1, 'Passo verso mezzanotte, tienimi un pezzo buono!', null],
        [18, 12, 'Lo segno per la webzine: un report della serata ci sta.', null],
        [22, 3, 'Corro anch’io il cinque, ci vediamo all’arrivo per la colazione.', null],
        [23, 2, 'Veniamo anche noi con i bambini, vediamo chi vola più in alto!', null],
        [23, 15, 'Sfida accettata Elena. Porto lo spago di riserva.', 18],
        [25, 1, 'Soul su vinile a Padova: finalmente. Ci sono.', null],
        [28, 6, 'Che bello, mia nonna li cantava sempre. Grazie per la segnalazione.', null],
        [30, 4, 'Vengo anch’io al laboratorio, magari scriviamo a quattro mani.', null],
        [30, 17, 'Volentieri Sara, ci vediamo lì!', 22],
    ],

    /*
     * I commenti pubblici alla scheda dell'evento, con risposte e reazioni.
     * [evento, persona, testo, risposte [[persona, testo]], reazioni [[persona, tipo]]].
     */
    'event_comments' => [
        [0, 16, 'Qualcuno sa se c’è l’introduzione anche per chi arriva alle 20:40?', [[3, 'L’introduzione dura dieci minuti, il film parte verso le 20:45.']], [[3, 'useful'], [0, 'like']]],
        [3, 10, 'Il ritrovo è davanti alla sede o direttamente in piazza?', [[0, 'Di solito ci si trova in sede per le istruzioni, poi si esce tutti insieme.']], [[0, 'useful']]],
        [5, 11, 'Porterò le fotografie dei punti dove si rischia di più: se ne avete, mandatemele prima.', [[8, 'Ne ho alcune di via Aspetti, te le porto stampate.']], [[8, 'love'], [17, 'like']]],
        [6, 2, 'Ci sono seggioline anche per gli adulti o si sta sul tappeto?', [[15, 'Ci sono alcune sedie in fondo alla sala, ma sul tappeto si sta benissimo.']], [[15, 'useful'], [10, 'like']]],
        [7, 13, 'In caso di pioggia il biglietto resta valido per la sala interna?', [[12, 'Sì, il concerto si sposta dentro e il biglietto vale lo stesso.']], [[13, 'useful'], [1, 'like']]],
        [9, 14, 'C’è un’alternativa senza pesce per chi non lo mangia?', [[5, 'Chiedendo alla prenotazione preparano un secondo di verdure, l’ho fatto la volta scorsa.']], [[14, 'useful']]],
        [12, 7, 'Il secondo set è mio, dalle 00:30. Ci vediamo in pista!', [[1, 'Arrivo per il tuo set!'], [12, 'Presente anche io, poi ne scrivo sulla webzine.']], [[1, 'love'], [12, 'like'], [0, 'like']]],
        [16, 9, 'Il percorso da dieci è tutto asfaltato o ci sono tratti sterrati?', [[3, 'Un breve tratto in ghiaia vicino al bastione, niente di impegnativo.']], [[9, 'useful']]],
        [17, 15, 'Per i bambini sotto i cinque anni c’è qualcosa di più semplice da costruire?', [[2, 'L’anno scorso preparavano una versione piccola, a forma di pesce.']], [[15, 'useful'], [2, 'like']]],
        [22, 13, 'Il coro è quello che ha cantato in Santa Sofia a Natale? Erano bravissimi.', [], [[6, 'love']]],
    ],

    /*
     * [persona, evento, voto, testo]. Il locale recensito è quello dell'evento
     * indicato (indice in `events`): è una chiave stabile fra un'esecuzione e
     * l'altra, mentre una posizione fra i locali approvati della città slitta
     * appena la redazione ne approva uno nuovo. Le recensioni nascono in
     * attesa di moderazione, non pubblicate.
     */
    'venue_reviews' => [
        [5, 9, 5, 'Personale gentile e programmazione curata. Ci torno volentieri.'],
        [12, 18, 4, 'Buona acustica e palco ben visibile anche dal fondo. Un po’ affollato nelle serate grandi.'],
        [3, 0, 5, 'Rastrelliera per le bici davanti all’ingresso e rassegne che non si trovano altrove.'],
        [13, 1, 4, 'Posto accogliente, ideale per un pomeriggio tranquillo. Bagni accessibili.'],
        [2, 6, 5, 'Spazio pensato anche per le famiglie: fasciatoio e posto per i passeggini.'],
        [14, 10, 4, 'Serate ben organizzate, prezzi onesti.'],
    ],

    /*
     * Passaggi offerti verso gli eventi della settimana.
     * [conducente, evento, tratta, zona di partenza, ora locale, posti, dettagli].
     * La tratta è `outbound` (andata) o `return` (ritorno); un ritorno con
     * un'ora precedente all'inizio dell'evento vale per il giorno dopo (03:15
     * dopo una serata del venerdì è sabato notte). Dettagli facoltativi:
     * note, stops, accessibility, accessibility_note. Ogni conducente ha una
     * sola offerta per data e tratta, come impone il servizio.
     */
    'rides' => [
        [1, 2, 'outbound', 'Arcella, piazzale Azzurri d’Italia', '20:50', 3, ['note' => 'Porto il basso: nel bagagliaio resta posto per un altro strumento piccolo.']],
        [1, 2, 'return', 'Arcella, piazzale Azzurri d’Italia', '23:45', 3, ['note' => 'Riparto a fine jam, verso mezzanotte meno un quarto.']],
        [7, 12, 'outbound', 'Stazione di Padova, lato via Tommaseo', '21:40', 4, ['note' => 'Suono il secondo set: arrivo presto e resto fino alla fine.', 'stops' => ['Piazzale Stanga']]],
        [7, 12, 'return', 'Stazione di Padova, lato via Tommaseo', '03:15', 4, ['note' => 'Ritorno dopo la chiusura, lungo la strada per l’Arcella.', 'stops' => ['Arcella', 'Pontevigodarzere']]],
        [5, 9, 'outbound', 'Guizza, capolinea del tram', '19:00', 3, ['note' => 'Ho prenotato alla stessa cena: chi viene anche lui è il benvenuto.']],
        [5, 21, 'outbound', 'Guizza, capolinea del tram', '10:30', 3, ['note' => 'Mercato e degustazione, poi pranzo: rientro nel primo pomeriggio.']],
        [13, 7, 'outbound', 'Sacra Famiglia, sagrato della chiesa', '20:10', 3, ['note' => 'Guido piano e parto puntuale.', 'accessibility' => 'folding_chair', 'accessibility_note' => 'Nel bagagliaio entra una sedia a rotelle pieghevole.']],
        [13, 22, 'outbound', 'Sacra Famiglia, sagrato della chiesa', '16:20', 3, ['accessibility' => 'folding_chair', 'accessibility_note' => 'Nel bagagliaio entra una sedia a rotelle pieghevole.']],
        [13, 22, 'return', 'Sacra Famiglia, sagrato della chiesa', '19:15', 3, []],
        [2, 4, 'outbound', 'Mandria, via Romana Aponense', '20:20', 3, ['note' => 'Posso passare dal Bassanello, basta dirmelo.', 'stops' => ['Bassanello']]],
        [2, 13, 'outbound', 'Mandria, via Romana Aponense', '20:15', 3, []],
        [12, 18, 'outbound', 'Chiesanuova, piazza della chiesa', '20:20', 3, ['note' => 'Ho il banchetto della webzine da montare: parto un po’ prima.']],
        [12, 18, 'return', 'Chiesanuova, piazza della chiesa', '00:30', 3, []],
    ],

    /*
     * Richieste di posto: [passaggio, persona, posti, stato, nota o null].
     * Lo stato è accepted, pending, declined o withdrawn. I posti accettati
     * non superano mai quelli offerti e ognuno lascia almeno un posto libero,
     * così tutte le offerte restano cercabili. Chi chiede più di un posto
     * dichiara maggiorenni i compagni di viaggio, come nel servizio.
     */
    'ride_requests' => [
        [0, 12, 1, 'accepted', null],
        [0, 3, 1, 'accepted', 'Se serve porto io l’amplificatore piccolo.'],
        [0, 17, 1, 'pending', 'Prima volta alla jam: vengo solo ad ascoltare.'],
        [1, 12, 1, 'accepted', null],
        [1, 17, 1, 'pending', null],
        [2, 1, 2, 'accepted', 'Siamo in due, con mio cugino.'],
        [2, 0, 1, 'accepted', null],
        [2, 14, 1, 'declined', null],
        [3, 1, 2, 'accepted', null],
        [3, 0, 1, 'pending', null],
        [4, 14, 1, 'accepted', 'Ho la prenotazione per le 19:30 anch’io.'],
        [4, 11, 1, 'withdrawn', null],
        [5, 6, 1, 'accepted', null],
        [5, 10, 1, 'pending', 'Se possibile scendo prima, all’ingresso del mercato.'],
        [6, 1, 1, 'accepted', null],
        [6, 12, 1, 'accepted', null],
        [7, 6, 1, 'accepted', null],
        [7, 9, 1, 'withdrawn', null],
        [8, 6, 1, 'pending', null],
        [9, 4, 1, 'accepted', null],
        [9, 6, 1, 'accepted', null],
        [10, 4, 2, 'accepted', 'Vengo con un’amica.'],
        [10, 6, 1, 'pending', null],
        [11, 1, 1, 'accepted', null],
        [11, 17, 1, 'pending', null],
        [12, 1, 1, 'accepted', null],
        [12, 16, 1, 'declined', null],
    ],

    /*
     * Messaggi nella chat di un passaggio accettato: [richiesta, chi scrive, testo].
     * Chi scrive è `driver` o `passenger`.
     */
    'ride_messages' => [
        [0, 'passenger', 'Ciao Marco! Ci troviamo al piazzale alle 20:50?'],
        [0, 'driver', 'Sì, sono con una Panda grigia davanti all’edicola. A dopo!'],
        [5, 'passenger', 'Ciao Andrea, siamo in due: va bene se portiamo una custodia di chitarra?'],
        [5, 'driver', 'Nessun problema, nel bagagliaio ci sono solo le borse dei dischi.'],
        [14, 'passenger', 'Buongiorno Paolo, se piove il passaggio vale lo stesso?'],
        [14, 'driver', 'Certo: il concerto si sposta nella sala interna. Alle 20:10 davanti alla chiesa.'],
        [19, 'driver', 'Ciao Sara, passo dal Bassanello alle 20:30: ti va bene lì?'],
        [19, 'passenger', 'Perfetto, ti aspetto alla fermata del tram. Grazie!'],
    ],

    /*
     * Viaggi già conclusi con la recensione del passeggero al conducente:
     * [conducente, passeggero, zona di partenza, voto, testo]. Il servizio
     * accetta recensioni solo dopo la partenza, quindi ognuno si appoggia a
     * una data **già passata** del catalogo investitori, dalla più vecchia in
     * avanti; senza date passate le recensioni si saltano e il resoconto lo dice.
     */
    'ride_reviews' => [
        [5, 14, 'Guizza, capolinea del tram', 5, 'Puntuale, macchina pulita e chiacchiere piacevoli. La benzina l’abbiamo divisa senza problemi.'],
        [7, 0, 'Stazione di Padova, lato via Tommaseo', 5, 'Andrea mi ha presa in stazione e riportata a casa dopo il concerto. Gentilissimo.'],
        [13, 6, 'Sacra Famiglia, sagrato della chiesa', 5, 'Guida tranquilla e tante storie sulla città lungo la strada.'],
        [1, 12, 'Arcella, piazzale Azzurri d’Italia', 4, 'Tutto bene: dieci minuti di ritardo, avvisati per tempo.'],
        [2, 4, 'Mandria, via Romana Aponense', 5, 'Precisa e cordiale, si parte all’ora detta.'],
        [12, 3, 'Chiesanuova, piazza della chiesa', 4, 'Viaggio piacevole e musica scelta bene.'],
    ],
];
