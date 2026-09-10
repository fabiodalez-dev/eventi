<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

arch('i modelli concreti usano Eloquent')
    ->expect('App\Models')
    ->classes()
    ->toExtend(Model::class);

arch('il nucleo non dipende dai punti di ingresso web o amministrativi')
    ->expect(['App\DTOs', 'App\Enums', 'App\Models', 'App\Queries'])
    ->not->toUse(['App\Http\Controllers', 'App\Filament', 'App\Console']);
