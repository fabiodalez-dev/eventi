<?php

use App\Models\User;

return [
    'database_connection' => null,
    'broadcasts' => false,
    'encrypt_messages' => true,
    'unarchive_on_new_message' => false,
    'sender_fields_whitelist' => ['id'],
    'participant_models' => [User::class],
    'should_load_routes' => false,
    'pagination' => ['page' => 1, 'perPage' => 30, 'sorting' => 'asc', 'columns' => ['*'], 'pageName' => 'page'],
    'transformers' => ['conversation' => null, 'message' => null, 'participant' => null],
];
