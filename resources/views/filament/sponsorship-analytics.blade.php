<x-filament-panels::page>
    <style>
        .sponsor-report { display:grid; gap:1.5rem; min-width:0; }
        .sponsor-report > * { min-width:0; }
        .sponsor-report svg { display:block; width:100%; }
        .sponsor-report .sponsor-stats { display:grid; gap:1rem; }
        @media(min-width:640px) { .sponsor-report .sponsor-stats { grid-template-columns:repeat(3,minmax(0,1fr)); } }
        .sponsor-report strong { font-size:1.75rem; }
        .sponsor-report .sponsor-labels { display:flex; justify-content:space-between; gap:1rem; font-size:.875rem; }
        .sponsor-report .sponsor-scroll { overflow-x:auto; max-width:100%; }
        .sponsor-report table { width:100%; font-size:.875rem; border-collapse:collapse; }
        .sponsor-report th, .sponsor-report td { padding:.75rem; text-align:left; vertical-align:top; }
        .sponsor-report tbody tr { border-top:1px solid color-mix(in srgb,currentColor 18%,transparent); }
        .sponsor-report .sponsor-note { font-size:.875rem; margin-bottom:1rem; }
        .sponsor-report summary { cursor:pointer; font-weight:600; }
    </style>
    <div class="sponsor-report">
    <p>Visualizzazioni e clic delle campagne, anche concluse. Filtra per periodo o cerca un evento.</p>
    {{ $this->form }}
    @php $report = $this->report(); @endphp
    <div class="sponsor-stats">
        <x-filament::section heading="Visualizzazioni"><strong class="text-2xl">{{ number_format($report['impressions'], 0, ',', '.') }}</strong></x-filament::section>
        <x-filament::section heading="Clic registrati"><strong class="text-2xl">{{ number_format($report['clicks'], 0, ',', '.') }}</strong></x-filament::section>
        <x-filament::section heading="Clic / visualizzazioni"><strong class="text-2xl">{{ $report['impressions'] ? number_format(100 * $report['clicks'] / $report['impressions'], 2, ',', '.').'%' : 'Non disponibile' }}</strong></x-filament::section>
    </div>
    @foreach (['impressions' => 'Visualizzazioni giornaliere', 'clicks' => 'Clic giornalieri'] as $metric => $label)
        @php
            $max = max(1, $report['series']->max($metric));
            $points = $report['series']->map(fn ($row, $i) => (10 + $i * 580 / max(1, $report['series']->count() - 1)).','.(150 - $row[$metric] * 130 / $max))->implode(' ');
        @endphp
        <x-filament::section :heading="$label">
            <svg viewBox="0 0 600 170" class="w-full" style="max-height:220px" role="img" aria-label="{{ $label }}. Massimo {{ $max }}. I valori esatti sono nella tabella giornaliera.">
                <line x1="10" y1="150" x2="590" y2="150" stroke="currentColor" opacity=".3"/>
                <polyline points="{{ $points }}" fill="none" stroke="currentColor" stroke-width="3"/>
            </svg>
            <div class="sponsor-labels"><span>{{ $report['series']->first()['day'] }}</span><span>Massimo: {{ $max }}</span><span>{{ $report['series']->last()['day'] }}</span></div>
        </x-filament::section>
    @endforeach
    <details>
        <summary class="cursor-pointer font-semibold">Valori giorno per giorno</summary>
        <div class="sponsor-scroll"><table><thead><tr><th>Giorno</th><th>Visualizzazioni</th><th>Clic</th></tr></thead>
            <tbody>@foreach ($report['series'] as $row)<tr><td class="p-2">{{ $row['day'] }}</td><td class="text-center">{{ $row['impressions'] }}</td><td class="text-center">{{ $row['clicks'] }}</td></tr>@endforeach</tbody>
        </table></div>
    </details>
    <x-filament::section heading="Registro dei clic">
        <p class="sponsor-note">Una riga per ogni clic ricevuto e accettato dai controlli antiabuso. Il dettaglio inizia dall’attivazione del registro: i totali precedenti restano nei grafici. Nessun IP o dato personale viene conservato qui. I clic bloccati dal browser o senza connessione non possono essere misurati.</p>
        <div class="sponsor-scroll"><table><thead><tr>
            @foreach (['Data e ora (UTC)', 'Evento / locale', 'Campagna', 'Canale', 'Collocazione', 'Pagina'] as $label)<th class="p-3 text-left">{{ $label }}</th>@endforeach
        </tr></thead><tbody>
            @forelse ($report['records'] as $click)
                <tr class="border-t border-gray-200 dark:border-gray-700">
                    <td class="p-3 whitespace-nowrap">{{ $click->clicked_at->utc()->format('d/m/Y H:i:s') }}</td>
                    <td class="p-3">{{ $click->sponsorship?->event?->title ?? 'Evento non più disponibile' }}<br><span class="text-gray-500">{{ $click->sponsorship?->event?->venue?->name }}</span></td>
                    <td class="p-3">#{{ $click->sponsorship_id }}</td><td class="p-3">{{ $click->channel === 'android' ? 'Android' : 'Sito' }}</td>
                    <td class="p-3">{{ ['banner' => 'Banner orizzontale', 'home_hero' => 'Apertura home', 'card' => 'Scheda sponsorizzata'][$click->placement] ?? $click->placement }}</td>
                    <td class="p-3">{{ ['home' => 'Home', 'feed' => 'Il mio feed', 'event' => 'Evento', 'venues' => 'Locali', 'profile' => 'Profilo', 'search' => 'Ricerca', 'other' => 'Non specificata'][$click->page] ?? 'Non specificata' }}</td>
                </tr>
            @empty<tr><td colspan="6" class="p-4">Nessun clic nel registro per questo periodo.</td></tr>@endforelse
        </tbody></table></div>
        {{ $report['records']->links() }}
    </x-filament::section>
    </div>
</x-filament-panels::page>
