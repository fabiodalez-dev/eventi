# Famiglie, meteo e contatti

## Prima di andare

Locale e singolo evento dichiarano fasce consigliate (tutte le età, 0–2, 3–5, 6–10, 11–17, 18+) e disponibilità di passeggino, fasciatoio e area bimbi. Le indicazioni del locale sono precompilate nelle schede evento; le eccezioni dell'evento prevalgono. Le informazioni compaiono nella stessa sezione «Prima di andare» sul web e Android.

I filtri `age`, `stroller`, `changing_table`, `kids_area` sono condivisi da catalogo, mappa e API. Le disponibilità non dichiarate non soddisfano i filtri; non vengono trasformate in «no».

## Meteo

Open-Meteo è il provider scelto dal proprietario, che dichiara il sito attualmente privo di pubblicità. Il backend recupera una previsione giornaliera per coordinate del luogo effettivo e fuso locale, conserva i dati per 30 minuti e serve `GET /api/v1/occurrences/{id}/weather`. Sono ammesse date da oggi a 15 giorni inclusi; i dati non disponibili non vengono inventati. Nessuna chiamata esterna per date fuori finestra. Oltre 5 giorni la previsione è indicata come orientativa.

La pagina e l'app mostrano icona, min/max della giornata, probabilità di pioggia e vento. Se il meteo non è disponibile o la richiesta fallisce, l'intera sezione resta nascosta, senza messaggi d'errore. Attribuzione Open-Meteo / CC BY 4.0. Con futura attivazione di pubblicità riesaminare le condizioni commerciali: configurando `OPEN_METEO_API_KEY` viene usato l'endpoint clienti; nessuna chiave viene inviata ai client.

## Contatti

Locale e organizzatore configurano nel proprio backend (o tramite admin) `contact_mode`: disattivato, solo iscritti, tutti. `contact_email` è un recapito privato obbligatorio quando la funzione è attiva. Non viene restituito dalle API dei contatti.

`GET /api/v1/{venues|organizers}/{slug}/contact` espone disponibilità e URL pubblico. `POST` alla stessa rotta invia il messaggio al solo recapito configurato. L'identità degli iscritti proviene dall'account. Per ospiti, la modalità «tutti» richiede reCAPTCHA v2, verifica server-side della risposta e corrispondenza con `RECAPTCHA_HOSTNAME`; in mancanza di configurazione resta utilizzabile solo agli iscritti. Limite 5 invii/ora. Nome, email e testo vengono inoltrati via email, senza archivio aggiuntivo dei messaggi nel database.

Android offre un form nativo agli iscritti. Gli ospiti aprono il modulo web con reCAPTCHA nel browser. Le chiavi rimangono nella configurazione privata. La checklist del dominio definitivo è in `docs/CI-CD.md`.
