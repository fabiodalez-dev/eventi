<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\City;
use App\Support\Features;
use Illuminate\Console\Command;
use Laravel\Pennant\Feature;

/**
 * Il comando che rende gli interruttori di `App\Support\Features` qualcosa che
 * si può davvero girare.
 *
 * Senza, spegnere l'import di una città richiederebbe una sessione di `tinker`
 * in produzione — cioè un'espressione PHP scritta a mano su una macchina viva,
 * che è il modo in cui si spegne la città sbagliata.
 */
final class SetFeatureCommand extends Command
{
    public function __construct()
    {
        $this->signature = 'feature:set'
            .' {feature : '.__('operations.feature_set.argument_feature').'}'
            .' {--city= : '.__('operations.feature_set.option_city').'}'
            .' {--off : '.__('operations.feature_set.option_off').'}';

        $this->description = __('operations.feature_set.description');

        parent::__construct();
    }

    public function handle(): int
    {
        $feature = (string) $this->argument('feature');

        if (! in_array($feature, Features::names(), true)) {
            $this->error(__('operations.feature_set.unknown', [
                'feature' => $feature,
                'available' => implode(', ', Features::names()),
            ]));

            return self::FAILURE;
        }

        $slug = $this->option('city');
        $slug = is_string($slug) && $slug !== '' ? $slug : null;
        $needsCity = $feature === Features::CITY_IMPORT;

        if ($needsCity && $slug === null) {
            $this->error(__('operations.feature_set.needs_city', ['feature' => $feature]));

            return self::FAILURE;
        }

        /*
         * Un `--city` su un interruttore che non ne ha una non viene ignorato
         * in silenzio: chi lo ha scritto credeva di agire su una città sola, e
         * se ne va convinto di averlo fatto.
         */
        if (! $needsCity && $slug !== null) {
            $this->error(__('operations.feature_set.unwanted_city', ['feature' => $feature]));

            return self::FAILURE;
        }

        $scope = Features::globalScope();
        $label = __('operations.feature_set.global_scope');

        if ($needsCity) {
            $city = City::query()->where('slug', $slug)->first();

            if (! $city instanceof City) {
                $this->error(__('operations.feature_set.city_not_found', ['slug' => $slug]));

                return self::FAILURE;
            }

            $scope = $city;
            $label = (string) $city->name;
        }

        $off = (bool) $this->option('off');

        if ($off) {
            Feature::for($scope)->deactivate($feature);
        } else {
            Feature::for($scope)->activate($feature);
        }

        $this->info(__($off ? 'operations.feature_set.deactivated' : 'operations.feature_set.activated', [
            'feature' => $feature,
            'scope' => $label,
        ]));

        return self::SUCCESS;
    }
}
