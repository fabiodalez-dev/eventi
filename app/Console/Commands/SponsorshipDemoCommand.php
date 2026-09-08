<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\City;
use Database\Seeders\SponsorshipBannerDemoSeeder;
use Illuminate\Console\Command;

class SponsorshipDemoCommand extends Command
{
    protected $signature = 'sponsorships:demo {city : Slug della città} {--allow-production : Autorizza esplicitamente le tre campagne dimostrative sul pubblico}';

    protected $description = 'Crea tre campagne demo a importo zero sugli eventi esistenti, senza duplicati';

    public function handle(SponsorshipBannerDemoSeeder $seeder): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('Serve --allow-production per pubblicare campagne dimostrative sul remoto.');

            return self::FAILURE;
        }

        $city = City::query()->active()->where('slug', $this->argument('city'))->first();
        if ($city === null) {
            $this->error('Città attiva non trovata.');

            return self::FAILURE;
        }

        $ids = $seeder->seedCity($city);
        $this->info('Campagne demo a importo zero: '.implode(', ', $ids));

        return $ids === [] ? self::FAILURE : self::SUCCESS;
    }
}
