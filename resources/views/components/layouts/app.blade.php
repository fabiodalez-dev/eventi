{{--
    Ponte verso resources/views/layouts/app.blade.php, che è il layout vero.

    Le pagine Blade lo usano come `<x-layouts.app>`; i componenti Livewire a
    pagina intera possono puntare direttamente a `layouts.app`, che espone lo
    stesso `$slot`.

    Una pagina può descriversi in due modi: con un `App\DTOs\PageMeta`, che è
    ciò che fanno tutte le pagine con un contenuto vero, oppure con i singoli
    attributi. Il secondo modo serve alla pagina iniziale, che non ha un
    soggetto da dichiarare.
--}}
@props([
    'meta' => null,
    'title' => null,
    'description' => null,
])

@include('layouts.app', [
    'title' => $meta?->title ?? $title,
    'description' => $meta?->description ?? $description,
    'canonical' => $meta?->canonical,
    'image' => $meta?->image,
    'robots' => $meta?->robots(),
    'head' => $head ?? '',
    'slot' => $slot,
])
