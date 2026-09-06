# Cache del frontend

La cache HTML passa da Laravel, mai da file HTML pubblici o da LSCache condivisa.
Silber/page-cache è adatto a pagine statiche: qui il markup include CSRF, consenso,
componenti Livewire e stato autenticato. Servirlo saltando PHP richiederebbe una
separazione architetturale di questi elementi. Non installiamo un secondo motore
che duplichi quello già testato.

## In produzione

- Cache Laravel attiva, durata predefinita 60 secondi.
- Home, elenchi, categorie, tag e locali: cache solo per ospiti non personalizzati.
- Dettagli evento: sempre dinamici per disponibilità e finestre di prenotazione.
- Login, pannelli, salvati, biglietti, anteprime, API e ricerche: niente cache HTML.
- Chiavi separate per città, dominio, lingua, consenso, filtri e giorno locale.
- Pubblicazione/modifica eventi invalida tramite ContentVersion; ogni release
  cambia la chiave attraverso il manifest di rilascio. Non si azzerano sessioni o lock.
- HTML con `Cache-Control: private, no-store` e `X-LiteSpeed-Cache-Control: no-cache`.
- Asset Vite versionati: cache pubblica di un anno, immutable, anche su LiteSpeed.
- Gli header `X-Page-Cache: miss` e `hit` mostrano il funzionamento sui GET pubblici.
  HEAD non riempie la cache. Per controllare: `curl -sD - URL -o /dev/null` due volte.

## Comandi

`php artisan frontend:cache` verifica scrittura/lettura senza stampare credenziali.

`php artisan frontend:cache --clear` invalida soltanto le pagine HTML.

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
mantiene lo store attuale. TTL configurabile fra 1 e 5 minuti con
PAGE_CACHE_TTL_MINUTES; il valore consigliato per questo calendario è 1.

Non attivare regole «cache everything» nel pannello hosting/CDN: annullerebbero
l'isolamento di consenso, sessione e autenticazione assicurato da Laravel.

Fonti: https://laravel.com/docs/13.x/cache e
https://docs.litespeedtech.com/lscache/devguide/controls/.
