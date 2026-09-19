<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\Permission;
use App\Enums\RideStatus;
use App\Models\CarpoolAudit;
use App\Models\CarpoolCase;
use App\Models\RideFeedback;
use App\Models\RideOffer;
use App\Models\RideReview;
use App\Models\User;
use App\Services\Carpool\CommunitySafety;
use App\Services\Carpool\RideReviews;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

class Carpool extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected string $view = 'filament.admin.pages.carpool';

    public string $section = 'cases';

    public string $search = '';

    public string $reason = '';

    public string $password = '';

    public ?int $selectedCase = null;

    public string $status = '';

    public ?int $recipient = null;

    public static function getNavigationLabel(): string
    {
        return __('carpool.admin.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
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
        return static::canAccess() ? (string) CarpoolCase::whereNull('closed_at')->count() : null;
    }

    public function updatedSection(): void
    {
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

    private function check(Permission $permission = Permission::ManageCommunityCases): void
    {
        app(CommunitySafety::class)->requireStaff(auth()->user(), $permission);
    }

    private function validateReason(bool $sensitive = false): void
    {
        $rules = ['reason' => ['required', 'string', 'min:5', 'max:1000']];
        if ($sensitive) {
            $rules['password'] = ['required', 'current_password:web'];
        }
        $this->validate($rules);
    }

    public function manageCase(int $id, int $revision, string $action): void
    {
        $this->check();
        $this->validateReason(in_array($action, ['hold', 'release'], true));
        try {
            app(CommunitySafety::class)->manage(auth()->user(), CarpoolCase::findOrFail($id), $action, $this->reason, ['revision' => $revision, 'recipient_id' => $this->recipient]);
            $this->reason = '';
            $this->recipient = null;
        } finally {
            $this->password = '';
        }
    }

    public function restrict(int $id, string $scope, bool $suspended): void
    {
        $this->check();
        $this->validateReason();
        app(CommunitySafety::class)->restrict(auth()->user(), User::findOrFail($id), $scope, $suspended, $this->reason);
        $this->reason = '';
    }

    public function cancelRide(int $id): void
    {
        $this->check(Permission::ManageCarpool);
        $this->validateReason();
        app(CommunitySafety::class)->cancelRide(auth()->user(), RideOffer::findOrFail($id), $this->reason);
        $this->reason = '';
    }

    public function freezeChat(int $id, bool $freeze): void
    {
        $this->check();
        $this->validateReason();
        app(CommunitySafety::class)->freezeChat(auth()->user(), CarpoolCase::findOrFail($id), $freeze, $this->reason);
        $this->reason = '';
    }

    public function moderateReview(int $id, bool $visible): void
    {
        $this->check(Permission::ManageCarpool);
        $this->validateReason();
        app(RideReviews::class)->moderate(auth()->user(), RideReview::findOrFail($id), $visible, $this->reason);
        $this->reason = '';
    }

    public function retryDelivery(int $id): void
    {
        $this->check(Permission::ManageCarpool);
        $this->validateReason();
        app(CommunitySafety::class)->retryDelivery(auth()->user(), $id, $this->reason);
        $this->reason = '';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $this->check();
        $needle = '%'.addcslashes(mb_substr($this->search, 0, 80), '%_\\').'%';
        $case = $this->selectedCase ? CarpoolCase::with(['reporter', 'assignee', 'offer.driver', 'rideRequest.user', 'messages.author', 'messages.recipient'])->findOrFail($this->selectedCase) : null;
        $query = match ($this->section) {
            'reviews' => RideReview::with(['user', 'driver', 'rideRequest'])->when($this->search !== '', fn ($q) => $q->where('body', 'like', $needle)),
            'feedback' => RideFeedback::with(['user', 'rideRequest.offer'])->where('kind', 'like', $needle),
            'rides' => RideOffer::with(['driver.communityProfile', 'occurrence.event'])->withCount('requests')->where(fn ($q) => $q->where('zone', 'like', $needle)->orWhereHas('driver', fn ($q) => $q->where('name', 'like', $needle))),
            'users' => User::with('communityProfile')->where(fn ($q) => $q->where('name', 'like', $needle)->orWhere('email', 'like', $needle)),
            'audit' => CarpoolAudit::with('actor')->where('action', 'like', $needle),
            'deliveries' => DB::table('community_delivery_outbox')->select(['id', 'user_id', 'notification_id', 'attempts', 'available_at', 'delivered_at', 'last_error'])->whereNull('delivered_at')->where('attempts', '>=', 1),
            default => CarpoolCase::with(['reporter', 'assignee'])->when($this->status !== '', fn ($q) => $q->where('status', $this->status)),
        };
        if (in_array($this->section, ['rides', 'users', 'deliveries', 'reviews', 'feedback'], true)) {
            $this->check(Permission::ManageCarpool);
        }
        if ($this->section === 'rides' && in_array($this->status, array_column(RideStatus::cases(), 'value'), true)) {
            $query->where('status', $this->status);
        }

        return ['items' => $query->orderByDesc('id')->paginate(25), 'case' => $case,
            'canSensitive' => app(CommunitySafety::class)->staff(auth()->user(), Permission::ReadCommunityMessages, true),
            'canRetention' => app(CommunitySafety::class)->staff(auth()->user(), Permission::ManageCommunityRetention, true)];
    }
}
