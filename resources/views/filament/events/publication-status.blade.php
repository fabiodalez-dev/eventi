@php($event = $getRecord())
@if ($event instanceof \App\Models\Event)
    <p><strong>{{ $event->status->label() }}</strong></p>
    @if ($event->scheduled_publish_at)
        <p>Pubblicazione prevista: <strong>{{ $event->scheduled_publish_at->timezone('Europe/Rome')->format('d/m/Y H:i') }}</strong> (Europe/Rome).</p>
        @if ($event->status === \App\Enums\EventStatus::Pending)<p>In attesa di approvazione della redazione. L’orario scelto non sostituisce l’approvazione.</p>@endif
    @else
        <p>Nessuna pubblicazione programmata. Per scegliere data e ora usa “Programma pubblicazione” sulle bozze con almeno una data.</p>
    @endif
    <p>La verifica del locale è gestita dalla redazione. Un locale verificato conferma automaticamente i propri eventi.</p>
@endif
