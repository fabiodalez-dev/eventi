<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialPublicationStatus;
use Illuminate\Database\Eloquent\Model;

class SocialPublication extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => SocialPublicationStatus::class, 'remote_ids' => 'array'];
    }
}
