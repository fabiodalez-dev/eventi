# Stato di avanzamento

Aggiornato dopo ogni checkpoint. I numeri riportati sono misurati, non stimati.

---

## C1 — Fondamenta (F0 + F1 + F4-motore) — ✅ VERIFICATO 2026-08-23

Verifica finale eseguita su `eventi_local` (MariaDB 127.0.0.1:3307) con database
riseedato da zero e suite completa.

### Comandi eseguiti

| Comando | Esito |
|---|---|
| `php artisan migrate:fresh --seed` | 25 migration + 7 seeder, nessun errore |
| `./vendor/bin/pest` | **159 test passati su 159, 384 asserzioni** |
| `./vendor/bin/pint` | passed, nessun file da correggere |
| `./vendor/bin/phpstan analyse --memory-limit=1G` | 0 errori (livello 6, `app` + `database`, Larastan) |

### Criteri di accettazione F1 — Database e dominio

- [x] Tutte le tabelle di §7 con migration, model, factory, seeder, enum, policy,
      relazioni, cast — 24 tabelle di dominio + pacchetti (D13), 19 enum, 21 model,
      21 factory, 11 policy registrate.
- [x] Observer per `business_date` ed `effective_ends_at`
      (`EventOccurrenceObserver`, ricalcolo al cambio categoria via `EventObserver`).
- [x] RRULE `FREQ=WEEKLY;BYDAY=TH;COUNT=10` → 10 occorrenze con `business_date`
      corretta (`tests/Feature/Recurrence/GenerateOccurrencesActionTest.php`),
      idempotente su esecuzioni ripetute (bug corretto, D22).
- [x] Cancellare una occorrenza non tocca le altre e imposta `is_exception`
      (observer `updating()`, D21; test dedicato).
- [x] Test di isolamento fra locali verdi (scenario F —
      `tests/Feature/Security/VenueIsolationTest.php`, accesso per ID diretto,
      slug e payload forzato).
- [x] Casi limite di §8.2 e §8.3 coperti (23:59 / 00:00 / 05:59 / 06:00,
      nightlife e non, ora legale 29/03 e solare 25/10, `effective_ends_at` nei
      quattro rami, `is_all_day` con orari di apertura).
- [x] Comando `occurrences:generate` schedulato (1° del mese).

### Criteri di accettazione F4 — Motore eventi

- [x] `EventOccurrenceQuery` completa (§8) con test su ogni metodo
      (`tests/Feature/TemporalEngine/`, 7 file).
- [x] Nessuna logica temporale fuori da `app/Queries/`:
      `grep -rn "business_date" app/ | grep -v app/Queries` restituisce solo
      il model (fillable/cast), gli observer (che sono i produttori designati
      delle colonne, §5 delle convenzioni) e commenti. Nessun controller,
      componente o job ricalcola finestre temporali.

### Verifica di merito sul database seedato (misurata 2026-08-23 17:26 Europe/Rome)

| Criterio | Atteso | Misurato | Esito |
|---|---|---|---|
| Occorrenze future | ~150 | **140** (143 totali) | ✅ |
| Giorni dei prossimi 14 con ≥ 3 occorrenze | ≥ 12 | **14 / 14** | ✅ |
| `EventOccurrenceQuery::for($city)->ongoing()->get()` | ≥ 1 | **2** | ✅ |
| `->startingSoon()->get()` | ≥ 1 | **6** | ✅ |
| Nightlife dopo mezzanotte → `business_date` del giorno prima | sì | **2 casi, entrambi corretti** (01:30 → giorno prima, 00:45 → giorno prima) | ✅ |

### Questioni aperte (non bloccanti per C1)

1. **"Stasera" e gli after nightlife** — §8.4 definisce "stasera" come
   `business_date` odierna AND ora locale ≥ 17:00: un evento nightlife che
   comincia alle 02:00 appartiene alla serata di ieri ma non compare in
   "stasera" di ieri. Il codice implementa la lettera del piano (test esplicito
   in `TimeWindowsTest.php`); serve una decisione di prodotto prima di cambiare.
2. **`weekend()`** copre solo i giorni non ancora passati del weekend corrente;
   §8.4 ammette anche "o successiva". Da chiarire quando si costruirà la UI.
3. **§15.2** — `User::canReceiveNotifications()` esiste ed è testato, ma il
   motore notifiche non è ancora scritto: chi lo implementerà (F7b) DEVE
   chiamarlo prima di ogni invio.
4. **Test in parallelo** — `eventi_test` è condiviso: `RefreshDatabase` fa
   `migrate:fresh` e due suite in parallelo si distruggono a vicenda. In CI
   serve esecuzione seriale o un database per processo.
5. `phpstan.neon` analizza `app/` e `database/`, non `tests/` (scelta D14).

### Prossimo checkpoint

C2 — Pannelli (F2 admin, F3 locali).
