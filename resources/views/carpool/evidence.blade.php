<x-layouts.app :title="__('carpool.admin.evidence')">
    <div class="mx-auto max-w-4xl px-5 py-10">
        <h1 class="text-3xl font-bold">{{ __('carpool.admin.evidence') }} #{{ $evidence['case_id'] }}</h1>
        <p class="my-4">{{ __('carpool.admin.access_logged') }}</p>
        <pre class="overflow-auto whitespace-pre-wrap break-words rounded-xl border border-line p-5 text-sm">{{ json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</x-layouts.app>
