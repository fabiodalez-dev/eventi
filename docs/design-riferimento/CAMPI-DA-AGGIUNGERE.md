# Campi del design di riferimento non presenti nello schema

Ricavati da `Radar Milano.dc.html` confrontando l'oggetto dati dell'evento con
lo schema di §7. Le voci sono in ordine di impatto sul prodotto.

## 1. Fasce di prezzo — `tiers`

La demo le mostra come tabella: *nome · prezzo · stato*, e lo stato è per
**fascia**, non per evento: «Parterre in piedi — 49 € — Esaurito» convive con
un secondo anello ancora disponibile.

È la risposta esatta alla richiesta «tutto esaurito / biglietti disponibili»:
oggi `OccurrenceStatus::SoldOut` marca l'intera data, e non sa dire che è finito
solo il parterre.

Nuova tabella `ticket_tiers`: `event_id` o `occurrence_id`, `name`, `price`,
`currency`, `status` (disponibile | esaurito | non ancora in vendita | chiuso),
`url`, `note`, `sort_order`.

## 2. Come arrivare — `transit`

Elenco di righe di testo: metro, tram, treno, parcheggio. Sul **locale**, non
sull'evento: cambia col luogo, non con la serata.

`venues.transit` (json): lista di `{mode, text}`.

## 3. Accessibilità — `access`

Oggi `venues.accessibility` è un json mai compilato né esposto. Va **strutturato**
in voci previste (ingresso senza scalini, servizi accessibili, posti riservati,
percorso tattile, assistenza su richiesta, cane guida ammesso), perché solo così
diventa un filtro di §11.3 invece che testo libero.

## 4. Scheda tecnica e informazioni — `facts`, `info`

Coppie chiave/valore mostrate come tabella: capienza, apertura porte, età minima,
guardaroba, regole della sala. Alcune sono dell'evento, altre del locale.

`events.facts` (json) e `venues.info` (json): liste di `{label, value}`.

## 5. Zona — `zone`

La demo raggruppa e filtra per zona («Navigli», «Isola»). Oggi esiste
`venues.municipality`, che per un capoluogo è sempre lo stesso valore e non
distingue i quartieri.

`venues.zone` (string, indicizzata) più il filtro corrispondente.

## 6. Capienza e posti rimasti — `capLabel`, `spotsLabel`

`event_occurrences.capacity_left` esiste già ma non è mai mostrato.
Serve solo la presentazione, più `capacity` sull'occorrenza.

## 7. In evidenza — `hotTag`, `hotLabel`

Etichetta breve di richiamo («ULTIMI POSTI», «NUOVA DATA»).
`event_occurrences.highlight` (string breve, nullable).

## 8. Partecipanti — `going`, `goingNote`

«312 persone ci vanno». Richiede un dominio nuovo: chi partecipa, come si
dichiara, cosa si mostra a chi non è iscritto.

**Da decidere prima di implementare**: è diverso dal salvataggio (§15.3), e
un numero pubblico su un evento con tre partecipanti scoraggia invece di
attrarre.

## 9. Recensioni — `rating`, `nrev`, `revs`

Voto medio, numero di recensioni, elenco con testo e autore.

**Da decidere prima di implementare**: è il sottosistema più impegnativo della
lista — modera, invita all'abuso, e su eventi singoli (non ripetuti) una
recensione arriva sempre dopo che l'evento è finito, quindi non aiuta chi
decide. Nella demo è riempito di dati finti; nel prodotto vero va progettato
o lasciato fuori.
