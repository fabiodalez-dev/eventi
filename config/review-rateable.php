<?php

declare(strict_types=1);
use App\Models\User;

return [
    'user_model' => User::class,
    'min_rating_value' => 1,
    'max_rating_value' => 5,
    'approved_review' => false,
    'departments' => ['default' => ['ratings' => ['overall' => 'Valutazione']]],
    'images' => ['max_count' => 0, 'allowed_mime_types' => [], 'delete_files_on_delete' => false],
];
