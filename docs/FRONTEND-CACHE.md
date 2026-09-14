# Cache del frontend

La cache HTML passa da Laravel, mai da file HTML pubblici o da LSCache condivisa.
Silber/page-cache è adatto a pagine statiche: qui il markup include CSRF, consenso,
componenti Livewire e stato autenticato. Servirlo saltando PHP richiederebbe una
separazione architetturale di questi elementi. Non installiamo un secondo motore
che duplichi quello già testato.

## In produzione

- Cache Laravel attiva, durata predefinita e minima 30 minuti.
- Home, elenchi, categorie, tag e locali: cache solo per ospiti non personalizzati.
- Dettagli evento: sempre dinamici per disponibilità e finestre di prenotazione.
- Login, pannelli, salvati, biglietti, anteprime, API e ricerche: niente cache HTML.
- Chiavi separate per città, dominio, lingua, consenso, tema, filtri e giorno locale.
- Pubblicazione/modifica eventi invalida tramite ContentVersion; ogni release
  cambia la chiave attraverso il manifest di rilascio. Non si azzerano sessioni o lock.
- Anche modifiche e rimozioni di locali, categorie e tag invalidano le copie;
  le tassonomie dei filtri si aggiornano immediatamente.
- HTML con `Cache-Control: private, no-cache` e `X-LiteSpeed-Cache-Control: no-cache`.
  Le risposte che dichiarano da sé `no-store` — biglietti e prenotazioni via
  `TicketingPrivacy`, anteprime, calendari — lo conservano: `PreventSharedResponseCache`
  riempie i vuoti e non sovrascrive più.
- Asset Vite versionati: cache pubblica di un anno, immutable, anche su LiteSpeed.
- Gli header `X-Page-Cache: miss` e `hit` mostrano il funzionamento sui GET pubblici.
  HEAD non riempie la cache. Per controllare: `curl -sD - URL -o /dev/null` due volte.
- Le voci HTML sono compresse con gzip nello store. Le vecchie voci in chiaro
  restano leggibili fino alla loro naturale scadenza.
- La home estrae dalla copia il frammento “in corso / inizia tra poco” e lo
  reinserisce dalla cache breve, quindi il TTL lungo non congela informazioni live.

## Comandi

Gli admin e superadmin trovano **Cache e prestazioni** nella sidebar del pannello
(`/admin/cache-performance`). La pulizia confermata invalida HTML, calendari,
sitemap, filtri e viste compilate, preservando impostazioni, sessioni e lock.
Si può abilitare la gestione dal pannello, scegliere file/Redis e il TTL. Il
pannello conserva il controllo del valore e accetta qualunque durata da 30
minuti in su; valori storici inferiori vengono portati a 30 anche a runtime.
Le credenziali Redis sono cifrate con Spatie Laravel Settings; il salvataggio
non attiva Redis se la prova di scrittura e lettura fallisce. Un campo password
vuoto conserva quella precedente. LiteSpeed HTML condiviso resta volutamente
non attivabile; gli asset versionati hanno già cache pubblica lunga.

`php artisan frontend:cache` verifica scrittura/lettura senza stampare credenziali.

`php artisan frontend:cache --clear` invalida soltanto le pagine HTML.

`php artisan page-cache:warm` rigenera le sei pagine pubbliche più visitate. Lo
scheduler lo esegue ogni cinque minuti, senza sovrapporre due esecuzioni.

## Redis opzionale

Il server al 7 settembre 2026 ha PhpRedis, ma nessun servizio risponde su
127.0.0.1:6379. Resta quindi attiva la cache su file; non cambiare SESSION_DRIVER
o CACHE_STORE solo per velocizzare il frontend.

Quando il provider rende disponibile Redis, impostare connessione e credenziali
REDIS_* nell'ambiente server, poi `PAGE_CACHE_STORE=frontend-redis` e
`php artisan config:cache`. Questo store usa il failover nativo Laravel da Redis
a frontend-file. Verificare la connessione Redis direttamente: un probe del
failover riuscito può indicare che sta funzionando il ripiego file, non Redis.
Configurare timeout di connessione brevi e Redis privato, mai esposto a Internet.

`PAGE_CACHE_STORE=frontend-file` isola invece gli HTML sul disco. Lasciarlo vuoto
mantiene lo store attuale. `PAGE_CACHE_TTL_MINUTES` configura il TTL con un
pavimento di 30 minuti; il pannello può impostare valori più alti quando la
gestione applicativa è attiva.

Non attivare regole «cache everything» nel pannello hosting/CDN: annullerebbero
l'isolamento di consenso, sessione e autenticazione assicurato da Laravel.

Fonti: https://laravel.com/docs/13.x/cache e
https://docs.litespeedtech.com/lscache/devguide/controls/.
