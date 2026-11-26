<?php

declare(strict_types=1);

namespace App\Support\Venues;

use App\Models\VenueApplication;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'immagine allegata a una richiesta di iscrizione, dal disco privato: la
 * pagina «Le mie richieste» e la redazione (pannello e app) la servono da
 * qui, con le stesse difese. Solo file del percorso delle richieste, solo
 * JPEG, PNG o WebP, mai in cache e mai interpretati come altro; 404 per
 * tutto il resto. Chi può vederla lo decide chi chiama.
 */
final class VenueApplicationImage
{
    public static function response(VenueApplication $application): Response
    {
        $path = $application->image_path;
        abort_unless(is_string($path) && $path !== '' && str_starts_with($path, 'venue-applications/') && Storage::disk('local')->exists($path), 404);

        $mime = (string) Storage::disk('local')->mimeType($path);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true), 404);

        return Storage::disk('local')->response($path, 'richiesta-'.$application->getKey().'.'.match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        }, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ], 'inline');
    }
}
