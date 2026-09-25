# Profilo e interazioni, 25 settembre 2026

## Azioni

| Azione | Stato persistente | Rimozione |
| --- | --- | --- |
| Salva | saved_events, privato come intenzione | Cancella il segnalibro e i promemoria pendenti, conserva consiglio e partecipazione |
| Ci vado | community_attendances | Cancella soltanto la partecipazione |
| Consiglia | community_posts | Ritira il post e i suoi commenti, conserva segnalibro e partecipazione |
| Segui | followables per persone, follows per locali/categorie/organizzatori | Smette di seguire il soggetto |
| Blocca | user_blocks | Interrompe i follow nelle due direzioni, filtra i contenuti; sbloccare non ripristina i follow |

Prenotazioni e accordi di viaggio non sono dichiarazioni di partecipazione alla
community. Il ritiro di un consiglio è esplicito nella pagina «Consiglia evento».
`saved_events.visibility` resta compatibile con i client precedenti per la
pubblicazione del consiglio; non determina più chi partecipa. I post possono
sopravvivere senza un segnalibro e mantengono URL e commenti.

## Permessi e verifica WhatsApp

`GET /api/v1/me` mantiene il fatto `whatsapp_verified` e aggiunge
`community_access` e `carpool_access`. Nessun client deve ricavare permessi dal
nome del ruolo. `community_access` contiene `eligible`, `reason`,
`required_step`, `whatsapp_exempt`, `can_publish`, `can_comment`, `can_attend`,
`can_follow`. Le autorizzazioni sono comunque ricalcolate durante ogni scrittura.

Admin e superadmin possono saltare soltanto il requisito del numero. Email
confermata, sospensioni del dominio, cancellazione, proprietà e blocchi non
vengono superati. Per i passaggi servono maggiore età dichiarata e versione
corrente delle regole; la chat resta riservata ai partecipanti. Il badge
WhatsApp rappresenta una verifica reale, anche nelle offerte di passaggio.

Il sito rifiuta le mutazioni community durante impersonificazione. Le query di
profili, commenti, partecipanti e discovery dei passaggi includono l'esonero,
con gli stessi vincoli del relativo servizio. Revocare un ruolo rimuove l'esonero
alla richiesta successiva; eventuali contenuti vengono filtrati in lettura.

Il numero non viene mai aggiunto ai profili pubblici. L'API di partecipazione
restituisce nomi solo ai lettori abilitati, filtrati per visibilità e blocchi.
Le credenziali Kapso e la chiave delle impronte rimangono sul server. Non si
inviano codici reali durante i test. Il controllo del mittente Android one-tap
continua a seguire la procedura di prova su dispositivo della specifica WhatsApp;
questo rilascio non modifica quel protocollo.

## Identità e navigazione

Area personale: attività, persone e avvisi, impostazioni, passaggi e gestione.
Notifiche e newsletter hanno un solo editor visibile. Il modulo identità invia
`profile_only` e non modifica preferenze o consensi; le vecchie richieste restano
compatibili. Aspetto e diritti sui dati si aprono su richiesta.

Il profilo pubblico richiede nome pubblico, handle e visibilità. Gli altri campi
sono facoltativi. Il cognome dell'account non viene proposto pubblicamente.
I nomi utente precedenti sono riservati nello stesso registro e risolti solo
se il profilo attuale è accessibile. Il cambio non rende pubblici profili privati.

Seguiti, follower e persone bloccate sono sezioni distinte. Un profilo non visibile
non espone il proprio nome nell'elenco follower. L'elenco dei propri blocchi
conserva l'identificazione necessaria a sbloccare la persona.

## Migrazione e rilascio

La migrazione crea community_attendances. Dai vecchi salvataggi pubblici copia
solo intenzione attend o azione attendance_public registrata; i consigli e i
casi ambigui non diventano partecipazioni. Il backfill è idempotente.
Il vincolo dei post sul segnalibro diventa SET NULL e si aggiunge unicità per
utente/data. I vecchi handle vengono prenotati nel registro degli alias.

Prima delle migrazioni il deploy ordinario esegue il backup remoto del database.
La separazione delle intenzioni non è reversibile senza perdita di significato:
`down` rifiuta la ricombinazione se esistono post o partecipazioni; su uno schema
vuoto consente il reset completo. Per tornare al modello precedente occorre
ripristinare insieme codice e backup del database, senza sovrascrivere attività
nuove non ancora recuperate. Non eseguire un semplice rollback del codice dopo
che sono state registrate attività con il nuovo modello.

Web/API devono essere pubblicati prima di distribuire Android 1.18.0 (40).
La CI verifica PHP, browser e Android; il workflow Play genera il bundle firmato.
La generazione del bundle non equivale alla sua pubblicazione sullo store.
