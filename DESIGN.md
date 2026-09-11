---
name: "inCittà Mobile"
description: "Il tabellone urbano degli eventi: scuro nero/lime o chiaro bianco/grafite, tipografico e immediato."
colors:
  canvas-night: "#0B0B0B"
  canvas-deep: "#060606"
  surface-ink: "#141413"
  surface-raised: "#1A1A19"
  paper-ink: "#F5F5F0"
  muted-ink: "#B0B0A9"
  divider: "#41413F"
  electric-lime: "#CCFF00"
  pressed-lime: "#B4E000"
  deep-lime: "#4A5C00"
typography:
  display:
    fontFamily: "Archivo, sans-serif"
    fontSize: "48px"
    fontWeight: 800
    lineHeight: 1.04
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "Archivo, sans-serif"
    fontSize: "24px"
    fontWeight: 800
    lineHeight: 1.12
    letterSpacing: "-0.02em"
  title:
    fontFamily: "Archivo, sans-serif"
    fontSize: "19px"
    fontWeight: 800
    lineHeight: 1.08
    letterSpacing: "-0.025em"
  body:
    fontFamily: "Archivo, sans-serif"
    fontSize: "16px"
    fontWeight: 400
    lineHeight: 1.45
  label:
    fontFamily: "Archivo, sans-serif"
    fontSize: "10px"
    fontWeight: 800
    lineHeight: 1.2
    letterSpacing: "0.12em"
rounded:
  none: "0px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  card: "22px"
  section: "48px"
components:
  button-primary:
    backgroundColor: "{colors.electric-lime}"
    textColor: "{colors.canvas-night}"
    typography: "{typography.label}"
    rounded: "{rounded.none}"
    padding: "16px 20px"
    height: "48px"
  button-secondary:
    backgroundColor: "{colors.canvas-night}"
    textColor: "{colors.paper-ink}"
    typography: "{typography.label}"
    rounded: "{rounded.none}"
    padding: "14px 18px"
    height: "48px"
  event-card:
    backgroundColor: "{colors.canvas-night}"
    textColor: "{colors.paper-ink}"
    rounded: "{rounded.none}"
    padding: "20px 22px"
  filter-chip-active:
    backgroundColor: "{colors.electric-lime}"
    textColor: "{colors.canvas-night}"
    typography: "{typography.label}"
    rounded: "{rounded.none}"
    padding: "8px 10px"
---

# Design System: inCittà — web e Android

Aggiornato all’11 settembre 2026. I token YAML e le sezioni iniziali descrivono il **tema scuro**. Le sezioni «Tema chiaro» e «Backend» definiscono le varianti e prevalgono per quelle superfici. I valori eseguibili sono in `resources/css/appearance.css`, `resources/css/app.css` e nel tema Compose in `android/`.

## Overview

**Creative North Star: "Il tabellone urbano notturno"**

L'interfaccia sembra un tabellone culturale affisso nella città dopo il tramonto: fondo quasi nero, testo color carta e un unico segnale giallo-verde che indica ciò che è vivo, scelto o azionabile. La struttura è modernista e tipografica. Griglie visibili e divisori da 2 px organizzano i contenuti; nulla galleggia e nulla viene addolcito.

Su Android il sistema conserva questa identità senza combattere le convenzioni della piattaforma. Navigazione, back, focus, TalkBack, caricamento e gesti restano familiari. Si rifiutano card arrotondate, gradienti, vetro, ombre decorative, palette multicolore e fotografie colorate.

**Key Characteristics:**

- Primo accesso secondo il sistema; scelta chiaro/scuro persistente.
- Archivo per ogni ruolo, con titoli molto pesanti e compatti.
- Griglia modulare, allineamento a sinistra, divisori forti da 2 px.
- Un solo accento elettrico, usato per azioni e stato corrente.
- Fotografie esclusivamente in scala di grigi ad alto contrasto.
- Angoli a raggio zero in ogni componente.

## Colors

Una notte quasi nera, inchiostro color carta e un lampo lime che deve restare raro e inequivocabile.

### Primary

