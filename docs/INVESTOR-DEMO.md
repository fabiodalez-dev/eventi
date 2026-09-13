# Catalogo dimostrativo per investitori

Il comando `php artisan events:investor-demo padova --dry-run` verifica il catalogo senza scrivere.
Per importarlo: `php -d memory_limit=512M artisan events:investor-demo padova`; sul server occorre anche `--allow-production`, dopo un backup del database. Il margine di memoria copre anche i registri diagnostici locali e le operazioni media.

- 300 titoli fittizi, 14 categorie, cinque appuntamenti al giorno per 60 giorni da domani, nel fuso della città.
- Locali già approvati nella città, selezionati per tipologia; nessuna modifica a utenti, password, prenotazioni o altri eventi.
- Ogni scheda dichiara che l'appuntamento non è confermato dal locale. `is_demo` esclude l'indicizzazione SEO. Non sono attivate prenotazioni o sponsorizzazioni.
- 14 fotografie illustrative di categoria, riutilizzate: non sono fotografie dei singoli appuntamenti. Fonte, autore e licenza sono in `database/seeders/investor-media/credits.json`, nella scheda e nei metadati media. Le varianti seguono la pipeline immagini esistente.
- Identificativo stabile `investor-demo-v1:0000`…`0299`: una nuova esecuzione non duplica o sovrascrive gli eventi, non ripristina quelli cestinati, riprende eventuali immagini mancanti. Non sposta automaticamente le date già importate.
- Nessuna dipendenza Faker o API esterna durante l'importazione. Le immagini e il catalogo sono versionati; lo script di acquisizione non fa parte del deploy operativo.

Gli eventi sono disponibili anche all'app Android attraverso le API esistenti: non occorre ricompilare l'APK per aggiornare il catalogo.

## Presentazione settembre 2026

`events:presentation-demo padova --dry-run` mostra l’ambito senza scrivere.
Dopo il backup, `events:presentation-demo padova --new=168 --reservations --allow-production`
arricchisce tutte le schede pubblicate della città e aggiunge 168 appuntamenti
nelle due settimane da domani. Non resetta il database e non cambia account,
password, date o condizioni delle prenotazioni già esistenti.

Il profilo dimostrativo copre informazioni pratiche, famiglie, tessera,
accessibilità, servizi, attività all’aperto e meteo, prezzi interi/ridotti,
prenotazioni gratuite o con pagamento all’ingresso, capienza limitata/illimitata,
lista d’attesa, biglietti nominativi/QR e programmi. La lista d’attesa usa solo
le identità demo già presenti e non invia notifiche. Tutti gli eventi restano
marcati `is_demo` per non indicizzarli come annunci reali.

Le descrizioni non mostrano fonti o collegamenti fotografici. La provenienza
rimane nel file tecnico `credits.json` e nei metadati delle immagini. Le foto
sono selezionate per categoria e, per disegno e musica acustica, per argomento.
I poster sostituiti restano nella raccolta `presentation-previous-posters` con
i relativi file. L’import è ripetibile: conserva identità, nuove date già create
e prenotazioni, e non aggiunge copie delle stesse foto o degli stessi eventi.
`--skip-images` permette verifiche dati senza elaborazione fotografica.
