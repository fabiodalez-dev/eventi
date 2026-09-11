# Rilascio 1.9.1 e checkpoint del 10 settembre 2026

Documentazione aggiornata l’11 settembre 2026. Questo è un referto del checkpoint, non una verifica in tempo reale di produzione o Play Console.

## Distribuzione verificata

- Web: PR [54](https://github.com/fabiodalez-dev/eventi/pull/54), release `328b7d101ccc927995f84cf7f3519fe0ece0fd0a`, deploy del 10 settembre alle 19:23:07 UTC. Pipeline main [34519513128](https://github.com/fabiodalez-dev/eventi/actions/runs/34519513128) completata.
- Controllo online: release-status corrispondente al commit; dettaglio evento con esclusione dello sponsor corrente, inclusa la rotta con prefisso città. Il banner API ha restituito un evento diverso.
- Android: versione **1.9.1**, **versionCode 23**, APK e AAB firmati generati. APK installato e avviato su emulatore Android 15. La firma sideload e la chiave di upload Play restano distinte.
- Play Console, ultimo controllo del 10 settembre: **1.8.5 (21)** disponibile ai tester interni; nuova bozza aperta ma **bundle 23 non caricato**, selettore Chrome rifiutato con `Not allowed`. Non dichiarare 1.9.1 distribuita da Google Play senza nuova verifica.

## Perimetro

Tema chiaro e controlli coerenti web/Android; URL pubblici progressivi delle singole date e deep link nativi; distinzione sede/organizzatore; sponsorizzazioni contestuali con esclusione dell’evento corrente; filtri e transizioni mappa; SEO delle date, canonical, schema e sitemap; consolidamento dell’architettura dei test. Per le regole dettagliate vedere [PRODUCT](../PRODUCT.md), [DESIGN](../DESIGN.md), [SEO-EVENTI](SEO-EVENTI.md), [SPONSORED-BANNERS](SPONSORED-BANNERS.md) e [TESTING](TESTING.md).

## Verifiche eseguite

| Controllo | Esito al checkpoint |
| --- | --- |
| PHP/Pest completo | 2.060 test passati, 8.052 asserzioni |
| JavaScript | 56 test passati |
| Python infrastruttura | 19 test passati |
| Pest Browser / Playwright | 3 test passati, 13 asserzioni |
| Larastan livello 6 | 0 errori |
| Larastan massimo | 1.413 errori preesistenti; profilo separato, non gate di deploy |
| Deptrac | 0 violazioni, 2 eccezioni esplicite; non copre ogni dipendenza del progetto |
| Infection | MSI e covered MSI 100%, **solo SafeUrl** |
| Pint e audit Composer/npm | Passati; audit senza vulnerabilità segnalate |
| Android build, unit test e lint | Passati per il packaging |
| Android strumentali | 33 casi, 4 opt-in saltati; un errore di navigazione corretto e relativo gruppo di 3 test ripetuto con successo. Non un’unica esecuzione completa tutta verde |
| Lighthouse locale | Performance 75 home / 74 archivio / 76 singola data; soglia 90 non raggiunta. Accessibility 100 / 96 / 100, SEO 100 su tutte e tre |

Lighthouse è stato raccolto sul server locale, cinque esecuzioni per URL; LCP circa 4,5 secondi. Non è una misura dei Core Web Vitals reali in produzione. Il gate Lighthouse della CI resta disabilitato. I quattro test Android opt-in richiedono account dedicati per login admin, preferenze, ticketing e calendario. Le verifiche automatiche social usano API simulate.

## Artefatti locali consegnati

Percorsi del Mac di packaging, non file da aggiungere al repository:

- `/Users/fabio/Desktop/inCitta-Android-1.9.1.apk`
  SHA-256: `b93dd953b0685806b53c8d1ddb881d4f1a76bedee0b2e8ab41ed32986fce20cf`.
- `/Users/fabio/Desktop/inCitta-1.9.1-23-play.aab`
  SHA-256: `791556d02d56d2d5984fb0865560e9e60d31a90cede1fbae2711936b17a1743f`.

## Da riprendere

1. Completare l’upload del bundle 23 e verificarne lo stato in Play Console; preparazione, caricamento e distribuzione sono passaggi distinti.
2. Ridurre il debito Larastan massimo e migliorare le prestazioni prima di promuovere quei controlli a gate obbligatori.
3. Completare verifiche con account dedicati e servizi esterni, oltre ai requisiti Play documentati in [GOOGLE-PLAY](GOOGLE-PLAY.md).
4. All’11 settembre il workspace contiene ulteriori modifiche applicative non committate su SEO/sitemap, cache, home, archivi e profilo. Non appartengono automaticamente al deploy descritto sopra: revisionarle e verificarle nel successivo rilascio. Questo aggiornamento documentale non le modifica e non riesegue le suite.
