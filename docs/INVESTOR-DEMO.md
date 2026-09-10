# Catalogo dimostrativo per investitori

Il comando `php artisan events:investor-demo padova --dry-run` verifica il catalogo senza scrivere.
Per importarlo: `php artisan events:investor-demo padova`; sul server occorre anche `--allow-production`, dopo un backup del database.

- 300 titoli fittizi, 14 categorie, cinque appuntamenti al giorno per 60 giorni da domani, nel fuso della città.
- Locali già approvati nella città, selezionati per tipologia; nessuna modifica a utenti, password, prenotazioni o altri eventi.
- Ogni scheda dichiara che l'appuntamento non è confermato dal locale. `is_demo` esclude l'indicizzazione SEO. Non sono attivate prenotazioni o sponsorizzazioni.
- 14 fotografie illustrative di categoria, riutilizzate: non sono fotografie dei singoli appuntamenti. Fonte, autore e licenza sono in `database/seeders/investor-media/credits.json`, nella scheda e nei metadati media. Le varianti seguono la pipeline immagini esistente.
- Identificativo stabile `investor-demo-v1:0000`…`0299`: una nuova esecuzione non duplica o sovrascrive gli eventi, non ripristina quelli cestinati, riprende eventuali immagini mancanti. Non sposta automaticamente le date già importate.
- Nessuna dipendenza Faker o API esterna durante l'importazione. Le immagini e il catalogo sono versionati; lo script di acquisizione non fa parte del deploy operativo.

Gli eventi sono disponibili anche all'app Android attraverso le API esistenti: non occorre ricompilare l'APK per aggiornare il catalogo.
