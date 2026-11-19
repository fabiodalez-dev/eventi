{{-- Il modulo dello scanner: nella pagina dello scanner e, chiuso, nella gestione della data. --}}
<form method="POST" action="{{ route('ticketing.manage.checkin', $date) }}" class="flex flex-col gap-4" data-ticket-scanner data-checkin-queue data-occurrence="{{ $date->id }}" data-late-sync-hours="{{ config('ticketing.checkin.late_sync_hours') }}" data-manifest-url="{{ route('ticketing.manage.manifest', $date) }}" data-fallback="{{ __('ticketing.scanner_fallback') }}" data-denied="{{ __('ticketing.camera_denied') }}" data-ready="{{ __('ticketing.scanner_ready') }}" data-pending="{{ __('decision.offline_pending') }}" data-success="{{ __('ticketing.checkin_success') }}" data-error="{{ __('decision.scan_failed') }}" data-list-loaded="{{ __('ticketing.offline.list_loaded') }}" data-list-missing="{{ __('ticketing.offline.list_missing') }}" data-known-pending="{{ __('ticketing.offline.known_pending') }}" data-known-used="{{ __('ticketing.offline.known_used') }}" data-unknown="{{ __('ticketing.offline.unknown') }}" data-queue-label="{{ __('ticketing.offline.queue') }}">@csrf
    <p class="text-sm text-ink-muted">{{ __('decision.offline_hint') }}</p>
    <div class="flex flex-col gap-3 border border-line bg-canvas p-4">
        <p data-manifest-status role="status" class="text-sm text-ink">{{ __('ticketing.offline.list_missing') }}</p>
        <div><x-button variant="secondary" size="sm" data-manifest-refresh type="button">{{ __('ticketing.offline.refresh') }}</x-button></div>
    </div>
    <div class="flex flex-wrap gap-3"><x-button data-scan-start><x-lucide name="scan-line" class="size-4" />{{ __('ticketing.scan') }}</x-button><x-button variant="secondary" data-scan-stop hidden>{{ __('ticketing.stop_scan') }}</x-button></div>
    <video hidden muted playsinline class="aspect-square w-full bg-canvas object-cover" aria-label="{{ __('ticketing.scan') }}"></video><p data-scan-message role="status" class="text-sm text-ink empty:hidden"></p>
    <x-field name="code" :label="__('ticketing.code')" :required="true" autocomplete="off" />
    <x-form-actions>
        <x-button type="submit">{{ __('ticketing.checkin') }}</x-button>
        <x-button variant="secondary" data-checkin-retry hidden>{{ __('decision.offline_retry') }}</x-button>
    </x-form-actions>
    <ul data-checkin-results class="flex flex-col gap-2 text-sm text-ink empty:hidden" aria-live="polite"></ul>
</form>
