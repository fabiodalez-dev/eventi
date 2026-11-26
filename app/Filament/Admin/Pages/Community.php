<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Carpool\CommunitySafety;
use App\Services\Community\CommunityModeration;
use App\Services\Editorial\CommunityDesk;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\WithPagination;

/**
 * La moderazione della community: profili, pubblicazioni, commenti e
 * restrizioni su una serata.
 *
 * **La motivazione sta nella finestra dell'azione.** Era un campo unico in
 * cima alla pagina, da compilare prima di premere un pulsante cinquanta righe
 * più in basso: chi lo dimenticava riceveva l'errore lontano da dove aveva
 * cliccato, e la stessa motivazione restava pronta per l'azione successiva.
 * Ora ogni azione apre la sua finestra, dice che cosa sta per succedere e
 * chiede il motivo lì; il registro della moderazione lo conserva come prima.
 */
class Community extends Page
{
    use WithPagination;

    public const SECTIONS = CommunityDesk::SECTIONS;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.admin.pages.community';

    public string $section = 'profiles';

    public string $search = '';

    public static function getNavigationLabel(): string
    {
        return __('community.moderation_navigation');
    }

    public function getTitle(): string
    {
        return __('community.moderation');
    }

    public function getSubheading(): ?string
    {
        return __('admin.community_page.subheading');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.moderation');
    }

    public static function canAccess(): bool
    {
        return auth()->user() && app(CommunitySafety::class)->staff(auth()->user(), Permission::ManageCommunity);
    }

    public function updatedSection(): void
    {
        if (! in_array($this->section, self::SECTIONS, true)) {
            $this->section = 'profiles';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** Metti o togli un profilo dall'evidenza. */
    public function featureProfileAction(): Action
    {
        return $this->moderationAction('featureProfile')
            ->label(fn (array $arguments): string => __(($arguments['enabled'] ?? false) ? 'community.feature' : 'community.unfeature'))
            ->icon(Heroicon::OutlinedStar)
            ->color('gray')
            ->modalDescription(__('admin.community_page.feature_description'))
            ->action(fn (array $data, array $arguments) => $this->apply('profile', $arguments, $data));
    }

    /** Sospendi l'account dalla community, o riattivalo. */
    public function suspendUserAction(): Action
    {
        return $this->moderationAction('suspendUser')
            ->label(fn (array $arguments): string => __(($arguments['enabled'] ?? false) ? 'community.unsuspend' : 'community.suspend'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color(fn (array $arguments): string => ($arguments['enabled'] ?? false) ? 'gray' : 'warning')
            ->modalDescription(fn (array $arguments): string => ($arguments['enabled'] ?? false)
                ? __('admin.community_page.unsuspend_description')
                : __('community.suspend_confirm'))
            ->action(fn (array $data, array $arguments) => $this->apply('user', $arguments, $data));
    }

    /** Nascondi o ripristina una pubblicazione o un commento. */
    public function hideContentAction(): Action
    {
        return $this->moderationAction('hideContent')
            ->label(fn (array $arguments): string => __(($arguments['enabled'] ?? false) ? 'community.restore' : 'community.hide'))
            ->icon(fn (array $arguments): Heroicon => ($arguments['enabled'] ?? false) ? Heroicon::OutlinedEye : Heroicon::OutlinedEyeSlash)
            ->color(fn (array $arguments): string => ($arguments['enabled'] ?? false) ? 'gray' : 'danger')
            ->modalDescription(fn (array $arguments): string => ($arguments['enabled'] ?? false)
                ? __('admin.community_page.restore_description')
                : __('community.hide_confirm'))
            ->action(fn (array $data, array $arguments) => $this->apply(($arguments['kind'] ?? '') === 'post' ? 'post' : 'comment', $arguments, $data));
    }

    /** Togli una restrizione su una serata. */
    public function restoreRestrictionAction(): Action
    {
        return $this->moderationAction('restoreRestriction')
            ->label(__('community.restore'))
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('gray')
            ->modalDescription(__('community.restore_restriction_confirm'))
            ->action(function (array $data, array $arguments): void {
                abort_unless(static::canAccess(), 403);
                app(CommunityModeration::class)->restoreRestriction($this->staff(), (int) ($arguments['id'] ?? 0), (string) $data['reason']);
                $this->done();
            });
    }

    /** La finestra comune: un motivo obbligatorio, il permesso richiesto di nuovo. */
    private function moderationAction(string $name): Action
    {
        return Action::make($name)
            ->size('sm')
            ->authorize(fn (): bool => static::canAccess())
            ->modalSubmitActionLabel(__('admin.community_page.confirm'))
            ->modalWidth('lg')
            ->schema([
                Textarea::make('reason')
                    ->label(__('carpool.admin.reason'))
                    ->helperText(__('admin.community_page.reason_help'))
                    ->required()
                    ->minLength(5)
                    ->maxLength(1000)
                    ->rows(3),
            ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $data
     */
    private function apply(string $kind, array $arguments, array $data): void
    {
        abort_unless(static::canAccess(), 403);
        app(CommunityModeration::class)->apply($this->staff(), $kind, (int) ($arguments['id'] ?? 0), (bool) ($arguments['enabled'] ?? false), (string) $data['reason']);
        $this->done();
    }

    private function staff(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function done(): void
    {
        Notification::make()->title(__('admin.community_page.done'))->success()->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        // Le query della pagina stanno in `CommunityDesk`: le usa anche l'app della redazione.
        return ['items' => app(CommunityDesk::class)->query($this->section, $this->search)->paginate(25)];
    }
}
