{{-- Le pagine della scheda «Aiuto»: requisiti, regole, assistenza. --}}
<x-segmented :label="__('carpool.web.help_nav')" :items="[
    ['label' => __('carpool.requirements'), 'href' => route('carpool.requirements'), 'active' => request()->routeIs('carpool.requirements')],
    ['label' => __('carpool.rules'), 'href' => route('carpool.terms'), 'active' => request()->routeIs('carpool.terms')],
    ['label' => __('carpool.support'), 'href' => route('carpool.cases'), 'active' => request()->routeIs('carpool.cases', 'carpool.case')],
]" />
