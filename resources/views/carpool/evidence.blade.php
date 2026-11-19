{{-- Le evidenze di un caso, consultate dallo staff con motivazione: l'accesso è registrato. --}}
<x-layouts.app service :title="__('carpool.admin.evidence')">
    <x-service-page :title="__('carpool.web.evidence_title', ['id' => $evidence['case_id']])" :subtitle="__('carpool.admin.access_logged')" :back="route('carpool.case', $evidence['case_id'])" :back-label="__('carpool.web.back_cases')">
        <x-card>
            <pre class="overflow-auto text-sm whitespace-pre-wrap break-words text-ink">{{ json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </x-card>
    </x-service-page>
</x-layouts.app>
