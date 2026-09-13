<?php

declare(strict_types=1);

namespace App\Enums;

enum ContactMode: string
{
    case Disabled = 'disabled';
    case Members = 'members';
    case Everyone = 'everyone';

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $mode): array => [$mode->value => __('contact.modes.'.$mode->value)])->all();
    }
}
