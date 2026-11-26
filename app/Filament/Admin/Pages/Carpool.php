<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\Permission;
use App\Models\CarpoolCase;
use App\Models\RideOffer;
use App\Models\RideReview;
use App\Models\User;
use App\Services\Carpool\CommunitySafety;
use App\Services\Carpool\RideReviews;
use App\Services\Editorial\CarpoolDesk;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Sicurezza dei passaggi in auto: segnalazioni, passaggi, recensioni,
 * feedback privati, utenti, registro e consegne.
 *
 * **Una motivazione per azione, nella sua finestra.** Prima c'era un solo
 * campo «Motivazione» in cima alla pagina, valido per «la prossima azione»:
 * si scriveva, si scendeva fino al pulsante, e se lo si dimenticava l'errore
 * compariva lontano. Ora ogni pulsante apre la sua finestra con il motivo, e
 * — dove serve — il destinatario della risposta o la password per le azioni
 * sulla conservazione delle evidenze. I permessi li ricontrolla il servizio
 * a ogni chiamata, come prima.
 */
class Carpool extends Page
{
    use WithPagination;

    public const SECTIONS = CarpoolDesk::SECTIONS;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.admin.pages.carpool';

    public string $section = 'cases';

    public string $search = '';

    public ?int $selectedCase = null;

    public string $status = '';

    public static function getNavigationLabel(): string
    {
        return __('carpool.admin.navigation');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return __('admin.carpool_page.subheading');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.moderation');
    }

    public static function canAccess(): bool
    {
        return auth()->user() && app(CommunitySafety::class)->staff(auth()->user(), Permission::ManageCommunityCases);
    }

    public static function getNavigationBadge(): ?string
    {
        return static::canAccess() ? (string) Cache::remember('nav-badge:carpool-open', 60, fn (): int => CarpoolCase::whereNull('closed_at')->count()) : null;
    }

