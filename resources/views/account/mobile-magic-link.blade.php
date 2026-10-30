{{--
    La pagina di ripiego del collegamento dell'app (`/app/auth/magic?token=…`).

    Si vede quando Android apre il collegamento nel browser invece che
    nell'app: finché gli App Links non sono verificati (manca
    `assetlinks.json`) succede sempre. Chi si è appena registrato dall'app non
    può «accedere con la password», che resta rifiutata finché l'indirizzo non
    è confermato (D64): la pagina offre allora di riportare il collegamento
    nell'app con un `intent://` che nomina il pacchetto, come la conferma
    dell'email (`account.verify-app`). Android lo consegna solo all'app
    ufficiale, che lo scambia con il verificatore PKCE salvato sul telefono.

    Il gettone non entra nell'HTML: lo legge lo script da `location.search`,
    così la pagina resta uguale per ogni collegamento e non lo riflette in una
    cache o in un registro. La rotta manda `no-store` e `no-referrer`.
--}}
<!doctype html>
<html lang="it">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><meta name="robots" content="noindex, nofollow"><title>Accesso all’app — inCittà</title></head>
<body>
<main>
    <h1>Completa l’accesso nell’app inCittà</h1>
    <p>Apri questo collegamento sul telefono da cui ti sei registrato o hai chiesto l’accesso, con l’app aggiornata.</p>
    <p><a id="apri-app" href="{{ route('account.magic-link') }}" rel="noreferrer" data-package="{{ config('account.android_package') }}" data-fallback="{{ route('account.magic-link') }}">Apri nell’app inCittà</a></p>
    <p>Se l’app non si apre, puoi entrare dal sito chiedendo un collegamento di accesso.</p>
    <a href="/">Torna a inCittà</a>
</main>
<script @cspNonce>
    (function () {
        var link = document.getElementById('apri-app');
        var token = new URLSearchParams(window.location.search).get('token') || '';
        if (!link || !/^[A-Za-z0-9]{64}$/.test(token)) {
            return;
        }
        link.href = 'intent://auth/magic?token=' + encodeURIComponent(token)
            + '#Intent;scheme=incitta;package=' + link.dataset.package
            + ';S.browser_fallback_url=' + encodeURIComponent(link.dataset.fallback) + ';end';
    })();
</script>
</body>
</html>
