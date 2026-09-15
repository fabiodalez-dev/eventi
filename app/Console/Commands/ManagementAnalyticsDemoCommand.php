<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\City;
use Carbon\CarbonImmutable;
use Database\Seeders\ManagementAnalyticsDemoSeeder;
use Illuminate\Console\Command;

class ManagementAnalyticsDemoCommand extends Command
{
    protected $signature = 'analytics:demo {city=padova} {--week= : Lunedì della settimana YYYY-MM-DD} {--as-of= : Ultimo giorno delle statistiche YYYY-MM-DD} {--allow-production : Crea esplicitamente i dati sintetici sul remoto}';

    protected $description = 'Crea locale, organizzatore, sei eventi, tre campagne gratuite e statistiche sintetiche senza duplicati';

    public function handle(ManagementAnalyticsDemoSeeder $seeder): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('Il seed remoto richiede --allow-production.');

            return self::FAILURE;
        }
        $city = City::where('slug', $this->argument('city'))->firstOrFail();
        $value = $this->option('week');
        foreach ([$value, $this->option('as-of')] as $date) {
            if ($date && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! CarbonImmutable::hasFormat($date, 'Y-m-d'))) {
                $this->error('Usare una data YYYY-MM-DD valida.');

                return self::FAILURE;
            }
        }
        $week = ($value ? CarbonImmutable::parse($value, $city->timezone) : CarbonImmutable::now($city->timezone))->startOfWeek();
        $asOf = $this->option('as-of') ? CarbonImmutable::parse($this->option('as-of'), $city->timezone) : CarbonImmutable::now($city->timezone);
        $result = $seeder->seedWeek($city, $week, $asOf);
        $this->info('Creati/verificati 6 eventi, 3 campagne a importo zero e 30 giorni di statistiche sintetiche. Settimana: '.$week->toDateString());
        $this->line('Email: '.ManagementAnalyticsDemoSeeder::EMAIL);
        $this->line($result['password'] ? 'Password iniziale: '.$result['password'] : 'Password esistente preservata.');
        $this->line('Locale: '.url('/gestione/'.$result['venue']->slug.'/statistiche'));
        $this->line('Organizzatore: '.url('/organizza/'.$result['organizer']->slug.'/statistiche'));

        return self::SUCCESS;
    }
}
