<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Event;
use App\Models\Tag;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateSharedEventTag
{
    public function execute(User $user, string $name, ?Event $event = null, ?Venue $venue = null): Tag
    {
        $gate = Gate::forUser($user);
        $event !== null ? $gate->authorize('update', $event) : $gate->authorize('create', [Event::class, $venue]);
        $name = Str::squish(strip_tags($name));
        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:80']])->validate();
        $slug = Str::slug($name);
        if ($slug === '') {
            throw ValidationException::withMessages(['name' => __('tags.invalid_name')]);
        }

        return Cache::lock('shared-event-tag:'.hash('sha256', $slug), 10)->block(5, function () use ($slug, $name): Tag {
            $tag = Tag::query()->firstOrCreate(['slug' => $slug], ['name' => $name, 'is_approved' => true]);
            // A previously hidden tag must not be republished by a venue.
            if (! $tag->is_approved) {
                throw ValidationException::withMessages(['name' => __('tags.pending')]);
            }

            return $tag;
        });
    }
}
