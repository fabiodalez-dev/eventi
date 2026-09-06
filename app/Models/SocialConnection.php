<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'facebook_enabled' => 'boolean', 'instagram_enabled' => 'boolean', 'automatic' => 'boolean', 'verified_at' => 'datetime', 'graphic_options' => 'array'];
    }
}
