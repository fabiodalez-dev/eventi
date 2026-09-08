# Organizzatori e locali

Gli organizzatori sono entità separate dai locali ospitanti. Gli amministratori
li creano e attivano in `/admin/organizers`, assegnando un proprietario e i
collaboratori. Il pannello `/organizza` limita ogni gestore ai propri organizzatori
attivi: non attribuisce la proprietà dei locali ospitanti.

Gli eventi nascono in bozza e possono essere inviati alla redazione dopo aver
aggiunto una data. La pubblicazione e la sponsorizzazione non sono concesse
automaticamente agli organizzatori. Le singole date possono indicare un locale
diverso da quello principale, nella città dell'evento. I controlli di accesso
coprono anche date e prenotazioni.

## Archivio e ricerca

- `/organizzatori`: directory paginata e ricercabile.
- `/organizzatori/{slug}`: eventi in programma e archivio passato, anche su più locali.
- `/cerca` e suggerimenti dopo tre caratteri: nome e descrizione dell'organizzatore,
  oltre agli eventi che lo indicano come organizzatore.
- `/api/v1/search`: campo additivo `data.organizers`, compatibile con i client precedenti.
- `/api/v1/organizers` e `/api/v1/organizers/{slug}`: directory e archivio nativi Android.

Gli organizzatori inattivi non compaiono nella ricerca e non hanno un archivio
pubblico accessibile. Le preferenze sulle categorie continuano a filtrare gli eventi.
Se non è indicato un organizzatore separato, il locale ospitante è l'organizzatore;
per una data con locale diverso si usa quel locale. I campi organizzatore preesistenti
sono mantenuti per compatibilità, senza conversioni automatiche ambigue.

## Distribuzione

È necessaria la migration `2026_09_08_240000_create_organizers.php`, che aggiunge
tabelle e relazioni nullable. Le modifiche Android sono nei sorgenti: il lavoro
non richiede né autorizza automaticamente la generazione di un nuovo APK.
Non eseguire seeding distruttivo e non modificare credenziali esistenti durante i test.
