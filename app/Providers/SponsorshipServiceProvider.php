<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Sponsorship\Delivery\Draw;
use App\Services\Sponsorship\Delivery\ImpressionToken;
use App\Services\Sponsorship\Delivery\RandomDraw;
use App\Services\Sponsorship\Delivery\TrafficHistory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Le dipendenze della consegna delle sponsorizzazioni, in un punto solo.
 *
 * Qui si decide quale caso usa la consegna (in produzione quello vero, nei
 * test si sostituisce con `SeededDraw`) e con quale chiave si firmano i
 * gettoni. I pacchetti successivi aggiungono qui i loro punti di estensione
 * (peso degli eventi, moltiplicatore per chi guarda), e il secondo rilascio li
 * sostituisce senza toccare chi li usa.
 */
final class SponsorshipServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Draw::class, RandomDraw::class);

        $this->app->singleton(ImpressionToken::class, fn (Application $app): ImpressionToken => new ImpressionToken(
            $app->make('config')->string('app.key'),
            $app->make('config')->integer('sponsorship.token_ttl_hours', 6),
        ));

        $this->app->singleton(TrafficHistory::class);
    }
}
