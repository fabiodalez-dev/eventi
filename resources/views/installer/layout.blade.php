{{--
    Il telaio delle sette schermate del wizard (D42).

    Il CSS è **in linea** e non viene da `npm run build`: l'installer deve
    disegnarsi anche su una macchina dove gli asset non sono mai stati
    compilati, che è esattamente la macchina su cui gira un installer. I colori
    sono gli stessi ruoli di `resources/css/app.css` — sfondo, superficie,
    inchiostro, marchio — scritti qui a mano perché non c'è nessun foglio da
    cui ereditarli.

    Una colonna sola, larga al massimo 44rem e con padding di 1rem: a 390 px di
    larghezza non c'è niente da far scorrere in orizzontale, e i campi arrivano
    a bordo schermo.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? __('installer.title') }} · {{ __('installer.app') }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --canvas: oklch(0.985 0.006 95);
            --surface: oklch(1 0 0);
            --sunken: oklch(0.965 0.008 95);
            --ink: oklch(0.24 0.03 290);
            --ink-muted: oklch(0.48 0.02 290);
            --line: oklch(0.9 0.01 290);
            --brand: oklch(0.53 0.23 300);
            --brand-strong: oklch(0.45 0.23 300);
            --on-brand: oklch(0.99 0.01 300);
            --bad: oklch(0.56 0.21 22);
            --bad-soft: oklch(0.95 0.04 30);
            --warn: oklch(0.63 0.16 70);
            --warn-soft: oklch(0.95 0.05 80);
            --good: oklch(0.55 0.14 165);
            --good-soft: oklch(0.94 0.05 165);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --canvas: oklch(0.17 0.02 288);
                --surface: oklch(0.21 0.025 288);
                --sunken: oklch(0.19 0.022 288);
                --ink: oklch(0.95 0.01 288);
                --ink-muted: oklch(0.74 0.02 288);
                --line: oklch(0.32 0.02 288);
                --brand: oklch(0.72 0.19 300);
                --brand-strong: oklch(0.78 0.18 300);
                --on-brand: oklch(0.17 0.03 300);
                --bad-soft: oklch(0.28 0.07 25);
                --warn-soft: oklch(0.28 0.06 70);
                --good-soft: oklch(0.26 0.05 165);
            }
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 1rem;
            background: var(--canvas);
            color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 1rem;
            line-height: 1.55;
        }

        .wrap { max-width: 44rem; margin: 0 auto; }

        header.masthead { padding: 0.5rem 0 1.25rem; }
        .eyebrow { font-size: 0.8125rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--ink-muted); margin: 0; }
        h1 { font-size: 1.5rem; line-height: 1.25; margin: 0.35rem 0 0.5rem; }
        h2 { font-size: 1.125rem; margin: 1.75rem 0 0.5rem; }
        p { margin: 0 0 0.75rem; }
        .lead { color: var(--ink-muted); margin: 0; }

        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 0.875rem;
            padding: 1.25rem;
        }

        .steps { display: flex; flex-wrap: wrap; gap: 0.35rem; list-style: none; margin: 0 0 1rem; padding: 0; font-size: 0.8125rem; }
        .steps li { color: var(--ink-muted); }
        .steps li::after { content: "›"; margin-left: 0.35rem; opacity: 0.5; }
        .steps li:last-child::after { content: ""; }
        .steps li[aria-current] { color: var(--brand); font-weight: 600; }

        label { display: block; font-weight: 600; margin-bottom: 0.25rem; }
        .field { margin-bottom: 1.1rem; }
        .field :is(input, select) {
            width: 100%;
            padding: 0.6rem 0.7rem;
            font: inherit;
            color: var(--ink);
            background: var(--sunken);
            border: 1px solid var(--line);
            border-radius: 0.5rem;
        }
        .field :is(input, select):focus-visible { outline: 2px solid var(--brand); outline-offset: 1px; }
        .help { display: block; font-size: 0.875rem; color: var(--ink-muted); margin-top: 0.3rem; }
        .error { display: block; font-size: 0.875rem; color: var(--bad); margin-top: 0.3rem; font-weight: 600; }
        .pair { display: grid; gap: 0 1rem; grid-template-columns: 1fr; }
        @media (min-width: 34rem) { .pair { grid-template-columns: 1fr 1fr; } }

        button, .button {
            display: inline-block;
            font: inherit;
            font-weight: 600;
            padding: 0.65rem 1.1rem;
            border-radius: 0.5rem;
            border: 1px solid transparent;
            background: var(--brand);
            color: var(--on-brand);
            cursor: pointer;
            text-decoration: none;
        }
        button:hover, .button:hover { background: var(--brand-strong); }
        .button.secondary { background: transparent; color: var(--brand); border-color: var(--line); }
        .actions { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: center; margin-top: 1.25rem; }

        .notice { border-radius: 0.625rem; padding: 0.8rem 0.9rem; margin-bottom: 1rem; border: 1px solid var(--line); }
        .notice.bad { background: var(--bad-soft); border-color: var(--bad); }
        .notice.good { background: var(--good-soft); }
        .notice.warn { background: var(--warn-soft); }
        .notice p:last-child { margin-bottom: 0; }

        ul.checks { list-style: none; margin: 0; padding: 0; }
        ul.checks li { padding: 0.6rem 0; border-top: 1px solid var(--line); }
        ul.checks li:first-child { border-top: 0; }
        .row { display: flex; gap: 0.6rem; align-items: baseline; flex-wrap: wrap; }
        .row .name { flex: 1 1 12rem; min-width: 0; }
        .tag { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.1rem 0.45rem; border-radius: 999px; white-space: nowrap; }
        .tag.ok { background: var(--good-soft); color: var(--good); }
        .tag.avviso { background: var(--warn-soft); color: var(--warn); }
        .tag.bloccante { background: var(--bad-soft); color: var(--bad); }

        pre {
            background: var(--sunken);
            border: 1px solid var(--line);
            border-radius: 0.5rem;
            padding: 0.7rem 0.8rem;
            margin: 0.5rem 0 0;
            overflow-x: auto;
            font-size: 0.875rem;
            line-height: 1.5;
        }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }

        dl.summary { margin: 0; }
        dl.summary div { display: flex; flex-wrap: wrap; gap: 0 0.5rem; padding: 0.45rem 0; border-top: 1px solid var(--line); }
        dl.summary div:first-child { border-top: 0; }
        dl.summary dt { flex: 0 0 11rem; color: var(--ink-muted); }
        dl.summary dd { margin: 0; flex: 1 1 12rem; min-width: 0; overflow-wrap: anywhere; font-weight: 600; }

        a { color: var(--brand); }
    </style>
</head>
<body>
<div class="wrap">
    <header class="masthead">
        <p class="eyebrow">{{ __('installer.app') }} · {{ __('installer.title') }}</p>
        @isset($step)
            <h1>{{ $step->label() }}</h1>
            <p class="lead">{{ __('installer.steps.'.$step->value.'.intro') }}</p>
        @else
            <h1>{{ $title ?? __('installer.title') }}</h1>
        @endisset
    </header>

    @isset($step)
        <ol class="steps">
            @foreach (\App\Enums\InstallerStep::cases() as $case)
                <li @if ($case === $step) aria-current="step" @endif>{{ $case->label() }}</li>
            @endforeach
        </ol>
    @endisset

    @if (session('installer_error'))
        <div class="notice bad" role="alert">
            <p>{{ session('installer_error') }}</p>
            @if (session('installer_command'))
                <p>{{ __('installer.actions.copy_hint') }}</p>
                <pre><code>{{ session('installer_command') }}</code></pre>
            @endif
        </div>
    @endif

    @if (session('installer_notice'))
        <div class="notice good">
            <p>{{ session('installer_notice') }}</p>
        </div>
    @endif

    <main class="card">
        @yield('content')
    </main>
</div>
</body>
</html>
