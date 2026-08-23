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
    /*
     * L'immagine più grande sopra la piega (§11.11: «preload del LCP»). La
     * dichiara la pagina, che è l'unica a sapere quale sia: sulla scheda è la
     * locandina, in una lista è la prima card. Il layout non può indovinarla.
     */
    'preload' => null,
])

@include('layouts.app', [
    'title' => $meta?->title ?? $title,
    'description' => $meta?->description ?? $description,
    'canonical' => $meta?->canonical,
    'image' => $meta?->image,
    'imageWidth' => $meta?->imageWidth,
    'imageHeight' => $meta?->imageHeight,
    'robots' => $meta?->robots(),
    'preload' => $preload,
    'head' => $head ?? '',
    'slot' => $slot,
])
