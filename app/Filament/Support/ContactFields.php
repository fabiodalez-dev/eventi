<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Enums\ContactMode;
use App\Models\Organizer;
use App\Models\Venue;
use App\Services\Contact\ContactEmailConfirmation;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

final class ContactFields
{
    /**
     * @param  (Closure(): (Venue|Organizer))|null  $target  la scheda, per le pagine che non legano il modulo a un record
     *                                                       (il profilo dell'organizzatore); altrimenti si usa il record del modulo
     */
    public static function make(?Closure $target = null): Section
    {
        $scheda = static fn (?Model $record): Venue|Organizer|null => ($found = $target !== null ? $target() : $record) instanceof Venue || $found instanceof Organizer ? $found : null;

        return Section::make(__('contact.settings'))->schema([
            Select::make('contact_mode')->label(__('contact.settings'))->options(ContactMode::options())->default(ContactMode::Disabled->value)->afterStateHydrated(fn ($component, $state) => $component->state($state ?? ContactMode::Disabled->value))->required()->live()->rules([Rule::enum(ContactMode::class)])
                ->helperText(config('contact.recaptcha_site_key') && config('contact.recaptcha_secret_key') ? null : __('contact.captcha_missing')),
            TextInput::make('contact_email')->label(__('contact.recipient'))->helperText(__('contact.private'))->email()->maxLength(255)->required(fn (Get $get): bool => in_array($get('contact_mode'), [ContactMode::Members->value, ContactMode::Everyone->value], true)),
            /* Lo stato del recapito salvato (doppio opt-in): finché non è
               confermato il modulo pubblico non compare. */
            Text::make(static function (?Model $record) use ($scheda): string {
                $model = $scheda($record);
                if ($model === null || blank($model->contact_email)) {
                    return '';
                }

                return $model->hasConfirmedContactEmail()
                    ? __('contact.confirmation.confirmed', ['email' => $model->contact_email])
                    : __('contact.confirmation.pending', ['email' => $model->contact_email, 'days' => ContactEmailConfirmation::LINK_DAYS]);
            })->visible(static fn (?Model $record): bool => filled($scheda($record)?->contact_email))->columnSpanFull(),
            Actions::make([
                Action::make('resendContactConfirmation')->label(__('contact.confirmation.resend'))->color('gray')->size('sm')
                    ->visible(static fn (?Model $record): bool => ($model = $scheda($record)) !== null && filled($model->contact_email) && ! $model->hasConfirmedContactEmail())
                    ->action(static function (?Model $record) use ($scheda): void {
                        $model = $scheda($record);
                        if ($model === null) {
                            return;
                        }
                        $confirmation = app(ContactEmailConfirmation::class);
                        if (! $confirmation->canSend($model)) {
                            Notification::make()->warning()->title(__('contact.confirmation.limit'))->send();

                            return;
                        }
                        $confirmation->send($model)
                            ? Notification::make()->success()->title(__('contact.confirmation.resent', ['email' => $model->contact_email]))->send()
                            : Notification::make()->danger()->title(__('contact.confirmation.failed'))->send();
                    }),
            ])->columnSpanFull(),
        ])->columns(2);
    }
}
