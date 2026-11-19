@php
    /*
     * Quello che serve a chi modera e non si legge sulla scheda pubblica:
     * provenienza, verifica, chi l'ha inserito, quando è uscito, il motivo di
     * un rifiuto. Sola lettura: le decisioni stanno nell'intestazione.
     */
    /** @var \App\Models\Event $event */
    $event->loadMissing('creator');
    $timezone = \Filament\Support\Facades\FilamentTimezone::get();
    $at = fn ($date) => $date === null ? null : \App\Filament\Support\PanelDate::event($date, $timezone);
    $rows = array_filter([
        __('admin.fields.source') => $event->source->label(),
        __('admin.fields.verification_status') => $event->verification_status->label(),
        __('admin.view_pages.created_by') => $event->creator?->name,
        __('admin.fields.created_at') => $at($event->created_at),
        __('admin.fields.updated_at') => $at($event->updated_at),
        __('admin.fields.published_at') => $at($event->published_at) ?? __('admin.placeholders.never'),
        __('admin.view_pages.scheduled_publish_at') => $at($event->scheduled_publish_at),
        __('admin.fields.featured_until') => $event->is_featured ? ($at($event->featured_until) ?? __('admin.view_pages.featured_open')) : null,
        __('admin.fields.editorial_score') => (string) $event->editorial_score,
        __('admin.fields.rejection_reason') => $event->rejection_reason,
    ], fn ($value) => filled($value));
@endphp
<x-filament::section :heading="__('admin.view_pages.editorial')" :description="__('admin.view_pages.editorial_description')" data-event-moderation>
    <dl class="divide-y divide-gray-100 dark:divide-white/10">
        @foreach ($rows as $label => $value)
            <div class="flex flex-col gap-1 py-3 first:pt-0 last:pb-0 sm:flex-row sm:gap-6">
                <dt class="shrink-0 text-sm text-gray-500 sm:w-44 dark:text-gray-400">{{ $label }}</dt>
                <dd class="min-w-0 whitespace-pre-line text-sm font-medium text-gray-950 [overflow-wrap:anywhere] dark:text-white">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>
</x-filament::section>
