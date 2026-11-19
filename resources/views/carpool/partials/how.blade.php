{{-- «Come funziona»: i quattro passi di un passaggio, dalla scheda evento alla chat. --}}
<x-card tone="muted" :title="__('carpool.web.how.title')" :description="__('carpool.web.how.description')" data-carpool-how>
    <ol class="m-0 flex list-none flex-col gap-3 p-0">
        @foreach (__('carpool.web.how.steps') as $index => $step)
            <li class="flex items-start gap-3 text-sm text-ink">
                <span aria-hidden="true" class="flex size-7 shrink-0 items-center justify-center bg-brand font-display text-sm font-extrabold text-on-brand">{{ $index + 1 }}</span>
                <span class="pt-0.5">{{ $step }}</span>
            </li>
        @endforeach
    </ol>
</x-card>
