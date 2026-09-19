# Migrazione al dominio definitivo

Decisione persistente del proprietario: 18 settembre 2026. Il dominio attuale è temporaneo. **Quando il sito verrà trasferito, tutto dovrà essere ricollegato a un nuovo account**, incluse le integrazioni e API aggiunte in futuro.

La migrazione riguarda tutto il sistema, incluse le integrazioni aggiunte dopo questo documento. Per ogni servizio/API attivo annotare l'esito della verifica, aggiornare i riferimenti al dominio e provare il flusso reale. Controllare anche le restrizioni di origine/referrer delle chiavi API; sostituire le credenziali solo dove richiesto dal provider, mantenendo le chiavi necessarie a decifrare i dati esistenti.

- [ ] Inventariare configurazione runtime, variabili ambiente e pannelli dei provider senza esportare segreti nei documenti.
- [ ] Identificare il nuovo account di destinazione e, per ciascun servizio, registrare account attuale, account futuro, risorse da trasferire o ricreare e responsabile della riconnessione. Non assumere trasferimenti automatici tra account.
- [ ] Ricollegare tutte le integrazioni al nuovo account: Meta/WhatsApp/Kapso, Google e Firebase/Play, email, mappe, pagamenti, storage, monitoraggio, repository e deploy. Rivedere titolarità, ruoli, consensi OAuth, credenziali/API key, firme, webhook e fatturazione; identificativi e certificati possono cambiare anche se il servizio rimane lo stesso.
- [ ] Verificare i flussi reali nel nuovo account prima di disattivare i collegamenti precedenti. La presente decisione non richiede di scollegare o revocare ora i servizi in uso.
- [ ] DNS, HTTPS, dominio canonico, APP_URL, cookie sessione, CORS/Sanctum se utilizzati e redirect 301 del vecchio dominio.
- [ ] Meta: proprietà/verifica del nuovo dominio (DNS o file HTML), domini autorizzati, App Domains, OAuth redirect URI, privacy policy e termini, eventuali callback di cancellazione dati. La verifica del portfolio è distinta dalla verifica del dominio. Il 18 settembre 2026 `fabiodalez.it` risulta Verified nel portfolio attuale, tramite file HTML nella radice: questo stato non sostituisce la verifica nel futuro account.
- [ ] Kapso/WhatsApp: profilo business e website, eventuali webhook e firme, URL dei template con link. Il template OTP copy-code non contiene URL: numero e credenziali restano validi se resta lo stesso WABA.
- [ ] Google Calendar: origini autorizzate e callback OAuth del nuovo dominio; aggiornare URL nelle schermate consenso.
- [ ] Email: mittente, dominio SPF/DKIM/DMARC, link di conferma, recupero password e magic link; mantenere raggiungibili i vecchi link firmati fino a scadenza.
- [ ] Android: nel nuovo Firebase account/progetto se previsto ricreare client, `google-services.json`, credenziale server e permessi FCM; aggiornare API_BASE_URL, manifest intent-filter, assetlinks.json, impronte SHA256 di debug/release dove previste, deep link delle notifiche social, carpool, chat, recensioni e pagamenti; ricompilare APK/AAB. Per WhatsApp ONE_TAP verificare anche package e hash di firma a 11 caratteri nei `supported_apps` del template: il solo dominio non li cambia, una nuova applicationId o firma sì. Usare il certificato effettivo della distribuzione (App signing di Play per Play, non la chiave di upload); conservare Copia codice come alternativa.
- [ ] Web push: nel nuovo account rigenerare/ricollegare service worker, scope, VAPID e credenziali; spiegare che le sottoscrizioni sono per origine e richiedono una nuova adesione.
- [ ] reCAPTCHA/Turnstile se attivati, Maps/geocoding e ogni API con referrer/host consentiti.
- [ ] Webhook prenotazioni/pagamenti e URL di ritorno, Stripe o altri servizi eventualmente configurati.
- [ ] Storage/CDN, URL immagini, sitemap, robots, Open Graph, feed RSS/ICS, widget incorporati e URL dei calendari.
- [ ] Monitoraggio: ricollegare nel nuovo account Sentry, analytics, healthcheck, deploy hooks, cron, backup, storage/CDN e GitHub environments; ruotare le credenziali senza spegnere il rollback.
- [ ] Cerca il dominio precedente nel repository, DB di configurazione e provider; non riscrivere indiscriminatamente dati storici o firme.
- [ ] Smoke test anonimo/iscritto/verificato/admin; verifica email reale, WhatsApp autorizzato, OAuth Calendar e App Links su dispositivo.

Non cambiare APP_KEY o WHATSAPP_PHONE_HASH_KEY: cifratura e unicità dei numeri dipendono da queste chiavi. Trasferirle tramite canale protetto.
