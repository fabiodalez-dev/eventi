# inCittà

Aggregatore editoriale di eventi locali: sito Laravel, pannello redazionale,
API v1 e app Android nativa.

## Componenti

- `app/`, `routes/`, `resources/`: applicazione Laravel 13 e frontend web.
- `android/`: app Kotlin/Jetpack Compose, package `it.fabiodalez.incitta`.
- `docs/openapi.json`: contratto OpenAPI 3.1 versionato.
- `docs/API.md`: comportamento e garanzie dell'API.
- `docs/MOBILE-APP-SPEC.md`: architettura, autenticazione e build Android.
- `docs/REVIEW-MOBILE-API-2026-09-04.md`: review completa e decisioni sui pacchetti.
- `PRODUCT.md` e `DESIGN.md`: contesto di prodotto e sistema visivo.

## Avvio Laravel

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
composer run dev
```

Il progetto richiede PHP 8.4, un database configurato in `.env` e i processi
queue/scheduler descritti in `docs/RUNBOOK.md`.

## Verifica backend

```bash
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --memory-limit=1G
php artisan test
composer audit --locked
```

La documentazione interattiva è in `/docs/api` negli ambienti autorizzati. Per
rigenerare il contratto versionato:

```bash
php artisan scramble:export --path=docs/openapi.json
```

## Build Android

Con Android SDK 36 installato e `ANDROID_HOME` configurato:

```bash
cd android
./gradlew test assembleDebug
```

L'APK debug è in `android/app/build/outputs/apk/debug/app-debug.apk`. La base
URL predefinita è la produzione; per il server Laravel locale nell'emulatore:

```bash
./gradlew assembleDebug -PapiBaseUrl=http://10.0.2.2:8000/api/v1/
```

## Segreti esterni

Nessuna chiave privata è nel repository. FCM richiede un progetto Firebase e
`FIREBASE_CREDENTIALS`; la pubblicazione Play Store richiede la upload key del
proprietario. In loro assenza l'app resta installabile e il backend usa
email/archivio come fallback notifiche.