- **Lime elettrico** (#CCFF00): azioni primarie, selezione, focus, stato in corso e salvataggio attivo.
- **Lime premuto** (#B4E000): stato premuto di controlli pieni.
- **Lime profondo** (#4A5C00): fondi attenuati e progressi non testuali.

### Neutral

- **Notte urbana** (#0B0B0B): canvas principale.
- **Notte profonda** (#060606): barre di sistema e superfici incassate.
- **Inchiostro di superficie** (#141413): secondo livello senza ombra.
- **Carta** (#F5F5F0): testo primario e icone.
- **Carta attenuata** (#B0B0A9): metadati secondari.
- **Regola metallica** (#41413F): divisori e bordi equivalenti al 22% della carta sul canvas.

**The One Signal Rule.** Il lime è l'unico accento. Non introdurre un secondo colore per categoria, stato o decorazione.

## Typography

**Display Font:** Archivo, con sans-serif di sistema come ripiego
**Body Font:** Archivo, con sans-serif di sistema come ripiego

**Character:** compatta, urbana e autorevole. La stessa famiglia tiene insieme identità e usabilità; scala, peso e spaziatura creano la gerarchia.

### Hierarchy

- **Display** (800, 48 px massimo, 1.04): titoli di apertura brevi.
- **Headline** (800, 24 px, 1.12): intestazioni di sezione.
- **Title** (800, 19 px, 1.08): titoli evento in maiuscolo, massimo tre righe.
- **Body** (400, 16 px, 1.45): descrizioni, errori e istruzioni, massimo 70 caratteri per riga.
- **Label** (800, 10 px, tracking 0.12 em, maiuscolo): categorie, filtri, date e pulsanti.

**The Poster Type Rule.** I titoli evento sono l'immagine primaria delle liste. Devono avere più peso del contenitore e non essere ridotti a una didascalia sotto una fotografia.

## Elevation

Il sistema è piatto. La profondità nasce da superfici tonali adiacenti, regole da 2 px e movimento di stato. Nessuna ombra a riposo; un bordo chiaro può segnalare una superficie temporaneamente sollevata, come un foglio mappa.

**The Structural Edge Rule.** Un componente appartiene al layout grazie ad allineamento e bordi completi, non grazie a ombre o strisce laterali decorative.

## Components

### Buttons

- **Shape:** rettangolare, raggio 0, altezza minima 48 dp.
- **Primary:** lime elettrico con testo notte, padding 16 x 20 dp, etichetta allineata a sinistra.
- **Hover / Focus:** su Android lo stato premuto usa lime premuto; focus da tastiera con contorno lime da 2 dp e offset 2 dp.
- **Secondary / Ghost:** canvas con bordo completo da 2 dp; al premuto il bordo e il testo diventano lime.

### Chips

- **Style:** rettangoli con bordo da 2 dp, label Archivo 800 maiuscola.
- **State:** selezionato pieno lime con testo notte; non selezionato canvas, bordo regola e testo carta attenuata.

### Cards / Containers

- **Corner Style:** raggio 0.
- **Background:** canvas o superficie inchiostro.
- **Shadow Strategy:** nessuna ombra.
- **Border:** griglia condivisa da 2 dp, non cornici morbide isolate.
- **Internal Padding:** 20 x 22 dp per una card evento.

### Inputs / Fields

- **Style:** superficie scura, bordo completo 2 dp, raggio 0, altezza minima 52 dp.
- **Focus:** bordo lime, cursore lime, label carta.
- **Error / Disabled:** messaggio testuale esplicito; disabilitato al 45% senza affidarsi solo al colore.

### Navigation

Barra superiore e navigazione inferiore nere, separate da una regola da 2 dp. Brand e tab attivo in lime; label brevi in Archivo 800. La destinazione corrente deve avere testo e indicatore, non solo un cambio cromatico.

### Event Card

Componente tipografico numerato. Categoria in un box da 2 dp, titolo maiuscolo, luogo e data, prezzo, stato e controllo wishlist. Nelle liste non usa la locandina. L'intera card apre il dettaglio, mentre il segnalibro ha un target indipendente da 48 dp.

## Do's and Don'ts

### Do:

- **Do** usare #0B0B0B, #F5F5F0 e #CCFF00 come vocabolario cromatico dominante.
- **Do** mostrare tempo, luogo, prezzo e stato prima delle descrizioni.
- **Do** usare bordi completi da 2 dp e griglie condivise per organizzare la pagina.
- **Do** trattare tutte le fotografie con scala di grigi e contrasto leggermente aumentato.
- **Do** preservare target da 48 dp, focus visibile, TalkBack e font scaling.
- **Do** progettare stati loading, offline, vuoto, errore, autenticato e guest.

### Don't:

- **Don't** usare card arrotondate, gradienti, vetro o ombre decorative.
- **Don't** trasformare il catalogo in una directory commerciale, un marketplace di biglietti o un social feed infinito.
- **Don't** usare palette multicolore o colorare le immagini.
- **Don't** nascondere informazioni o azioni essenziali dietro animazioni.
- **Don't** usare strisce laterali colorate maggiori di 1 px come decorazione delle card.
- **Don't** confondere sponsorizzazioni e catalogo editoriale: ogni contenuto pagato porta una label visibile.

## Tema chiaro: Bianco e grafite

Il sito pubblico offre un comando a icona che alterna Scuro / Chiaro, senza popup: ultimo elemento a destra su desktop, compatto su mobile. La scelta è presente anche nel profilo e nella pagina Aspetto. Alla prima visita il sito rileva il tema di sistema e lo memorizza;
la selezione si salva nel profilo per gli autenticati e nel cookie necessario `incitta_appearance` (un anno) per i
visitatori, con memoria locale di supporto. Il profilo prevale sulle preferenze locali durante gli accessi.

Sul web il chiaro usa bianco morbido `oklch(98.8% 0.002 80)`, superfici
`oklch(96.5% 0.003 80)`, grafite #262624, testo secondario #686863 e
accento #B54D23. Bordi neutri da 1 px e controlli con raggio 6–8 px. Sul web Bricolage Grotesque variabile, ospitato localmente, distingue i titoli
con peso 700 e dimensione ottica adattiva. Manrope resta per testo,
metadati e comandi. Il titolo della homepage usa la dimensione ottica 96,
interlinea 0,94, fino a 132 px e tre righe: città, promessa, scelta. Tutte le righe
del titolo principale hanno peso 800. Le dimensioni
si adattano a desktop, tablet e telefono senza cambiare il contenuto.
Fondi quasi bianchi e grigi neutri a bassissima cromaticità. Nessuna fascia
arancione: il colore è limitato a parole chiave, azioni e selezioni.
La fotografia di apertura ha raggio 12 px; su tablet si dispone sotto il titolo.
I dati del catalogo formano una riga discreta: numeri da 22 px e descrizioni
grigie da 11 px, affiancati senza riquadri. Le scorciatoie sono capsule bordate.
Le card mantengono la griglia continua, con titoli Bricolage Grotesque 700, categorie
neutre e date leggibili. L'hover applica un grigio tenue senza spostare il testo.
Il tema scuro conserva Archivo e la sua impaginazione. Le fotografie con
testo sovrapposto mantengono un contrasto locale indipendente dal tema.
Le mappe usano lo stile Positron nel tema chiaro e marcatori terracotta.

Queste regole sostituiscono nel solo tema chiaro le prescrizioni precedenti
su lime, raggio zero e divisori da 2 px. Android offre lo stesso selettore nel profilo, con persistenza locale da ospite e
sincronizzazione della preferenza attraverso GET/PATCH /api/v1/me.

## Backend, sempre chiaro

Amministrazione, gestione locali e organizzatori condividono il tema chiaro:
Manrope nei controlli e nelle tabelle, Bricolage Grotesque 700–800 nei titoli,
fondi neutri quasi bianchi, accento #B54D23 e bordi sottili con raggi 6–8 px.
La modalità scura è disabilitata nei pannelli, indipendentemente dal sistema
e dalla preferenza salvata sul sito pubblico. I font sono serviti localmente.

## Coerenza dei controlli e movimento

Nel chiaro tag, prezzi, stati, condivisione e collegamenti calendario/PDF condividono raggio 6 px, bordo neutro sottile e tipografia Manrope. La coerenza non impone la stessa altezza: badge compatti non interattivi, azioni di utilità da 36 px su desktop e almeno 44 px sui puntatori touch. Android conserva target accessibili secondo le convenzioni native.

Le card chiare applicano il fondo hover all’intero contenitore, senza traslare solo il titolo. Il foglio mappa mantiene padding interno; l’elenco sovrapposto alla mappa ha un fondo opaco anche negli spazi vuoti. I cambi di filtro web usano fade out/in da 120/200 ms; Android usa una transizione da 180 ms. Il movimento non ritarda l’accesso ai contenuti e rispetta la riduzione delle animazioni.

Android include localmente Bricolage Grotesque 700/800 per i titoli e Manrope per testo e controlli nel chiaro, con superfici quasi bianche e controlli da 6 dp. «Solo pesi grossi» si riferisce a Bricolage, non a tutto il testo dell’applicazione.

### Coerenza dei controlli nel tema chiaro

I link che svolgono il ruolo visivo di pulsanti usano `ui-action` (inclusa nei componenti `button` e `filter-chip`). Pulsanti, azioni, badge e campi condividono `--radius-control: 6px`; dimensioni e spaziatura restano specifiche del contesto. I link testuali, le righe di elenco e le schede non vanno trasformati in pulsanti. Il controllo di ricerca composto conserva gli angoli esterni condivisi.
