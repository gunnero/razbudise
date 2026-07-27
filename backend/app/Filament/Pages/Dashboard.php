<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Models\User;
use App\Services\Newsroom\NewsroomWorkspaceService;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.dashboard';

    public function getTitle(): string|Htmlable
    {
        return auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor')
            ? 'Мој преглед'
            : 'Редакциски преглед';
    }

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor')
            ? 'Мој преглед'
            : 'Редакциски преглед';
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor')
            ? 'Мои објави'
            : 'Редакција';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $workspace = app(NewsroomWorkspaceService::class);

        return [
            'workspaceRole' => $workspace->role($user),
            'metrics' => $workspace->metrics($user),
            'metricUrls' => self::metricUrlsFor($user),
            'requestedChanges' => $workspace->requestedChanges($user),
            'urgentItems' => $workspace->urgentItems($user, 8),
            'approvalQueue' => $workspace->approvalQueue($user, 5),
            'managedAuthors' => $workspace->managedAuthors($user)->take(6),
            'editorWorkload' => method_exists($workspace, 'editorWorkload') ? $workspace->editorWorkload($user) : collect(),
            'recentActivity' => $workspace->activity($user, 8),
            'today' => $workspace->today($user),
        ];
    }

    /**
     * Keep dashboard actions aligned with the canonical Filament table filters
     * so the count and its destination always describe the same queue.
     *
     * @return array<string, string>
     */
    public static function metricUrlsFor(User $user): array
    {
        if (app(NewsroomWorkspaceService::class)->role($user) === 'author') {
            return collect([
                'drafts' => 'draft',
                'submitted' => 'submitted',
                'changes_requested' => 'changes_requested',
                'approved' => 'approved',
                'scheduled' => 'scheduled',
                'published' => 'published',
            ])->mapWithKeys(fn (string $status, string $key): array => [
                $key => ArticleResource::getUrl('index', [
                    'filters' => [
                        'status' => ['value' => $status],
                    ],
                ]),
            ])->all();
        }

        return [
            'needs_approval' => NewsroomApprovalQueue::getUrl(),
            'changes_requested' => self::articleQueueUrl('changes_requested'),
            'in_review' => self::articleQueueUrl('in_review'),
            'scheduled_today' => self::articleQueueUrl('scheduled_today'),
            'published_today' => self::articleQueueUrl('published_today'),
            'failed_publications' => self::articleQueueUrl('failed'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createArticle')
                ->label('Нова објава')
                ->icon(Heroicon::OutlinedPlus)
                ->url(ArticleResource::getUrl('create'))
                ->authorize(fn (): bool => $this->canCreateArticle())
                ->visible(fn (): bool => $this->canCreateArticle()),
        ];
    }

    private function canCreateArticle(): bool
    {
        $user = auth()->user();

        if (! $user?->hasPermission('articles.create')) {
            return false;
        }

        return ! $user->hasRole('author') || $user->teamMember?->author_id !== null;
    }

    private static function articleQueueUrl(string $queue): string
    {
        return ArticleResource::getUrl('index', [
            'filters' => [
                'editorial_queue' => ['value' => $queue],
            ],
        ]);
    }
}
