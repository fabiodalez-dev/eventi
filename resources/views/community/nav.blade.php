<nav class="mb-8 flex flex-wrap gap-x-6 gap-y-0 border-b border-line text-sm font-semibold sm:mb-10" aria-label="{{ __('community.nav') }}">
    @foreach (['community.feed' => 'feed', 'community.people' => 'people', 'community.settings' => 'settings', 'community.inbox' => 'inbox'] as $route => $label)
        <a href="{{ route($route) }}" class="relative -mb-px inline-flex min-h-13 items-center border-b-2 py-3 transition-colors hover:text-brand {{ request()->routeIs($route) ? 'border-brand text-brand' : 'border-transparent text-ink-muted' }}" @if(request()->routeIs($route)) aria-current="page" @endif>{{ __('community.tabs.'.$label) }}</a>
    @endforeach
</nav>
@if($errors->any())<div role="alert" class="mb-6 border-l-4 border-brand bg-surface p-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