    public function updatedSection(): void
    {
        if (! in_array($this->section, self::SECTIONS, true)) {
            $this->section = 'cases';
        }
        $this->resetPage();
        $this->selectedCase = null;
        $this->status = '';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /** Assegna, nota interna, risolvi, riapri. */
    public function caseAction(): Action
    {
        return $this->reasonAction('case', function (array $data, array $arguments): void {
            $this->manage($arguments, (string) $data['reason']);
        })
            ->label(fn (array $arguments): string => __('carpool.admin.'.($arguments['op'] ?? 'note')))
            ->icon(fn (array $arguments): Heroicon => match ($arguments['op'] ?? 'note') {
                'assign' => Heroicon::OutlinedUserPlus,
                'resolve' => Heroicon::OutlinedCheckCircle,
                'reopen' => Heroicon::OutlinedArrowPath,
                default => Heroicon::OutlinedPencilSquare,
            })
            ->color(fn (array $arguments): string => ($arguments['op'] ?? '') === 'resolve' ? 'success' : 'gray')
            ->modalDescription(fn (array $arguments): string => __('admin.carpool_page.case_ops.'.($arguments['op'] ?? 'note')));
    }

    /** La risposta a una persona del caso: il testo è il messaggio che riceve. */
    public function replyCaseAction(): Action
    {
        return Action::make('replyCase')
            ->label(__('carpool.admin.reply'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->size('sm')
            ->authorize(fn (): bool => static::canAccess())
            ->modalDescription(__('admin.carpool_page.reply_description'))
            ->modalSubmitActionLabel(__('carpool.admin.reply'))
            ->schema(fn (array $arguments): array => [
                Select::make('recipient')
                    ->label(__('admin.carpool_page.recipient'))
                    ->options(fn (): array => $this->casePeople((int) ($arguments['id'] ?? 0)))
                    ->required(),
                Textarea::make('reason')
                    ->label(__('admin.carpool_page.message'))
                    ->required()->minLength(5)->maxLength(1000)->rows(4),
            ])
            ->action(function (array $data, array $arguments): void {
                $this->manage($arguments + ['op' => 'reply'], (string) $data['reason'], ['recipient_id' => (int) $data['recipient']]);
            });
    }

    /** Conservazione eccezionale delle evidenze: chiede anche la password. */
    public function holdCaseAction(): Action
    {
        return $this->reasonAction('holdCase', function (array $data, array $arguments): void {
            $this->manage($arguments, (string) $data['reason']);
        }, [
            TextInput::make('password')
                ->label(__('carpool.admin.password'))
                ->password()
                ->autocomplete('current-password')
                ->currentPassword(guard: 'web')
                ->required()
                ->dehydrated(false),
        ])
            ->label(fn (array $arguments): string => __('carpool.admin.'.(($arguments['op'] ?? '') === 'release' ? 'release' : 'hold')))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->modalDescription(__('admin.carpool_page.hold_description'));
    }

    public function freezeChatAction(): Action
    {
        return $this->reasonAction('freezeChat', function (array $data, array $arguments): void {
            $this->check();
            $this->run(fn () => app(CommunitySafety::class)->freezeChat($this->staffUser(), CarpoolCase::findOrFail((int) $arguments['id']), (bool) ($arguments['freeze'] ?? false), (string) $data['reason']));
        })
            ->label(fn (array $arguments): string => __(($arguments['freeze'] ?? false) ? 'carpool.admin.freeze_chat' : 'carpool.admin.unfreeze_chat'))
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color(fn (array $arguments): string => ($arguments['freeze'] ?? false) ? 'warning' : 'gray');
    }

    public function restrictUserAction(): Action
    {
        return $this->reasonAction('restrictUser', function (array $data, array $arguments): void {
            $this->check();
            $this->run(fn () => app(CommunitySafety::class)->restrict($this->staffUser(), User::findOrFail((int) $arguments['id']), (string) $arguments['scope'], (bool) ($arguments['suspend'] ?? false), (string) $data['reason']));
        })
            ->label(fn (array $arguments): string => __('carpool.admin.'.(($arguments['suspend'] ?? false) ? 'suspend_' : 'restore_').($arguments['scope'] ?? 'social')))
            ->color(fn (array $arguments): string => ($arguments['suspend'] ?? false) ? 'warning' : 'gray');
    }

    public function cancelRideAction(): Action
    {
        return $this->reasonAction('cancelRide', function (array $data, array $arguments): void {
            $this->check(Permission::ManageCarpool);
            $this->run(fn () => app(CommunitySafety::class)->cancelRide($this->staffUser(), RideOffer::findOrFail((int) $arguments['id']), (string) $data['reason']));
        })
            ->label(__('carpool.admin.cancel_ride'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->modalDescription(__('carpool.admin.cancel_confirm'));
    }

    public function moderateReviewAction(): Action
    {
        return $this->reasonAction('moderateReview', function (array $data, array $arguments): void {
            $this->check(Permission::ManageCarpool);
            $this->run(fn () => app(RideReviews::class)->moderate($this->staffUser(), RideReview::findOrFail((int) $arguments['id']), (bool) ($arguments['visible'] ?? false), (string) $data['reason']));
        })
            ->label(fn (array $arguments): string => __(($arguments['visible'] ?? false) ? 'carpool.reviews.restore' : 'carpool.reviews.hide'))
            ->color(fn (array $arguments): string => ($arguments['visible'] ?? false) ? 'gray' : 'danger');
    }

    public function retryDeliveryAction(): Action
    {
        return $this->reasonAction('retryDelivery', function (array $data, array $arguments): void {
            $this->check(Permission::ManageCarpool);
            $this->run(fn () => app(CommunitySafety::class)->retryDelivery($this->staffUser(), (int) $arguments['id'], (string) $data['reason']));
        })
            ->label(__('carpool.admin.retry'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray');
    }

    /**
     * La finestra comune: il motivo obbligatorio (più eventuali campi), il
     * permesso richiesto di nuovo.
     *
     * @param  Closure(array<string, mixed>, array<string, mixed>): void  $handler
     * @param  list<TextInput>  $extra
     */
    private function reasonAction(string $name, Closure $handler, array $extra = []): Action
    {
        return Action::make($name)
            ->size('sm')
            ->authorize(fn (): bool => static::canAccess())
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('admin.carpool_page.confirm'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('carpool.admin.reason'))
                    ->helperText(__('admin.carpool_page.reason_help'))
                    ->required()->minLength(5)->maxLength(1000)->rows(3),
                ...$extra,
            ])
            ->action($handler);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $data
     */
    private function manage(array $arguments, string $reason, array $data = []): void
    {
        $this->check();
        $this->run(fn () => app(CommunitySafety::class)->manage(
            $this->staffUser(),
            CarpoolCase::findOrFail((int) ($arguments['id'] ?? 0)),
            (string) ($arguments['op'] ?? 'note'),
            $reason,
            ['revision' => (int) ($arguments['revision'] ?? 0)] + $data,
        ));
    }

    /**
     * Esegue l'operazione e dice com'è andata: un caso cambiato nel frattempo
     * (409) o un dato rifiutato (422) diventano un avviso, non una pagina
     * d'errore che fa perdere il punto in cui si era.
     */
    private function run(Closure $operation): void
    {
        try {
            $operation();
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 403) {
                throw $exception;
            }
            Notification::make()->title(filled($exception->getMessage()) ? $exception->getMessage() : __('admin.carpool_page.failed'))->danger()->send();

            return;
        }

        Notification::make()->title(__('admin.carpool_page.done'))->success()->send();
    }

    /** @return array<int, string> */
    private function casePeople(int $caseId): array
    {
        return app(CarpoolDesk::class)->people(CarpoolCase::with(['reporter', 'offer.driver', 'rideRequest.user'])->find($caseId));
    }

    private function check(Permission $permission = Permission::ManageCommunityCases): void
    {
        app(CommunitySafety::class)->requireStaff($this->staffUser(), $permission);
    }

    private function staffUser(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $this->check();
        // Le query della pagina stanno in `CarpoolDesk`: le usa anche l'app della redazione.
        $desk = app(CarpoolDesk::class);
        $case = $this->selectedCase ? $desk->case($this->selectedCase) : null;
        $query = $desk->query($this->section, $this->search, $this->status);
        if (CarpoolDesk::permission($this->section) === Permission::ManageCarpool) {
            $this->check(Permission::ManageCarpool);
        }

        return ['items' => $query->paginate(25), 'case' => $case,
            'canSensitive' => app(CommunitySafety::class)->staff($this->staffUser(), Permission::ReadCommunityMessages, true),
            'canRetention' => app(CommunitySafety::class)->staff($this->staffUser(), Permission::ManageCommunityRetention, true)];
    }
}
