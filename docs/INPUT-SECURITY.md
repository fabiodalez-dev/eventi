# Campi dei locali: verifica e criteri di sicurezza

Verifica applicativa dell'11 settembre 2026. Ambito: pannello locale, campi condivisi con organizzatori, proposte/accreditamento pubblici e configurazione ticketing. Non è una certificazione OWASP ASVS né un penetration test dell'infrastruttura di produzione.

## Criteri

- Validazione server per tipo, lunghezza, intervallo e valori ammessi; i vincoli HTML non costituiscono una barriera di sicurezza.
- Rich text solo nei testi destinati alla lettura: editor visuale Filament, senza Markdown, sorgente HTML o allegati. I moduli pubblici usano lo stesso motore Tiptap, caricato solo dove serve.
- Allowlist HTML: paragrafi, interruzioni, grassetto, corsivo, sottolineato, barrato, titoli H2/H3, elenchi, citazioni e link. Nessun attributo CSS, classe, ID, gestore di eventi, iframe, SVG, immagine o elemento attivo. Link rich text HTTP/HTTPS/mailto o relativi; URL di siti/biglietti/prenotazioni HTTP/HTTPS.
- Sanitizzazione in scrittura sui modelli coinvolti e nuovamente nel componente di visualizzazione: anche dati storici non bonificati non devono eseguire markup attivo. Gli aggiornamenti bulk del query builder non attivano gli eventi Eloquent: non vanno usati per salvare input non fidato senza validazione esplicita.
- Nei campi semplici il markup viene eliminato; apostrofi e parole come `SELECT` restano testo legittimo. SQL injection si previene con parametri associati alle query, non cancellando parole dal testo.
- Identificatori tenant e campi redazionali non sono input affidabili: policy, query circoscritte al locale, stato Livewire bloccato e allowlist delle colonne scrivibili.
- Le API esistenti continuano a fornire testo semplice per descrizioni, istruzioni e dettagli editoriali, evitando markup letterale nei client mobili.

## Inventario dei campi e controlli

I nomi separati da virgola indicano campi distinti con lo stesso contratto. `*` indica ogni riga di un ripetitore, non un'esenzione dal controllo.

