<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Enums\Permission;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
use App\Services\Carpool\CommunitySafety;
use App\Services\Community\CommunityModeration;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

class Community extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected string $view = 'filament.admin.pages.community';

    public string $section = 'profiles';

    public string $search = '';

    public string $reason = '';

    public static function getNavigationLabel(): string
    {
        return __('community.moderation');
    }

    public function getTitle(): string
    {
        return __('community.moderation');
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
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function moderate(string $kind, int $id, bool $enabled): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        app(CommunityModeration::class)->apply(auth()->user(), $kind, $id, $enabled, $this->reason);
        $this->reason = '';
    }

    public function restoreRestriction(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        app(CommunityModeration::class)->restoreRestriction(auth()->user(), $id, $this->reason);
        $this->reason = '';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $search = $this->search !== '' ? '%'.addcslashes(mb_substr($this->search, 0, 80), '%_\\').'%' : null;
        if ($this->section === 'restrictions') {
            // Titolo e data dicono allo staff quale serata sta sbloccando; l'id resta come riferimento.
            return ['items' => DB::table('community_restrictions')
                ->join('users', 'users.id', '=', 'community_restrictions.user_id')
                ->join('event_occurrences', 'event_occurrences.id', '=', 'community_restrictions.occurrence_id')
                ->join('events', 'events.id', '=', 'event_occurrences.event_id')
                ->join('cities', 'cities.id', '=', 'events.city_id')
                ->when($search !== null, fn ($query) => $query->where('users.name', 'like', $search))
                ->select('community_restrictions.*', 'users.name', 'events.title as event_title', 'event_occurrences.starts_at', 'cities.timezone')
                ->orderByDesc('community_restrictions.id')->paginate(25)];
        }
        $query = match ($this->section) {
            'posts' => CommunityPost::query()->with(['user.communityProfile', 'occurrence.event']),
            'comments' => CommunityComment::query()->with('user.communityProfile'),
            default => CommunityProfile::query()->with('user'),
        };
        if ($search !== null) {
            $field = in_array($this->section, ['posts', 'comments'], true) ? 'body' : 'display_name';
            $query->where($field, 'like', $search);
        }

        return ['items' => $query->orderByDesc('id')->paginate(25)];
    }
}
