<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Articles\ArticlePublicationBlockerExplainer;
use App\Services\Newsroom\NewsroomWorkspaceService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class NewsroomManagedPosts extends Page
{
    use WithPagination;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Објави што ги управувам';

    protected static ?string $slug = 'newsroom/posts';

    protected string $view = 'filament.pages.newsroom-managed-posts';

    #[Url(as: 'status', except: 'all', history: true)]
    public string $status = 'all';

    #[Url(as: 'author', except: 'all', history: true)]
    public string $author = 'all';

    #[Url(as: 'from', except: '', history: true)]
    public string $authorsContext = '';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor')
            ? 'Мои објави'
            : 'Редакција';
    }

    public static function getNavigationLabel(): string
    {
        return auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor')
            ? 'Мои објави'
            : 'Објави што ги управувам';
    }

    public function getTitle(): string|Htmlable
    {
        return auth()->user()?->hasRole('author') || auth()->user()?->hasRole('contributor')
            ? 'Мои објави'
            : 'Објави што ги управувам';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $workspace = app(NewsroomWorkspaceService::class);
        $status = $this->status === 'all' ? null : $this->status;
        $authorId = $this->author === 'all' ? null : (int) $this->author;
        $postsQuery = $workspace->managedPosts($user, $status, $authorId);
        $posts = $postsQuery instanceof Builder
            ? $postsQuery->latest('updated_at')->latest('id')->paginate(40)
            : collect($postsQuery);
        $authors = $workspace->managedAuthors($user);
        $postCollection = method_exists($posts, 'getCollection') ? $posts->getCollection() : collect($posts);
        $blockerExplainer = app(ArticlePublicationBlockerExplainer::class);
        $postCollection->each(function ($article) use ($blockerExplainer): void {
            $article->setAttribute('publication_blocker_reason', $blockerExplainer->explain($article, auth()->user()));
        });

        if ($authors->isEmpty()) {
            $authors = $postCollection->pluck('author')->filter()->unique('id')->values();
        }

        $isAuthor = $user->hasRole('author') || $user->hasRole('contributor');

        return [
            'posts' => $posts,
            'authors' => $authors,
            'groups' => $postCollection->groupBy(fn ($post): string => $post->author?->name ?? 'Без автор'),
            'authorWorkloads' => $authors->keyBy('id'),
            'authorsReturnUrl' => static::authorDirectoryUrl($this->authorsContext),
            'statusOptions' => $isAuthor
                ? [
                    'all' => 'Сите',
                    'draft' => 'Работни верзии',
                    'submitted' => 'Испратени',
                    'changes_requested' => 'Потребна доработка',
                    'approved' => 'Одобрени',
                    'scheduled' => 'Закажани',
                    'published' => 'Објавени',
                    'published_this_month' => 'Овој месец',
                ]
                : [
                    'all' => 'Сите',
                    'needs_approval' => 'Чекаат одобрување',
                    'in_review' => 'Во преглед',
                    'changes_requested' => 'Потребна доработка',
                    'approved' => 'Одобрени',
                    'scheduled' => 'Закажани',
                    'published' => 'Објавени',
                    'published_this_month' => 'Овој месец',
                    'archived' => 'Архивирани',
                ],
        ];
    }

    /** @param array<string, scalar|null> $authorsContext */
    public static function authorDrilldownUrl(int $authorId, string $metric, array $authorsContext = []): string
    {
        $status = match ($metric) {
            'working_versions' => 'draft',
            'waiting' => 'needs_approval',
            'changes' => 'changes_requested',
            'scheduled' => 'scheduled',
            'this_month' => 'published_this_month',
            default => 'all',
        };

        $query = [
            'author' => $authorId,
            'status' => $status,
        ];

        $context = static::sanitizeAuthorContext($authorsContext);
        if ($context !== []) {
            $query['from'] = http_build_query($context);
        }

        return static::getUrl().'?'.http_build_query($query);
    }

    public static function authorDirectoryUrl(string $context): string
    {
        parse_str($context, $query);
        $query = static::sanitizeAuthorContext(is_array($query) ? $query : []);

        return static::authorDirectoryBaseUrl().($query === [] ? '' : '?'.http_build_query($query));
    }

    protected static function authorDirectoryBaseUrl(): string
    {
        return NewsroomManagedAuthors::getUrl();
    }

    /**
     * Only known author-directory filters may make the round trip. Besides
     * preserving context, this prevents the return breadcrumb becoming an
     * open redirect or carrying arbitrary nested input.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, string>
     */
    private static function sanitizeAuthorContext(array $context): array
    {
        $allowed = ['q', 'status', 'account', 'editor', 'department'];

        return collect($context)
            ->only($allowed)
            ->filter(fn (mixed $value): bool => is_scalar($value) && filled((string) $value))
            ->map(fn (mixed $value): string => mb_substr((string) $value, 0, 160))
            ->all();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedAuthor(): void
    {
        $this->resetPage();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('articles.view')
            || auth()->user()?->hasPermission('articles.publish')
            || false;
    }
}
