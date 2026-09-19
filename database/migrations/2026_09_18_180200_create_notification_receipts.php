<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_notification_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->uuid('notification_id')->unique();
        });
        DB::table('notifications')->whereIn('notifiable_type', ['user', User::class])->orderBy('id')->chunk(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('community_notification_receipts')->insertOrIgnore(['user_id' => $row->notifiable_id, 'notification_id' => $row->id]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_notification_receipts');
    }
};
