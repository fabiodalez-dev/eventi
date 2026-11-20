<?php

declare(strict_types=1);

namespace App\Filament\Venue\Pages;

use App\Actions\LeaveVenueAction;
use App\Enums\VenueRole;
use App\Filament\Venue\Support\CurrentVenue;
use App\Models\User;
use App\Support\RequestMemo;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * «Il tuo accesso a questo locale» (D64): da qui chiunque gestisca il locale
 * — collaboratore o referente — può uscirne da solo.
 *
 * Non sta nel menu laterale, che è fatto per il lavoro di tutti i giorni: si
 * apre dal menu dell'account, accanto al profilo. Non sta nemmeno nella
 * pagina dei collaboratori, che un collaboratore non può aprire. L'unico
 * limite è l'ultimo referente, che lo chiede alla redazione
 * (`LeaveVenueAction`).
 */
class VenueAccess extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'il-tuo-accesso';

    protected string $view = 'filament.venue.pages.venue-access';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isActiveMemberOfVenue((int) CurrentVenue::get()->getKey());
    }

    public function getTitle(): string
    {
        return __('manage.access.title');
    }

    public function getSubheading(): ?string
    {
        return __('manage.access.lead', ['venue' => CurrentVenue::get()->name, 'role' => $this->roleLabel()]);
    }

    public function isLastOwner(): bool
    {
        $user = auth()->user();

        $venue = CurrentVenue::get();

        /* La pagina lo chiede in più punti: una risposta per richiesta. */
        return $user instanceof User && RequestMemo::remember('venue-access:last-owner:'.$venue->getKey().':'.$user->id, fn (): bool => app(LeaveVenueAction::class)->isLastOwner($venue, $user));
    }

    public function isOwner(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isOwnerOfVenue((int) CurrentVenue::get()->getKey());
    }

    public function roleLabel(): string
    {
        return mb_strtolower(($this->isOwner() ? VenueRole::Owner : VenueRole::Editor)->label());
    }

    /** Da quando la persona gestisce il locale, se l'invito è stato accettato. */
    public function memberSince(): ?string
    {
        $accepted = CurrentVenue::get()->members()->whereKey(auth()->id())->first()?->pivot?->getAttribute('accepted_at');

        return $accepted !== null ? CarbonImmutable::parse($accepted)->timezone(CurrentVenue::timezone())->format('d/m/Y') : null;
    }

    /**
     * L'uscita sta nella card «Zona pericolosa», non fra le azioni della
     * testata: è l'unica cosa irreversibile della pagina e chiede conferma.
     */
    public function leaveAction(): Action
    {
        return Action::make('leave')
            ->label(__('manage.access.leave'))
            ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => __('manage.access.leave_confirm', ['venue' => CurrentVenue::get()->name]))
            ->disabled(fn (): bool => $this->isLastOwner())
            ->action(function (): void {
                $user = auth()->user();
                abort_unless($user instanceof User && static::canAccess(), 403);
                $venue = CurrentVenue::get();

                if (! app(LeaveVenueAction::class)->execute($venue, $user)) {
                    Notification::make()->warning()->title(__('manage.access.last_owner'))->send();

                    return;
                }

                /* Chi ha ancora un altro locale torna al pannello, che
                   apre quello; gli altri al proprio profilo, perché qui
                   non hanno più niente da aprire. */
                if ($user->venueMemberships()->isNotEmpty()) {
                    Notification::make()->success()->title(__('manage.access.left', ['venue' => $venue->name]))->send();
                    $this->redirect(Filament::getPanel('venue')->getUrl() ?? url('/'));

                    return;
                }

                session()->flash('status', __('manage.access.left', ['venue' => $venue->name]));
                $this->redirect(route('account.profile'));
            });
    }
}
