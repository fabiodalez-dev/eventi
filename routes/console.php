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

/*
 * L'import dei calendari (§14.2), **ogni ora**. Il comando non esegue: accoda
 * un lavoro per sorgente, così che un calendario irraggiungibile consumi i
 * propri tentativi senza ritardare gli altri.
 *
 * `withoutOverlapping()` vale per l'accodamento; la sovrapposizione che conta
 * davvero — due esecuzioni della stessa sorgente — la impedisce
 * `ShouldBeUnique` sul lavoro.
 */
Schedule::command('import:run')
    ->hourly()
    ->withoutOverlapping();

/*
 * Il motore delle notifiche (§15.5). Il worker gira **ogni cinque minuti**:
 * è la cadenza che lo scenario J di §18 rende verificabile — quaranta avvisi
 * di annullamento devono partire entro cinque minuti dal momento in cui la
 * serata viene annullata.
 *
 * `withoutOverlapping()` non sostituisce il blocco di riga: le righe si
 * prendono comunque con `FOR UPDATE SKIP LOCKED`, perché un lock di scheduler
 * scade e un'esecuzione lanciata a mano non lo rispetta affatto.
 */
Schedule::command('notifications:send')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// I riepiloghi si programmano in anticipo, non si scoprono al momento
// dell'invio: la stessa esecuzione purga l'archivio oltre i dodici mesi (§15.9).
Schedule::command('notifications:plan')
    ->hourly()
    ->withoutOverlapping();
