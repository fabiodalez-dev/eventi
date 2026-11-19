{{--
    La pagina campione delle pagine di servizio (`/_design/servizio`): tutti i
    componenti del sistema, con i testi di prova, in una pagina sola. Esiste
    solo in sviluppo e nei test; serve a chi migra una pagina per vedere come
    si compongono i pezzi, e a chi cambia un componente per vedere cosa tocca.
--}}
<x-layouts.app service :title="__('ui.design_sample.title')">
    <x-service-page
        :title="__('ui.design_sample.title')"
        :subtitle="__('ui.design_sample.subtitle')"
        :eyebrow="__('ui.design_sample.eyebrow')"
        :back="url('/')"
        :back-label="__('ui.design_sample.back')"
        :tabs="[
            ['href' => url('/_design/servizio'), 'label' => __('ui.design_sample.tab_components'), 'active' => true],
            ['href' => url('/_design/servizio#moduli'), 'label' => __('ui.design_sample.tab_forms'), 'active' => false],
            ['href' => url('/_design/servizio#elenchi'), 'label' => __('ui.design_sample.tab_lists'), 'active' => false],
            ['href' => url('/_design/servizio#numeri'), 'label' => __('ui.design_sample.tab_numbers'), 'active' => false],
        ]"
        :tabs-label="__('ui.design_sample.tabs_label')"
    >
        <x-slot:actions>
            <x-button>{{ __('ui.design_sample.primary') }}</x-button>
            <x-button variant="secondary">{{ __('ui.design_sample.secondary') }}</x-button>
        </x-slot:actions>

        <x-notice tone="info" :title="__('ui.design_sample.notice_info_title')">{{ __('ui.design_sample.notice_info_body') }}</x-notice>
        <x-notice tone="success">{{ __('ui.design_sample.notice_success_body') }}</x-notice>

        <x-card :title="__('ui.design_sample.buttons_title')" :description="__('ui.design_sample.buttons_description')">
            <div class="flex flex-wrap gap-3">
                <x-button>{{ __('ui.design_sample.primary') }}</x-button>
                <x-button variant="secondary">{{ __('ui.design_sample.secondary') }}</x-button>
                <x-button variant="danger">{{ __('ui.design_sample.danger') }}</x-button>
                <x-button variant="ghost">{{ __('ui.design_sample.ghost') }}</x-button>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-button size="sm">{{ __('ui.design_sample.small') }}</x-button>
                <x-button size="sm" variant="secondary">{{ __('ui.design_sample.small') }}</x-button>
                <x-button size="sm" variant="danger">{{ __('ui.design_sample.small') }}</x-button>
            </div>
        </x-card>

        <x-card id="moduli" :title="__('ui.design_sample.form_title')" :description="__('ui.design_sample.form_description')">
            <x-field name="design_name" :label="__('ui.design_sample.field_name')" :hint="__('ui.design_sample.field_name_hint')" :required="true" />
            <x-field name="design_email" type="email" :label="__('ui.design_sample.field_email')" />
            <x-field name="design_notes" type="textarea" rows="3" :label="__('ui.design_sample.field_notes')" />
            <x-slot:footer>
                <x-form-actions>
                    <x-button type="submit">{{ __('ui.design_sample.save') }}</x-button>
                    <x-button variant="secondary" :href="url('/_design/servizio')">{{ __('ui.design_sample.cancel') }}</x-button>
                </x-form-actions>
            </x-slot:footer>
        </x-card>

        <x-card id="elenchi" :title="__('ui.design_sample.rows_title')" padding="none">
            <x-card.row :href="url('/_design/servizio')" icon="bookmark" :title="__('ui.design_sample.row_saved')" :description="__('ui.design_sample.row_saved_hint')" />
            <x-card.row :href="url('/_design/servizio')" icon="ticket" :title="__('ui.design_sample.row_tickets')" :badge="__('ui.design_sample.row_new')" />
            <x-card.row :title="__('ui.design_sample.row_person')" :description="__('ui.design_sample.row_person_hint')">
                <x-slot:leading><x-avatar :name="__('ui.design_sample.row_person')" /></x-slot:leading>
                <x-slot:trailing><x-button size="sm" variant="secondary">{{ __('ui.design_sample.follow') }}</x-button></x-slot:trailing>
            </x-card.row>
        </x-card>

        <x-card :title="__('ui.design_sample.events_title')" :description="__('ui.design_sample.events_description')">
            <x-segmented :label="__('ui.design_sample.segmented_label')" :items="[
                ['label' => __('ui.design_sample.upcoming'), 'href' => url('/_design/servizio'), 'active' => true, 'count' => $occurrences->count()],
                ['label' => __('ui.design_sample.past'), 'href' => url('/_design/servizio?passati=1'), 'active' => false],
                ['label' => __('ui.design_sample.calendar'), 'href' => url('/_design/servizio?calendario=1'), 'active' => false],
            ]" />
            @forelse ($occurrences as $occurrence)
                <x-service-event-row :occurrence="$occurrence">
                    <x-slot:badge><x-badge tone="brand" size="sm">{{ __('ui.design_sample.confirmed') }}</x-badge></x-slot:badge>
                    <x-slot:actions>
                        <x-button size="sm" variant="secondary" :href="url('/_design/servizio')">{{ __('ui.design_sample.details') }}</x-button>
                        <x-button size="sm" variant="danger">{{ __('ui.design_sample.cancel_booking') }}</x-button>
                    </x-slot:actions>
                </x-service-event-row>
            @empty
                <x-empty-state :title="__('ui.design_sample.empty_title')" :description="__('ui.design_sample.empty_description')" />
            @endforelse
            @if ($withoutPoster !== null)
                <x-service-event-row :occurrence="$withoutPoster" size="sm" :meta="__('ui.design_sample.no_poster')" />
            @endif
        </x-card>

        <x-card id="numeri" :title="__('ui.design_sample.stats_title')" tone="muted">
            <x-stat-list :label="__('ui.design_sample.stats_title')" :items="[
                ['label' => __('ui.design_sample.stat_confirmed'), 'value' => 42, 'hint' => __('ui.design_sample.stat_confirmed_hint')],
                ['label' => __('ui.design_sample.stat_waitlist'), 'value' => 3],
                ['label' => __('ui.design_sample.stat_checked_in'), 'value' => 18],
                ['label' => __('ui.design_sample.stat_capacity'), 'value' => 50],
            ]" />
        </x-card>

        <x-card :title="__('ui.design_sample.danger_title')" :description="__('ui.design_sample.danger_description')" tone="alert">
            <x-notice tone="alert" :title="__('ui.design_sample.notice_alert_title')">{{ __('ui.design_sample.notice_alert_body') }}</x-notice>
            <x-slot:actions>
                <x-button variant="danger" size="sm">{{ __('ui.design_sample.leave') }}</x-button>
            </x-slot:actions>
        </x-card>
    </x-service-page>
</x-layouts.app>
