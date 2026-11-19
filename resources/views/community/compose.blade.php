{{--
    Consigliare un evento salvato: la data con la sua locandina in cima, poi
    una card con la scelta (pubblicare o no) e il testo del consiglio.
--}}
@php
    // Senza verifica valida l'unica scelta possibile è privata: un pubblico rimasto spuntato ma disabilitato non verrebbe inviato, e il salvataggio fallirebbe invece di ritirare il post.
    $verified = auth()->user()->canParticipateInCommunity();
    $visibility = $verified ? old('visibility', $visibility) : 'private';
@endphp
<x-layouts.app service :meta="$meta">
    <x-service-page
        :title="$meta->heading"
        :subtitle="__('community.privacy_help')"
        :back="route('account.saved')"
        :back-label="__('community.back_saved')"
        :tabs="\App\Services\Community\CommunityNavigation::tabs('feed')"
        :tabs-label="__('community.nav')"
    >
        <x-card>
            <x-service-event-row :occurrence="$saved->occurrence" />
        </x-card>

        <x-card as="form" data-community-form method="post" action="{{ route('community.publish', $saved->occurrence_id) }}" :title="__('community.save_privacy')">
            @csrf @method('PUT')
            <fieldset class="m-0 flex min-w-0 flex-col gap-1 border-0 p-0">
                <legend class="sr-only">{{ __('community.save_privacy') }}</legend>
                <label class="flex min-h-12 cursor-pointer items-center gap-3 text-ink"><input type="radio" name="visibility" value="private" @checked($visibility === 'private') class="size-4 shrink-0 accent-[var(--brand)]">{{ __('community.private') }}</label>
                <label @class(['flex min-h-12 items-center gap-3 text-ink', 'cursor-pointer' => $verified, 'opacity-60' => ! $verified])><input type="radio" name="visibility" value="public" @disabled(! $verified) @checked($visibility === 'public') class="size-4 shrink-0 accent-[var(--brand)]">{{ __('community.public') }}</label>
            </fieldset>
            @if ($verified)
                <input type="hidden" name="intent" value="recommend">
                <div class="flex flex-col gap-1.5">
                    <label for="consiglio" class="text-sm font-semibold text-ink">{{ __('community.body') }}</label>
                    <textarea id="consiglio" name="body" maxlength="500" rows="4" placeholder="{{ __('community.body_hint') }}" class="min-h-12 w-full border border-line bg-surface px-3.5 py-2.5 text-base text-ink placeholder:text-ink-subtle focus:border-brand focus:outline-none">{{ old('body', $post?->body) }}</textarea>
                </div>
            @else
                <x-notice :title="__('community.verification_required')">
                    <div><x-button size="sm" variant="secondary" :href="route('community.whatsapp')">{{ __('community.whatsapp.title') }}</x-button></div>
                </x-notice>
            @endif
            <p class="m-0 text-sm text-ink-muted">{{ __('community.withdraw_help') }}</p>
            <x-form-actions><x-button type="submit">{{ __('community.save') }}</x-button></x-form-actions>
        </x-card>
    </x-service-page>
</x-layouts.app>
