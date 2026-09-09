# inCittà 1.8.4 (19)

Rilascio richiesto il 9 settembre 2026: deploy Laravel e nuovo APK Android.

## Contenuto

- Calendario Google separato con consenso OAuth, filtri condivisi fra web e
  Android, sincronizzazione periodica e scollegamento.
- Pulsante di personalizzazione calendario riconoscibile come azione primaria.
- Profilo Android con nome, email copiabile e ruolo prima delle altre azioni;
  possibilità immediata di uscire o cambiare account.
- Aggiornamento dell'identità dal server e compatibilità con sessioni precedenti.
- Protezioni contro accesso fra account e callback dopo cancellazione account.

## Stato iniziale del rilascio

Preparazione in corso; questo documento non attesta ancora un deploy riuscito.
CI, verifica remota, test su emulatore e checksum dell'APK saranno registrati
nel referto finale sul Desktop, insieme al commit distribuito.

La prova Google locale ha sincronizzato 63 date e verificato l'idempotenza
senza modificare altri calendari. Non trasferire questa connessione sul remoto:
serve il consenso dell'account inCittà remoto. Il client Google è ancora in
modalità Testing, autorizzato per il Gmail del proprietario; non è un'apertura
generale a tutti gli account Google. I token di prova durano al massimo sette
giorni. L'informativa pubblica richiede aggiornamento prima dell'apertura generale.

Le credenziali OAuth e Firebase restano private. Le password admin non vengono
modificate. Non si eseguono seeder generali o test distruttivi in produzione.
Lighthouse rimane sospeso come richiesto dal proprietario; gli altri gate CI
restano obbligatori.

La notifica sul telefono fisico resta da verificare dopo l'attivazione push
del destinatario. Non inviare messaggi di prova indiscriminati agli utenti.
