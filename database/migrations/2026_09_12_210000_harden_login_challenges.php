<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('used_web_login_links', function (Blueprint $table): void {
            $table->char('hash', 64)->primary();
            $table->timestamp('expires_at')->index();
        });
        Schema::table('mobile_auth_challenges', function (Blueprint $table): void {
            $table->char('code_challenge', 43)->nullable();
            $table->char('password_fingerprint', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('used_web_login_links');
        Schema::table('mobile_auth_challenges', fn (Blueprint $table) => $table->dropColumn(['code_challenge', 'password_fingerprint']));
    }
};
