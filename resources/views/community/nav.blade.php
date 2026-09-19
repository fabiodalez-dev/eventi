<x-section-tabs :label="__('community.nav')" :items="collect(['community.feed' => 'feed', 'community.people' => 'people', 'community.settings' => 'settings', 'community.inbox' => 'inbox'])
    ->map(fn ($label, $route) => ['href' => route($route), 'label' => __('community.tabs.'.$label), 'active' => request()->routeIs($route)])->values()->all()" />
