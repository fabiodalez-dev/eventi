<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// L'orizzonte delle ricorrenze si estende una volta al mese: ogni esecuzione
// riporta a dodici mesi le date materializzate (§7.8).
Schedule::command('occurrences:generate')
    ->monthlyOn(1, '03:15')
    ->withoutOverlapping();
