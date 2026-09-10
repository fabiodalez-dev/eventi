<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'app_secret', 'telegram_bot_token'];

    protected function casts(): array
    {
        return ['telegram_bot_token' => 'encrypted', 'telegram_enabled' => 'boolean', 'telegram_verified_at' => 'datetime', 'app_secret' => 'encrypted', 'access_token' => 'encrypted', 'facebook_enabled' => 'boolean', 'instagram_enabled' => 'boolean', 'automatic' => 'boolean', 'verified_at' => 'datetime', 'graphic_options' => 'array'];
    }
}
