# Migrazione al dominio definitivo

Decisione persistente del proprietario: 18 settembre 2026. Il dominio attuale è temporaneo.

- [ ] Inventariare configurazione runtime, variabili ambiente e pannelli dei provider senza esportare segreti nei documenti.
- [ ] DNS, HTTPS, dominio canonico, APP_URL, cookie sessione, CORS/Sanctum se utilizzati e redirect 301 del vecchio dominio.
- [ ] Meta: proprietà/verifica DNS del nuovo dominio, domini autorizzati, App Domains, OAuth redirect URI, privacy policy e termini, eventuali callback di cancellazione dati. La verifica del portfolio è distinta dalla verifica del dominio.
- [ ] Kapso/WhatsApp: profilo business e website, eventuali webhook e firme, URL dei template con link. Il template OTP copy-code non contiene URL: numero e credenziali restano validi se resta lo stesso WABA.
- [ ] Google Calendar: origini autorizzate e callback OAuth del nuovo dominio; aggiornare URL nelle schermate consenso.
- [ ] Email: mittente, dominio SPF/DKIM/DMARC, link di conferma, recupero password e magic link; mantenere raggiungibili i vecchi link firmati fino a scadenza.
- [ ] Android: API_BASE_URL, manifest intent-filter, assetlinks.json, impronte SHA256 di debug/release dove previste, deep link notifiche e pagamenti; ricompilare APK/AAB.
- [ ] Web push: service worker, scope, VAPID e sottoscrizioni; spiegare che le sottoscrizioni sono per origine e richiedono una nuova adesione.
- [ ] reCAPTCHA/Turnstile se attivati, Maps/geocoding e ogni API con referrer/host consentiti.
- [ ] Webhook prenotazioni/pagamenti e URL di ritorno, Stripe o altri servizi eventualmente configurati.
- [ ] Storage/CDN, URL immagini, sitemap, robots, Open Graph, feed RSS/ICS, widget incorporati e URL dei calendari.
- [ ] Monitoraggio: Sentry, Telescope/Pulse protetti, healthcheck, deploy hooks, cron, backup e GitHub environments.
- [ ] Cerca il dominio precedente nel repository, DB di configurazione e provider; non riscrivere indiscriminatamente dati storici o firme.
- [ ] Smoke test anonimo/iscritto/verificato/admin; verifica email reale, WhatsApp autorizzato, OAuth Calendar e App Links su dispositivo.

Non cambiare APP_KEY o WHATSAPP_PHONE_HASH_KEY: cifratura e unicità dei numeri dipendono da queste chiavi. Trasferirle tramite canale protetto.
