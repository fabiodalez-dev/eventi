# Verifica locali e proposte eventi

In **Admin → Locali**, aprire un locale e usare **Verifica**. La stessa azione
è nel menu **Moderazione** della riga. **Togli verifica** revoca il badge.
Occorre il permesso `venues.moderate`. La verifica aggiunge il badge pubblico;
non cambia lo stato di approvazione del locale e non pubblica i suoi eventi.

Le richieste di **Proponi un evento** arrivano in **Moderazione → Proposte eventi**.
Aprire **Esamina**, controllare titolo e descrizione pubblica, scegliere un locale
approvato della stessa città e una categoria, verificare l'orario e l'ingresso.
**Crea e pubblica** crea l'evento con una data e lo pubblica subito. Il catalogo
lo mostra nelle finestre temporali corrispondenti alla data scelta.

L'orario del modulo è quello della città, memorizzato in UTC. I dati originali,
i contatti, l'autore della revisione e la proposta restano conservati; i contatti
non vengono copiati nell'evento. La pubblicazione è transazionale e idempotente.
La proposta accolta rimanda alla scheda evento, dove si aggiungono altre date,
locandine o dettagli. **Rifiuta proposta** richiede una motivazione interna.
L'accesso è riservato allo staff con `events.moderate`; per pubblicare servono
anche i permessi di creazione e pubblicazione degli eventi.
