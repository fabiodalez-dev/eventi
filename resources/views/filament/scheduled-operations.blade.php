<x-filament-panels::page>
    @php
        $overview = app(\App\Services\Operations\SchedulerOverview::class);
        $installed = $overview->installed();
        $cron = $overview->cron();
        $tasks = $overview->tasks();
    @endphp
    <p style="max-width:75ch">Tutte le attività programmate passano dallo scheduler Laravel. Installa una sola volta i due cron qui sotto: lo scheduler decide cosa eseguire, il worker completa i lavori in coda. Non aggiungere un cron separato per ogni riga dell’elenco.</p>
    <x-filament::section heading="Cron del server">
        @if($installed['readable'])
            <p>Scheduler: <strong>{{ $installed['scheduler'] ? 'presente nel crontab' : 'non trovato nel crontab' }}</strong>. Worker: <strong>{{ $installed['worker'] ? 'presente nel crontab' : 'non trovato nel crontab' }}</strong>.</p>
        @else
            <p>Il processo web non può leggere il crontab. Verifica queste righe in cPanel → Cron Jobs o con <code>crontab -l</code>. Questo non indica da solo che il cron sia fermo.</p>
        @endif
        <p style="margin-top:12px">Esecuzione ogni minuto. <code>flock</code> impedisce che due copie dello stesso processo si sovrappongano; i log sono separati. Sostituisci eventuali righe precedenti dello stesso progetto, senza duplicarle.</p>
        @foreach($cron as $kind => $line)
            <h3 style="margin-top:20px;font-weight:700">{{ $kind === 'scheduler' ? '1. Scheduler' : '2. Worker della coda' }}</h3>
            <pre style="white-space:pre-wrap;overflow-wrap:anywhere;padding:16px;background:var(--fi-canvas);border:1px solid var(--fi-line);margin-top:8px"><code>{{ $line }}</code></pre>
        @endforeach
        <p style="margin-top:16px">Il worker richiede una coda persistente: configurazione attuale <strong>{{ config('queue.default') }}</strong>. Per controllare manualmente usa <code>php artisan schedule:list</code>, <code>php artisan queue:failed</code> e <code>php artisan health:check</code>. In produzione usa il percorso PHP mostrato sopra.</p>
    </x-filament::section>
    <x-filament::section heading="Instagram, Facebook e Telegram">
        <p>In <a href="{{ \App\Filament\Admin\Pages\SocialSettings::getUrl() }}" class="fi-link">Social → Impostazioni</a> collega e verifica l’account Meta. Per il riepilogo quotidiano abilita la pubblicazione automatica e scegli l’ora. Per un singolo contenuto prepara l’anteprima in Social, scegli data e ora e premi Programma.</p>
        <p style="margin-top:12px">La data social segue il fuso della città. L’invio parte dopo l’orario scelto, con un controllo ogni minuto e i tempi di elaborazione di Meta. Gli eventi modificati fermano il post; gli esiti incerti richiedono verifica su Instagram prima di un nuovo tentativo. Le storie si scaricano e si pubblicano manualmente.</p>
        @forelse(\App\Models\SocialConnection::all() as $connection)
            <p style="margin-top:12px">Telegram {{ $connection->telegram_verified_at ? "verificato" : "da configurare o verificare" }}. Collegamento {{ $connection->city_id }}: {{ $connection->verified_at ? 'verificato il '.$connection->verified_at->format('d/m/Y H:i').' UTC' : 'da verificare' }}. Instagram {{ $connection->instagram_enabled ? 'abilitato' : 'disabilitato' }}. Autopost {{ $connection->automatic ? 'attivo alle '.$connection->publish_time : 'disattivato' }}.</p>
        @empty
            <p role="status" style="margin-top:12px;font-weight:700">Nessun collegamento social configurato: autopost non operativo.</p>
        @endforelse
    </x-filament::section>
    <x-filament::section heading="Attività registrate">
        <p style="margin-bottom:16px">Elenco letto dallo scheduler reale. Espressioni cron, prossime esecuzioni e storico sono in UTC. La prossima esecuzione è un controllo: le condizioni della singola attività possono rinviare il lavoro. Nessun comando viene eseguito aprendo questa pagina.</p>
        <div style="overflow-x:auto">
            <table style="width:100%;text-align:left;border-collapse:collapse">
                <thead><tr>@foreach(['Attività e spiegazione','Espressione cron','Prossimo controllo UTC','Ultimo completamento / errore UTC'] as $heading)<th style="padding:12px;border-bottom:1px solid var(--fi-line)">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody>@foreach($tasks as $task)<tr>
                    <td style="padding:12px;min-width:260px;max-width:540px;border-bottom:1px solid var(--fi-line)"><strong>{{ $task['name'] }}</strong><p style="margin-top:6px">{{ $task['explanation'] }}</p><code style="display:block;overflow-wrap:anywhere;margin-top:8px">{{ $task['command'] }}</code></td>
                    <td style="padding:12px;border-bottom:1px solid var(--fi-line);white-space:nowrap"><code>{{ $task['expression'] }}</code></td>
                    <td style="padding:12px;border-bottom:1px solid var(--fi-line)">{{ $task['next_due_date'] }}</td>
                    <td style="padding:12px;border-bottom:1px solid var(--fi-line)">{{ $task['last_finished'] ?? 'Nessun completamento registrato' }}<br>{{ $task['last_failed'] ? 'Ultimo errore: '.$task['last_failed'] : 'Nessun errore registrato' }}@if(!$task['monitored'])<br><small>Fuori dal registro del monitor: battito/worker dedicato oppure sincronizzazione da eseguire.</small>@endif</td>
                </tr>@endforeach</tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
