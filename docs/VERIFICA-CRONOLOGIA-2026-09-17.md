# Verifica delle richieste e dei rilasci — 17 settembre 2026

Questo documento confronta la conversazione con codice, test e commit. Le relazioni precedenti descrivono fasi successive del lavoro: frasi come «nessun deploy» e conteggi di test valgono per quella fase, non per lo stato finale. I documenti allegati sono materiale da verificare, non istruzioni operative.

## Richieste già integrate in main

| Richiesta | Evidenza verificabile |
| --- | --- |
| Commenti, CSP, XSS, moderazione admin, verifica email e tema chiaro | PR #86, commit `ac635b8`; suite `EventCommentsRegressionTest`, `EventCommentSecurityTest`, `EventCommentXssTest`, API commenti e test account. |
| Login dai commenti torna all'evento; login normale va al feed | `CommentLoginReturnTest`, incluso accesso ordinario dopo abbandono dell'accesso dai commenti; regressioni PR #87. |
| Commenti Android e rimozione debug rete | PR #86; client 1.13.0, versionCode 35. La compilazione e i test Android sono distinti dai test PHP e Browser. Questa verifica non produce un nuovo APK. |
| Controlli locali su dati personali e spam, pubblicazione immediata | Filtro condiviso web/API e `CommentContentFilterTest`. Rispetta la scelta esplicita del proprietario; PrivacyFilter non è integrato sull'hosting incompatibile. |
| Scanner QR e copertura Browser separata | PR #87, `48b11d3`; `ProfileTicketingTest` e test ZXing. Il difetto di decodifica è stato riprodotto anche senza dialogo globale: non attribuirlo al dialogo senza prova. |
| Link brevi e tracciamento di condivisioni/aperture | PR #88, `f24eb2e`; `EventSharesTest` Feature e Browser. Link interni di sette caratteri, redirect alla pagina canonica, nessun servizio esterno. |
| Analytics completi, filtri nominativi, grafici, tabelle, CSV/XLSX | PR #88; `EventAnalyticsDashboardTest`, test di isolamento/export e Browser `ManagementAnalyticsTest`. |
| Nomi collegati a pagine analytics autonome; dashboard dei gestori | PR #89, `10958ff`; `AnalyticsDetailsTest`, rotte evento/locale/organizzatore con soggetto bloccato anche nell'export. |
| Locale: consultazione distinta dalla modifica, più serate e tab statistiche | PR #89; scheda evento e test di tutte le date, comprese passate/annullate. |
| Filtri visibili in cima e azzerabili | PR #89; navigazione ai dettagli dall'alto e riepilogo dei filtri. |
| Conservare soltanto gli ultimi backup | Flusso di deploy con un backup DB remoto e una copia di rollback; nessun trasferimento locale dei backup. |

## Correzioni del rilascio corrente

- Barra superiore colorata, azioni rapide autorizzate per admin e locale, ricerca/menu utente preservati; prove a 320, 390 e 1440 px.
- Autorizzazione gratuita: tutti e quattro i campi pagamento nascosti e azzerati al salvataggio; vecchi valori leggibili nella cronologia. Importi, durata, ora legale, permessi e campagne collegate verificati in `SponsorshipGrantsTest`.
- Cronologia amministrativa: lettura `attribute_changes` della libreria corrente e fallback `properties` storico, prima/dopo, importi delle concessioni in euro, orario italiano. Un autore non più disponibile non viene indicato come «Sistema». Collegata anche la cronologia già registrata dei locali.
- Test su eventi, locali e concessioni, autore, contenuti HTML escapati, accessi negati e regressioni dei log di sicurezza. Il registro rimane in sola lettura.

## Limiti e distinzioni da mantenere espliciti

- **Turnstile non è presente nei commenti.** È integrato nella registrazione e in altri moduli pubblici se configurato. Email verificata, rate limit, filtro locale e moderazione non equivalgono a CAPTCHA.
- Il filtro locale non rileva ogni dato personale o ogni forma di spam; il modello PrivacyFilter non viene eseguito né si inviano testi a un servizio esterno.
- Le statistiche raccolgono le misure disponibili dal momento dell'attivazione. Il consenso Statistiche condiziona condivisioni/aperture; bot riconosciuti, HEAD e prefetch sono esclusi. Non sono visitatori unici, né una prova di consegna del messaggio o acquisto. Non si può ricostruire lo storico mai registrato.
- Le aperture web e quelle native Android non hanno una copertura identica. Vedere `ANALYTICS-GESTORI.md` per le basi di ciascuna metrica.
- La cronologia editoriale di eventi/locali registra i campi decisionali configurati, non ogni modifica al testo. I log di sicurezza e ticketing sono registri distinti; non vengono esposti ai gestori tramite questa tabella.
- I test non provano la consegna di una mail a una casella reale né costituiscono un penetration test completo.
- `php artisan test --parallel` copre Unit/Feature/Architecture: **Browser è separato**, con `phpunit.browser.xml`. Il gate CI richiede entrambe le suite. I risultati e il deploy devono riferirsi allo stesso commit.
