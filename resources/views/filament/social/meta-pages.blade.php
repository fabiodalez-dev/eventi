{{--
    La scelta della Pagina Meta dopo l'autorizzazione: una pagina autonoma
    (arriva dal ritorno OAuth, fuori dal layout del pannello) con il tema e i
    colori del pannello, nel tema scelto sul sito come il resto della redazione.
--}}
<!doctype html>
<html lang="it" class="fi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('social.meta_pages.title') }} · {{ config('app.name') }}</title>
    @include('filament.partials.theme-bootstrap')
    @vite('resources/css/filament/admin/theme.css')
</head>
<body class="fi-body min-h-dvh bg-gray-50 text-gray-950 antialiased dark:bg-gray-950 dark:text-white">
    <main class="mx-auto flex w-full max-w-2xl flex-col gap-6 px-4 py-12">
        <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">{{ __('social.meta_pages.heading') }}</h1>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('social.meta_pages.intro') }}</p>
        <form method="post" action="{{ route('social.meta.select') }}" class="flex flex-col gap-3">
            @csrf
            @foreach ($pages as $page)
                <label class="flex cursor-pointer gap-3 rounded-xl border border-gray-200 bg-white p-5 has-checked:border-primary-600 dark:border-white/10 dark:bg-white/5 dark:has-checked:border-primary-400">
                    <input type="radio" name="page_id" value="{{ $page['id'] }}" required class="mt-1">
                    <span class="flex flex-col gap-1">
                        <strong>{{ $page['name'] }}</strong>
                        <span class="text-sm text-gray-600 dark:text-gray-400">{{ $page['instagram_id'] ? __('social.meta_pages.instagram') : __('social.meta_pages.facebook_only') }}</span>
                    </span>
                </label>
            @endforeach
            <div class="flex flex-wrap items-center gap-4 pt-2">
                <x-filament::button type="submit">{{ __('social.meta_pages.submit') }}</x-filament::button>
                <x-filament::link :href="\App\Filament\Admin\Pages\SocialSettings::getUrl()" color="gray">{{ __('social.meta_pages.cancel') }}</x-filament::link>
            </div>
        </form>
    </main>
</body>
</html>
