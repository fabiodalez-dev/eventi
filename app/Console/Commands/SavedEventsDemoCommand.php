<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\City;
use App\Models\User;
use App\Queries\EventOccurrenceQuery;
use App\Support\ContentVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SavedEventsDemoCommand extends Command
{
    protected $signature = 'events:saved-demo {city=padova} {--allow-production} {--dry-run}';

    protected $description = 'Aggiunge salvataggi dimostrativi alle date pubbliche dei prossimi cinque giorni';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('allow-production')) {
            $this->error('Usare --allow-production per il seeding autorizzato.');

            return self::FAILURE;
        }
        $city = City::query()->active()->where('slug', $this->argument('city'))->firstOrFail();
        $dates = EventOccurrenceQuery::for($city)->nextDays(5)->upcoming()->get();
        if ($this->option('dry-run')) {
            $this->info('Date selezionate: '.$dates->count());

            return self::SUCCESS;
        }
        DB::transaction(function () use ($dates): void {
            $users = [];
            for ($i = 1; $i <= 35; $i++) {
                $users[] = User::firstOrCreate(
                    ['email' => 'saved-demo-'.$i.'@demo.incitta.invalid'],
                    ['name' => 'Salvataggi demo '.$i, 'password' => Str::random(64)],
                )->getKey();
            }
            // Demo rows only: no reminder scheduling or messages to real accounts.
            foreach ($dates as $date) {
                $rows = [];
                foreach (array_slice($users, 0, 8 + $date->id % 28) as $id) {
                    $rows[] = ['user_id' => $id, 'occurrence_id' => $date->id, 'created_at' => now(), 'updated_at' => now()];
                }
                DB::table('saved_events')->insertOrIgnore($rows);
            }
        });
        ContentVersion::bump($city);
        $this->info('Salvataggi demo preparati per '.$dates->count().' date (8–35 account per data).');

        return self::SUCCESS;
    }
}
