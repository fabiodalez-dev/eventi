        <form data-appearance-form action="{{ route('appearance.update') }}" method="POST" class="mt-8">
            @csrf
            @method('PATCH')
            <fieldset>
                <legend class="sr-only">Tema del sito</legend>
                <div class="appearance-options">
                    @foreach (['dark' => ['Scuro', 'Nero e lime'], 'light' => ['Chiaro', 'Carta e terracotta']] as $value => [$label, $description])
                        <button type="{{ auth()->check() ? 'submit' : 'button' }}" name="appearance" value="{{ $value }}" data-appearance-choice="{{ $value }}" aria-pressed="{{ (auth()->user()?->appearance ?? 'dark') === $value ? 'true' : 'false' }}" class="appearance-option">
                            <span class="appearance-preview appearance-preview--{{ $value }}" aria-hidden="true">
                                <span class="preview-nav"><span></span><i></i><i></i></span>
                                <span class="preview-content"><b></b><i></i><span><em></em><em></em><em></em></span></span>
                            </span>
                            <span class="appearance-caption"><span><strong>{{ $label }}</strong><small>{{ $description }}</small></span><span class="appearance-check" aria-hidden="true">✓</span></span>
                        </button>
                    @endforeach
                </div>
            </fieldset>
            <p class="mt-5 text-sm text-ink-muted">{{ auth()->check() ? 'La scelta si salva automaticamente nel tuo profilo, anche per i prossimi accessi e sugli altri dispositivi.' : 'La scelta resta salvata in questo browser. Accedi per ritrovarla anche sugli altri dispositivi.' }}</p>
            <p data-appearance-status role="status" aria-live="polite" class="mt-3 min-h-6 text-sm"></p>
            <noscript><p class="mt-3 text-sm">{{ auth()->check() ? 'Seleziona un tema per salvarlo e applicarlo.' : 'Attiva JavaScript per scegliere e memorizzare il tema in questo browser.' }}</p></noscript>
        </form>
