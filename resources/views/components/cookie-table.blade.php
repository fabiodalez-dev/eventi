{{--
    L'elenco dei cookie, letto dal registro invece che scritto nel testo.

    **Perché non basta scriverlo nella pagina.** Fin qui l'elenco stava dentro
    il corpo della Cookie Policy, come tabella in markdown. Funziona finché
    qualcuno si ricorda di aggiornarlo — e nessuno se ne ricorda, perché chi
    aggiunge uno strumento sta pensando allo strumento, non all'informativa.
    Il risultato è il caso peggiore per una pagina legale: un elenco che
    sembra completo e non lo è.

    Ora la fonte è una sola. Si aggiunge un cookie dal pannello e compare qui.

    **Le finalità vuote si mostrano lo stesso.** «Nessun cookie in questa
    finalità» non è una riga sprecata: è la risposta alla domanda che porta
    qualcuno su questa pagina — «mi state tracciando?».
--}}
@php
    $registro = \App\Models\CookieDeclaration::perCategoria();
@endphp

<div class="flex flex-col gap-8">
    @foreach (\App\Enums\ConsentCategory::cases() as $categoria)
        @php $righe = $registro[$categoria->value]; @endphp

        <section class="flex flex-col gap-3" aria-labelledby="cookie-{{ $categoria->value }}">
            <h3 id="cookie-{{ $categoria->value }}" class="font-display text-[clamp(1rem,1.4vw,1.25rem)] leading-none font-extrabold tracking-[-0.02em] uppercase">
                {{ $categoria->label() }}
            </h3>

            <p class="text-sm text-ink-muted">{{ $categoria->description() }}</p>

            @if ($righe->isEmpty())
                <p class="border-2 border-line px-4 py-3 text-sm text-ink-muted">
                    {{ __('cookies.public.none') }}
                </p>
            @else
                {{-- Una tabella che su telefono diventa un elenco di schede:
                     quattro colonne su 390 px sono illeggibili, e una tabella
                     che si può solo trascinare di lato è una tabella che non
                     si legge. --}}
                <div class="flex flex-col gap-0.5">
                    @foreach ($righe as $riga)
                        <div class="grid gap-x-4 gap-y-1 border-2 border-line p-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_minmax(0,9rem)]">
                            <p class="font-mono text-sm break-all">{{ $riga->name }}</p>
                            <p class="text-sm text-ink-muted">{{ $riga->purpose }}</p>
                            <p class="text-sm text-ink-subtle">
                                {{ $riga->duration ?? '—' }}
                                @if (filled($riga->provider))
                                    <span class="block">{{ $riga->provider }}</span>
                                @endif
                            </p>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach
</div>
