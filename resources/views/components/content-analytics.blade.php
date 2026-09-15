@props(['subject', 'type', 'occurrence' => null])
<span hidden data-content-analytics
    data-url="{{ \Illuminate\Support\Facades\URL::signedRoute('content.metrics', array_filter(['type' => $type, 'id' => $subject->getKey(), 'occurrence' => $occurrence?->getKey()]), absolute: false) }}"
    data-allowed="{{ app(\App\Support\Consent::class)->allows(\App\Enums\ConsentCategory::Statistics) ? '1' : '0' }}"
></span>