| Superficie / campi | Contratto e punto di controllo |
| --- | --- |
| Evento: `title` | Stringa richiesta, massimo 255; testo semplice. `EventFields`, validatore server aggiuntivo nel salvataggio automatico `CreateEvent::persist`. |
| Evento: `description` | Rich text, massimo 50.000; `DescriptionEditor`, `EditorContent`, `Description`. Lo stato Tiptap JSON delle bozze viene convertito e sanitizzato prima del salvataggio. |
| Evento: `category_id`, `price_type` | Categoria del catalogo attivo; tipo prezzo da enum. Anche il salvataggio diretto della bozza verifica esistenza/enum. |
| Evento: `price_min`, `price_max` | Numerici non negativi, limite 999.999,99 nel pannello locale e nell'autosalvataggio. |
| Evento: `ticket_url`, `booking_url`, `organizer_url` | URL HTTP/HTTPS, limiti del modulo; controllo ulteriore sul modello e `SafeUrl` nei collegamenti pubblici. |
| Evento: `organizer_name` | Testo semplice, massimo 180 nel modulo condiviso. |
| Evento: `tags` e creazione `name` | Selezione dalla relazione, massimo 50; nuovo nome massimo 80, normalizzazione, autorizzazione nel servizio `CreateSharedEventTag`; un tag non approvato non viene ripubblicato. |
| Evento: `external_links.*.label`, `.url` | Massimo 8 righe; regola `ExternalLinks` e DTO: etichetta limitata, URL con schema/host ammessi e senza credenziali. |
| Evento/locale: `facts` / `info`, `*.label`, `*.value` | Massimo 12 righe; `FactRows` e DTO validano struttura e lunghezze; contenuto semplice. |
| Listino: `ticketTiers.*.name`, `.price`, `.status`, `.url`, `.note` | Massimo 12 righe, nome/note 255, prezzo 0–999999, stato da enum, URL web; relazione del record corrente e sanitizzazione `TicketTier`. |
| Date: `starts_at`, `ends_at` | Date/orari validi, fine successiva all'inizio, conversione nel fuso della città. |
| Date: `capacity`, `capacity_left`, `highlight` | Capienze intere 0–1.000.000; evidenza testuale massimo 40. |
| Date: `lineups.*.name`, `.role` | Massimo 100 righe, nome massimo 255, ruolo da enum; relazione circoscritta all'occorrenza e sanitizzazione `Lineup`. |
| Date: stato, `status_note`, ambito modifica | Azioni autorizzate, stati/ambito da elenco; nota formattata nel pannello organizzatori e sanitizzata anche in uscita. |
| Ripetizione: `repeat`, `frequency`, `weekdays`, `until` | Booleano, frequenza/giorni da enum, data; generazione tramite `RecurrenceRule` e `GenerateOccurrencesAction`, non esecuzione di codice del locale. |
| Locale: `venue_name`, `venue_type` | Informazioni non deidratate: non autorizzano la modifica dell'identità del locale. |
| Locale: `short_description`, `description`, `membership_notes` | Riassunto semplice; descrizione e istruzioni tessera visuali, con limiti del rispettivo componente e sanitizzazione. |
| Locale: `address`, `address_extra`, `postal_code` | Stringhe limitate rispettivamente a 255, 255, 10; testo semplice. Il CAP resta compatibile con formati esteri. |
| Locale: `municipality`, `zone` | Selezioni dal catalogo geografico; quartiere pertinente al comune, non una query/colonna scelta dal client. |
| Locale: `lat`, `lng`, `mappa` | Latitudine −90…90, longitudine −180…180 anche nei campi nascosti; il componente mappa non è un'autorizzazione. |
| Locale: `opening_hours.*.day`, `.open`, `.close` | Massimo 28 intervalli, giorno da elenco, orari validi; conversione server alla mappa per giorno. La chiusura dopo mezzanotte è legittima. |
| Locale: `capacity`, `requires_membership` | Capienza intera 0–1.000.000; booleano per tessera. |
| Locale: `phone`, `email`, `website` | Telefono con caratteri telefonici e limite 40; email valida massimo 255; URL web massimo 255. La sintassi non prova la titolarità del recapito. |
| Locale: `socials.*.key`, `.value` | Massimo 12 righe, chiave testuale massimo 50, URL HTTP/HTTPS massimo 2048. Validazione della rappresentazione chiave/valore effettivamente inviata da Filament. |
| Locale: `transit.*.mode`, `.text` | Mezzo da enum, numero righe e lunghezza da DTO; regola server `TransitRows`. |
| Locale: `accessibility.*` | Funzionalità dichiarate dal catalogo e stato sì/no/non dichiarato tramite DTO; nessuna icona o classe CSS arbitraria. |
| Immagini: poster, logo, copertina, galleria | `ImageUpload`/`RealImage`: tipo dai byte, corrispondenza estensione, dimensioni/peso, pipeline di ricodifica; galleria massimo 30. Rich editor: allegati disabilitati anche lato server. |
| Collaboratori: `name`, `email`, destinatario rimozione | Nome/email massimo 255, email valida; nome ripulito. Solo referente autorizzato, ruolo editor imposto sul server. Rimozione vietata ai referenti anche nell'azione, non soltanto nascosta. |
| Import: `url`, `exclude_keywords.*` | URL massimo 1000, HTTP/HTTPS e `ImportUrlGuard` contro indirizzi interni, redirect ricontrollati, timeout/peso massimo download; massimo 100 esclusioni di 100 caratteri. Metodi pubblici autorizzati; flag anteprima `Locked`. |
| Social studio: `event`, `cityId`, `date`, `selected`, `format`, `imageFit`, `graphicTitle`, booleani | Evento autorizzato, città derivata dal locale; data ISO, massimo 100 ID appartenenti ai risultati autorizzati, formato/adattamento da elenco, titolo massimo 300; booleani tipizzati. Batch autorizzato e circoscritto al locale, ID bloccato. Pubblicazione/programmazione riservata allo staff. |
| Sponsorizzazioni: `grant`, `event`, ID campagna | Query limitate al locale, policy e servizio `GrantCampaigns`; il client non assegna budget, proprietario o autorizzazioni. |
| Ticketing: `booking_enabled`, `booking_waitlist`, `booking_capacity`, `booking_limit` | Booleani, capienza intera 1–1.000.000, limite intero 1–20; policy `Booking::manage`, transazione e controllo posti già occupati. |
| Ticketing: `booking_opens_at`, `booking_closes_at`, `cancellation_closes_at`, `booking_instructions` | Date e ordine apertura/chiusura; istruzioni rich text massimo 3000. |
| Ticketing: `booking_fields.phone/address/city/postal_code/country` | Solo chiavi previste e valori hidden/optional/required; email account non modificabile dalle impostazioni del locale. |
| Proposta pubblica: `title`, `starts_at_hint`, `venue_id`, `venue_hint`, `raw_text`, `contact_name`, `contact_email` | `StoreEventSubmissionRequest`: stringhe limitate, data, ID esistente non eliminato, email valida; descrizione visuale massimo 5000. Solo dati validati salvati; anti-CSRF e antispam. |
| Accreditamento: `venue_name`, `type`, `address`, `website`, `contact_name`, `contact_role`, `contact_phone`, `contact_email`, `message` | `StoreVenueApplicationRequest`: limiti specifici, enum, URL web, email, verifica telefono italiano/internazionale; messaggio visuale massimo 5000; anti-CSRF e antispam. |

