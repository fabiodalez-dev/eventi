# Architettura dei test

Configurazione verificata il 10 settembre 2026. Larastan livello 6, Deptrac,
56 test JavaScript e i 3 flussi browser desktop/mobile passano. Il profilo
Larastan massimo rileva 1.413 errori di tipizzazione preesistenti: resta un
controllo separato esplicito, senza baseline che nasconda il debito.
Il risultato di ogni rilascio è conservato nei referti della relativa CI.

## Controlli ordinari

| Comando | Scopo |
| --- | --- |
| `composer test:unit` | Logica isolata, senza database Laravel |
| `composer test:feature` | HTTP, autorizzazioni, query e integrazioni Laravel |
| `composer test:arch` | Modelli Eloquent e indipendenza del nucleo dai controller/pannelli |
| `composer architecture` | Dipendenze tra livelli con Deptrac |
| `composer analyse` | Larastan, livello 6 attualmente adottato |
| `composer test:browser` | Chromium reale: tema desktop/mobile, proposta evento ed errori server |

Pest Arch è già incluso dalle dipendenze di Pest. Faker e Mockery erano già
installati. Per API esterne usare fake di HTTP, notifiche e code nei test,
verificando anche errori, timeout e richieste inviate: mai credenziali reali.

Pest Browser usa Playwright, già previsto dal progetto. Non viene aggiunto
Dusk: mantenere un solo motore E2E evita due installazioni e due suite sovrapposte.
Il test mobile emula un browser; non sostituisce i test nativi Android/APK.

Prima esecuzione browser: `npm ci`, `npx playwright install --with-deps chromium`,
`npm run build`, quindi `composer test:browser`. Servono PHP 8.4 con sockets e
MariaDB 10.11. Pest avvia il proprio server: non puntare il test al sito pubblico.
Il database deve essere dedicato, per esempio `eventi_test`; Tests\TestCase
rifiuta ambienti non testing e nomi database senza segmento test. RefreshDatabase
ricrea lo schema e isola i dati; non usare mai un database con contenuti da conservare.
Le variabili DB_* possono sovrascrivere i valori locali di phpunit.xml.

La suite Browser usa phpunit.browser.xml separato, con le stesse protezioni.
La CI esegue Arch con Unit/Feature, Deptrac nel job qualità e Browser in un job
separato. Il gate di deploy richiede anche Browser; fallimenti o salti imprevisti
bloccano il rilascio. Nessun workflow viene avviato dalla sola modifica locale.

## Regole architetturali

Deptrac distingue Core (DTO, enum, modelli e query), Services, Delivery
(controller, Filament, console) e Support (restanti namespace applicativi).
Core e Services non possono dipendere da Delivery. Support resta permissivo:
questa configurazione impedisce accoppiamenti diretti, non prova l'assenza di
accoppiamenti transitivi né introduce un'architettura DDD che il progetto non ha.

Due eccezioni esistenti sono nominate in deptrac.yaml: SponsorshipReport usa
CurrentVenue e MessageFactory usa EventResource per collegamenti al pannello.
Non sono autorizzazioni generali e vanno eliminate spostando contesto e URL fuori
dall'interfaccia. I controller esistenti interrogano anche Eloquent: il divieto
globale di query richiederebbe una rifattorizzazione, non è dichiarato già rispettato.

## Analisi massima e mutation testing

`composer analyse:max` analizza app e database al livello massimo senza baseline.
È un profilo separato: prima di sostituire il livello 6 obbligatorio occorre
eseguirlo, correggere i risultati e verificare il costo in CI. Non vengono
nascosti errori per ottenere un risultato verde.

`XDEBUG_MODE=coverage composer mutation` usa Infection con Pest come eseguibile
PHPUnit. Il piccolo adattatore tools/testing/pest-infection.php comunica a
Infection la versione del motore PHPUnit, poi delega i test a Pest. Il perimetro iniziale esplicito è **SafeUrl**, una difesa per i link
provenienti dai contenuti. Esegue la suite Unit, senza database né browser;
non misura ancora l'efficacia di tutta la suite Feature. Il filtro è nello script
Composer: usare quel comando per mantenere il perimetro iniziale.

Soglie: MSI 80%, covered MSI 90%. La prima esecuzione su SafeUrl ha raggiunto
100% in entrambe le misure. I mutanti non coperti restano nel conteggio con
`--with-uncovered`; il risultato non si estende al resto dell’applicazione. Leggere i mutanti sopravvissuti
in storage/logs/infection.html, distinguere mutazioni equivalenti da test mancanti
e migliorare le asserzioni prima di estendere il perimetro alle regole di business.
Non abbassare le soglie automaticamente per far passare il workflow.

Il workflow manuale **Qualità approfondita** esegue separatamente livello massimo
e mutation testing e conserva i referti. Nessun risultato è mascherato con
continue-on-error. Questi due controlli non sono ancora gate di deploy.

Riferimenti: [Pest Arch](https://pestphp.com/docs/arch-testing),
[Pest Browser](https://pestphp.com/docs/browser-testing),
[Deptrac](https://deptrac.github.io/deptrac/configuration/),
[Infection](https://infection.github.io/guide/usage.html).
