<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\CommunityProfile;
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
        return auth()->user()?->isEditorialStaff() ?? false;
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
        app(CommunityModeration::class)->apply(auth()->user(), $kind, $id, $enabled);
    }

    public function restoreRestriction(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        app(CommunityModeration::class)->restoreRestriction(auth()->user(), $id);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        if ($this->section === 'restrictions') {
            return ['items' => DB::table('community_restrictions')
                ->join('users', 'users.id', '=', 'community_restrictions.user_id')
                ->select('community_restrictions.*', 'users.name')->orderByDesc('community_restrictions.id')->paginate(25)];
        }
        $query = match ($this->section) {
            'posts' => CommunityPost::query()->with(['user.communityProfile', 'occurrence.event']),
            'comments' => CommunityComment::query()->with('user.communityProfile'),
            default => CommunityProfile::query()->with('user'),
        };
        if ($this->search !== '') {
            $field = in_array($this->section, ['posts', 'comments'], true) ? 'body' : 'display_name';
            $query->where($field, 'like', '%'.addcslashes(mb_substr($this->search, 0, 80), '%_\\').'%');
        }

        return ['items' => $query->orderByDesc('id')->paginate(25)];
    }
}