## Campi editoriali annidati condivisi

| Campi sotto `content_details` / `seo` | Contratto |
| --- | --- |
| `introduction`; `city_introductions.*.city_id/text` | Introduzione rich text massimo 5000; città esistente/distinta nei contenuti tassonomici. |
| `membership`, `accessibility`, `parking_type`, `attendance_mode`, `organizer_type` | Elenchi chiusi/enum. |
| `membership_notes`, `accessibility_notes`, `parking_notes`, `transit_notes`, `entrance_notes` | Rich text massimo 2000. |
| `feature_ids` | ID caratteristiche attive non di sistema; creazione protetta da policy. |
| `practical_custom.*.label/icon/text` | Massimo 12; titolo 120, icona dal catalogo chiuso, rich text 1000. |
| `organizer_venue_id`, `minimum_age`, `online_url` | Locale approvato, età intera 0–120, URL massimo 2048 richiesto per online/misto. |
| `mandatory_costs`, `weather_policy`, `minors_policy`, `cancellation_policy`, `refund_policy`, `public_contact`, `poster_caption`, `poster_credit` | Rich text massimo 2000. |
| `poster_alt` | Testo semplice massimo 2000, destinato a un attributo escapato, mai un editor HTML. |
| `agenda.*.title/when/starts_at/ends_at/occurrence_id/speaker/description` | Massimo 50 righe; titolo/speaker 180, quando 120, date ordinate, occorrenza dello stesso evento, descrizione rich text 5000. |
| `faqs.*.question/answer` | Massimo 30, domanda semplice 250, risposta rich text 5000. |
| `seo.title/description/image/indexing` | Titolo semplice 180, descrizione semplice 400, URL immagine 2048; indicizzazione solo amministrativa. Chiavi sconosciute escluse dal salvataggio. |
| Campi amministrativi e tenant aggiunti alla richiesta | Non fanno parte dell'allowlist di `EditEvent`; il wizard assegna città, locale, creatore, stato e provenienza lato server. |

## Verifiche e limiti

Test mirati in `tests/Feature/Security/EditorContentTest.php`, `Venue/EventWizardTest.php`, `Venue/VenueProfileTest.php`, oltre alle suite esistenti di isolamento, CSRF, URL, media, import e ticketing. Il test browser in `tests/Browser/PublicFlowsTest.php` verifica toolbar e persistenza del grassetto.

Risultati locali:

- Suite PHP completa finale: 2127 test, 8357 asserzioni, tutti superati. I test sui dati storici inseriscono intenzionalmente il payload senza eventi Eloquent, per verificare l'escape in uscita indipendentemente dalla nuova sanitizzazione in scrittura.
- Browser desktop/mobile: 6 test, 48 asserzioni, superati.
- JavaScript e controlli della pipeline: 56 test superati.
- PHPStan: nessun errore. Pint e `git diff --check`: superati.
- Build Vite: superata; resta l'avviso sulle dimensioni dei bundle della mappa.
- Deptrac: nessuna violazione attiva; 2 eccezioni preesistenti e 4434 dipendenze non coperte dalle regole architetturali.
- `composer audit --locked` e `npm audit`: nessuna vulnerabilità nota riportata al momento del controllo.
- Licenze verificate nei pacchetti installati: Filament Forms, Tiptap Core/StarterKit e Symfony HtmlSanitizer sono MIT.

Questa verifica non dimostra l'assenza di ogni vulnerabilità: configurazione del database di produzione, privilegi dell'utenza DB, rete/egress, DNS rebinding, dipendenze e protezioni del server richiedono verifiche infrastrutturali dedicate. I link esterni consentiti possono comunque portare a siti non affidabili. Nessuna scansione aggressiva o bonifica massiva del database di produzione è stata eseguita.

Riferimenti: [OWASP Input Validation](https://cheatsheetseries.owasp.org/cheatsheets/Input_Validation_Cheat_Sheet.html), [OWASP XSS Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html), [OWASP SQL Injection Prevention](https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html).
