<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\ContactMode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\Rule;

final class ContactFields
{
    public static function make(): Section
    {
        return Section::make(__('contact.settings'))->schema([
            Select::make('contact_mode')->label(__('contact.settings'))->options(ContactMode::options())->default(ContactMode::Disabled->value)->required()->live()->rules([Rule::enum(ContactMode::class)])
                ->helperText(config('contact.recaptcha_site_key') && config('contact.recaptcha_secret_key') ? null : __('contact.captcha_missing')),
            TextInput::make('contact_email')->label(__('contact.recipient'))->helperText(__('contact.private'))->email()->maxLength(255)->required(fn (Get $get): bool => $get('contact_mode') !== ContactMode::Disabled->value),
        ])->columns(2);
    }
}
