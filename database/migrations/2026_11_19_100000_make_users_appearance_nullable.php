<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Il tema del profilo diventa facoltativo: vuoto vuol dire «segui il
 * sistema» (o, per chi non ha mai scelto, la scelta fatta nel browser prima
 * di accedere).
 *
 * Prima la colonna nasceva `dark` per tutti, e un account nuovo passava al
 * tema scuro nell'istante dell'accesso anche se fino a un momento prima il
 * sito era chiaro. Nessuno può distinguere un `dark` scelto da un `dark`
 * ricevuto per difetto: i `dark` diventano vuoti, e chi lo voleva davvero lo
 * risceglie con un tocco (al primo accesso, se in quel browser il tema scuro
 * era stato scelto, il profilo lo riprende da solo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('appearance', 10)->nullable()->default(null)->change();
        });

        DB::table('users')->where('appearance', 'dark')->update(['appearance' => null]);
    }

    public function down(): void
    {
        DB::table('users')->whereNull('appearance')->update(['appearance' => 'dark']);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('appearance', 10)->nullable(false)->default('dark')->change();
        });
    }
};
