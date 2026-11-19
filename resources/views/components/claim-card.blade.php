{{--
    «Gestisci questo locale?»: l'invito a rivendicare un locale pubblico che
    nessuno gestisce ancora, sulla scheda del locale e nella sezione «Dove»
    della pagina di un evento. Un pulsante vero, non un collegamento in
    piccolo: è la porta da cui i gestori entrano nel catalogo.

    Non disegna niente se il locale non si può rivendicare
    (`Venue::isClaimable()`: pubblico e senza referente, nemmeno invitato).
--}}
@props(['venue', 'compact' => false])
@if ($venue instanceof \App\Models\Venue && $venue->isClaimable())
    <x-card
        as="aside"
        tone="accent"
        :padding="$compact ? 'sm' : 'md'"
        :title="__('venues.claim_card.title')"
        :description="__('venues.claim_card.description', ['venue' => $venue->name])"
        data-claim-card
        {{ $attributes }}
    >
        {{-- Nella colonna stretta della pagina evento il pulsante va sotto
             il testo; sulla scheda del locale, a tutta riga, sta a destra. --}}
        @if ($compact)
            <div>
                <x-button :href="route('venues.claim.create', $venue)" :block="true">
                    {{ __('venues.claim_card.action') }}
                    <x-lucide name="arrow-right" class="size-4" />
                </x-button>
            </div>
        @else
            <x-slot:actions>
                <x-button :href="route('venues.claim.create', $venue)" :block="true">
                    {{ __('venues.claim_card.action') }}
                    <x-lucide name="arrow-right" class="size-4" />
                </x-button>
            </x-slot:actions>
        @endif
    </x-card>
@endif
