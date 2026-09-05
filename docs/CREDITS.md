# Crediti e licenze

## Immagini del seed

Le 84 locandine in `database/seeders/media/` provengono da **Lorem Picsum**
(https://picsum.photos), che ridistribuisce fotografie di **Unsplash** sotto
[Unsplash License](https://unsplash.com/license): uso gratuito, anche commerciale,
senza obbligo di attribuzione (l'attribuzione resta comunque apprezzata).

Sono nominate `<categoria>-<n>.jpg`, sei per ciascuna delle quattordici categorie,
in formato 3:4 (800×1067) come prescrive §11.4 del piano. Il seed deterministico
(`evt-<categoria>-<n>`) garantisce che rieseguire il download produca le stesse
immagini.

**Sono immagini di riempimento, non locandine reali.** In produzione le locandine
sono caricate dai locali, che in fase di iscrizione dichiarano di averne il diritto
d'uso (§16 del piano).

## Dati cartografici

- **Tile e dati:** © OpenStreetMap contributors, licenza
  [ODbL](https://www.openstreetmap.org/copyright). L'attribuzione deve restare
  visibile sulla mappa.
- **Tile server:** [OpenFreeMap](https://openfreemap.org), gratuito e senza API key.
- **Mappa pubblica:** [MapLibre GL JS](https://maplibre.org/), licenza BSD-3-Clause.
- **Selettore coordinate del pannello:** [Leaflet](https://leafletjs.com), licenza BSD-2-Clause.

## Font

Self-hosted via `@fontsource`. Nessuna richiesta a Google Fonts né ad altri CDN
(il plugin `bunny()` di `laravel-vite-plugin` è stato rimosso da
`vite.config.js`): sarebbe un trasferimento di dati verso terzi senza consenso.

- **Bricolage Grotesque** (titoli) — `@fontsource-variable/bricolage-grotesque`,
  SIL Open Font License 1.1.
- **Inter** (testo) — `@fontsource-variable/inter`, SIL Open Font License 1.1.

Entrambi sono variabili e suddivisi per `unicode-range`: il browser scarica solo
il sottoinsieme che gli serve.

## Pacchetti

Nessuna dipendenza GPL o AGPL. Prima di aggiungerne di nuove va verificata la
licenza: un pacchetto AGPL in un progetto che offrirà servizi a pagamento è un
problema legale, non un dettaglio (§15 dello stack tecnologico).
