{{-- Le stesse schede della community (x-section-tabs): la conferma dell'ultima azione la mostra già il layout. --}}
<x-section-tabs :label="__('carpool.title')" :items="array_values(array_filter([
    ['href' => route('carpool.index'), 'label' => __('carpool.mine'), 'active' => request()->routeIs('carpool.index', 'carpool.offer', 'carpool.request'), 'count' => 'pending'],
    ['href' => route('carpool.chats'), 'label' => __('carpool.messages'), 'active' => request()->routeIs('carpool.chats', 'carpool.chat'), 'count' => 'conversations'],
    config('community.enabled') ? ['href' => route('community.inbox'), 'label' => __('community.tabs.inbox'), 'active' => request()->routeIs('community.inbox'), 'count' => 'unread'] : null,
    ['href' => route('carpool.requirements'), 'label' => __('carpool.requirements'), 'active' => request()->routeIs('carpool.requirements')],
    ['href' => route('carpool.terms'), 'label' => __('carpool.rules'), 'active' => request()->routeIs('carpool.terms')],
    ['href' => route('carpool.cases'), 'label' => __('carpool.support'), 'active' => request()->routeIs('carpool.cases', 'carpool.case')],
]))" />
