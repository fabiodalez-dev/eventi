<form method="POST" action="{{ route('ticketing.manage.checkin', $date) }}" class="mt-4 flex flex-col gap-4" data-ticket-scanner data-checkin-queue data-fallback="{{ __('ticketing.scanner_fallback') }}" data-ready="{{ __('ticketing.scanner_ready') }}" data-pending="{{ __('decision.offline_pending') }}" data-success="{{ __('ticketing.checkin_success') }}" data-error="{{ __('decision.scan_failed') }}">@csrf
    <p class="text-sm text-ink-muted">{{ __('decision.offline_hint') }}</p>
    <div class="flex flex-wrap gap-3"><x-button variant="secondary" data-scan-start>{{ __('ticketing.scan') }}</x-button><x-button variant="secondary" data-scan-stop hidden>{{ __('ticketing.stop_scan') }}</x-button></div>
    <video hidden muted playsinline class="w-full max-w-md" aria-label="{{ __('ticketing.scan') }}"></video><p data-scan-message role="status"></p>
    <x-field name="code" :label="__('ticketing.code')" :required="true" autocomplete="off" />
    <x-button type="submit" class="min-h-12">{{ __('ticketing.checkin') }}</x-button>
    <x-button variant="secondary" data-checkin-retry hidden>{{ __('decision.offline_retry') }}</x-button>
    <ul data-checkin-results class="space-y-3" aria-live="polite"></ul>
</form>
