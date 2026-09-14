# Misurare le prestazioni

`php artisan serve` non comprime le risposte e usa un solo processo. Per questo
non va usato per confrontare Lighthouse o il TTFB con la produzione.

Il server locale di misura è FrankenPHP:

```bash
brew install dunglas/frankenphp/frankenphp
frankenphp php-server --root public --listen 127.0.0.1:8094
```

Verificare che la risposta sia compressa:

```bash
curl -sS -o /dev/null -D - -H 'Accept-Encoding: br, gzip' http://127.0.0.1:8094/ | grep -i content-encoding
```

Poi eseguire Lighthouse con lo stesso profilo usato per le misure precedenti:

```bash
npx lighthouse http://127.0.0.1:8094/ --output=json --quiet --chrome-flags="--headless"
```

Un numero locale e uno di produzione non sono confrontabili se i database
contengono eventi diversi. Fra i due ambienti si confrontano CSS, JavaScript e
font, che provengono dalla stessa build; non il peso o il tempo delle immagini.
