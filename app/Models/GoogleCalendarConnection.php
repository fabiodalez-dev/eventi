<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GoogleCalendarError;
use Illuminate\Database\Eloquent\Model;

final class GoogleCalendarConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token', 'google_subject', 'calendar_id'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'selection' => 'array', 'error_code' => GoogleCalendarError::class,
            'enabled' => 'boolean', 'expires_at' => 'immutable_datetime', 'synced_at' => 'immutable_datetime'];
    }
}
