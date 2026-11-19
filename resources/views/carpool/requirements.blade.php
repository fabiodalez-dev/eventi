{{--
    Verifiche e requisiti (scheda «Aiuto»): cosa manca per usare i passaggi,
    oppure lo stato raggiunto, le notifiche e la rettifica dell'età.
--}}
<x-layouts.app service :meta="$meta">
    <x-service-page :title="__('carpool.requirements')" :subtitle="__('carpool.web.requirements_subtitle')" :back="route('account.profile')" :tabs="$tabs" :tabs-label="__('carpool.web.tabs.label')">
        @include('carpool.partials.help-nav')

        @if (! $access['eligible'])
            <x-card :title="__('carpool.web.blocked_title')" tone="accent">
                @include('carpool.partials.gate', ['hideHeading' => true])
            </x-card>
        @else
            <x-card padding="none" :title="__('carpool.web.status_title')" :description="__('carpool.web.status_ready')" data-carpool-status>
                <x-card.row icon="check" :title="__($access['whatsapp_exempt'] && ! $access['whatsapp_verified'] ? 'community.whatsapp.exempt' : 'carpool.verified')" />
                <x-card.row icon="calendar" :title="__('carpool.adult_status')" :description="\Carbon\CarbonImmutable::parse($access['adult_declared_at'])->format('d/m/Y')" />
                <x-card.row icon="navigation" :title="__('carpool.mine')" :href="route('carpool.index')" />
            </x-card>

            <x-card :title="__('carpool.web.notifications_title')" data-carpool-push>
                <form method="post" action="{{ route('carpool.action', 'preferences') }}" class="flex flex-col gap-4">
                    @include('carpool.partials.key')
                    <input type="hidden" name="push_enabled" value="0">
                    <label class="flex min-h-12 items-center gap-3 text-sm text-ink"><input type="checkbox" name="push_enabled" value="1" @checked($access['push_enabled']) class="size-5 shrink-0 accent-brand">{{ __('carpool.push') }}</label>
                    <div><x-button type="submit" variant="secondary">{{ __('carpool.save') }}</x-button></div>
                </form>
            </x-card>

            <x-card tone="alert" padding="sm" data-carpool-revoke>
                <details class="group">
                    <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3">
                        <span class="flex flex-col"><span class="font-semibold text-ink">{{ __('carpool.web.revoke_title') }}</span><span class="text-sm text-ink-muted">{{ __('carpool.adult_revoke_help') }}</span></span>
                        <x-lucide name="chevron-down" class="size-5 shrink-0 text-ink-muted transition group-open:rotate-180" />
                    </summary>
                    <form method="post" action="{{ route('carpool.action', 'revoke-adult') }}" class="mt-4 flex flex-col gap-4">
                        @include('carpool.partials.key')
                        <label class="flex items-start gap-3 text-sm text-ink"><input required type="checkbox" name="confirm" value="1" class="mt-0.5 size-5 shrink-0 accent-brand"><span>{{ __('carpool.adult_revoke') }}</span></label>
                        <div><x-button type="submit" variant="danger">{{ __('carpool.adult_revoke') }}</x-button></div>
                    </form>
                </details>
            </x-card>
        @endif

        @include('carpool.partials.how')
    </x-service-page>
</x-layouts.app>
