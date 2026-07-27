<?php

namespace App\Services\Newsroom;

use App\Models\AdminAuditLog;
use App\Models\Article;
use App\Models\ArticleEditorialComment;
use App\Models\Author;
use App\Models\EditorialSupervision;
use App\Models\User;
use App\Services\Articles\ArticlePublicationReadiness;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NewsroomWorkspaceService
{
    public function __construct(private readonly ArticlePublicationReadiness $publicationReadiness) {}

    public function role(User $user): string
    {
        $user->loadMissing('roles');
        $roles = $user->roles->pluck('name');

        return match (true) {
            ! $user->isActive() => 'staff',
            $roles->contains(fn (string $role): bool => in_array($role, ['admin', 'super_admin'], true)) => 'administrator',
            $roles->contains('editor_in_chief') => 'editor_in_chief',
            $roles->contains('editor') => 'editor',
            $roles->contains(fn (string $role): bool => in_array($role, ['author', 'contributor'], true)) => 'author',
            default => 'staff',
        };
    }

    /**
     * @return array<string, array{count: int, previous_count: int|null, delta: int|null}>
     */
    public function metrics(User $user): array
    {
        if ($this->role($user) === 'author') {
            return $this->authorMetrics($user);
        }

        [$todayStart, $todayEnd] = $this->localDayBounds();
        [$yesterdayStart, $yesterdayEnd] = $this->localDayBounds($this->now()->subDay());

        $summary = $this->dashboardArticleQuery($user)
            ->selectRaw("SUM(CASE WHEN status IN ('submitted', 'review', 'in_review') AND approved_at IS NULL THEN 1 ELSE 0 END) AS needs_approval")
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS changes_requested', ['changes_requested'])
            ->selectRaw("SUM(CASE WHEN status IN ('review', 'in_review') THEN 1 ELSE 0 END) AS in_review")
            ->selectRaw('SUM(CASE WHEN status = ? AND scheduled_at >= ? AND scheduled_at < ? THEN 1 ELSE 0 END) AS scheduled_today', ['scheduled', $todayStart, $todayEnd])
            ->selectRaw('SUM(CASE WHEN status = ? AND published_at >= ? AND published_at < ? THEN 1 ELSE 0 END) AS published_today', ['published', $todayStart, $todayEnd])
            ->selectRaw("SUM(CASE WHEN status = 'failed' OR publication_failed_at IS NOT NULL THEN 1 ELSE 0 END) AS failed_publications")
            ->selectRaw('SUM(CASE WHEN status = ? AND scheduled_at >= ? AND scheduled_at < ? THEN 1 ELSE 0 END) AS scheduled_yesterday', ['scheduled', $yesterdayStart, $yesterdayEnd])
            ->selectRaw('SUM(CASE WHEN status = ? AND published_at >= ? AND published_at < ? THEN 1 ELSE 0 END) AS published_yesterday', ['published', $yesterdayStart, $yesterdayEnd])
            ->selectRaw('SUM(CASE WHEN publication_failed_at >= ? AND publication_failed_at < ? THEN 1 ELSE 0 END) AS failed_yesterday', [$yesterdayStart, $yesterdayEnd])
            ->first();

        $pointInTime = fn (string $key): array => [
            'count' => (int) ($summary?->{$key} ?? 0),
            'previous_count' => null,
            'delta' => null,
        ];
        $daily = function (string $key, string $previousKey) use ($summary): array {
            $count = (int) ($summary?->{$key} ?? 0);
            $previous = (int) ($summary?->{$previousKey} ?? 0);

            return ['count' => $count, 'previous_count' => $previous, 'delta' => $count - $previous];
        };

        return [
            'needs_approval' => $pointInTime('needs_approval'),
            'changes_requested' => $pointInTime('changes_requested'),
            'in_review' => $pointInTime('in_review'),
            'scheduled_today' => $daily('scheduled_today', 'scheduled_yesterday'),
            'published_today' => $daily('published_today', 'published_yesterday'),
            'failed_publications' => $pointInTime('failed_publications'),
        ];
    }

    /**
     * Author and contributor dashboards show personal workflow totals rather
     * than global newsroom queues. Historical deltas are intentionally omitted
     * because these are current-state totals, not daily event counts.
     *
     * @return array<string, array{count: int, previous_count: null, delta: null}>
     */
    private function authorMetrics(User $user): array
    {
        $summary = $this->dashboardArticleQuery($user)
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS drafts', ['draft'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS submitted', ['submitted'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS changes_requested', ['changes_requested'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS approved', ['approved'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS scheduled', ['scheduled'])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS published', ['published'])
            ->first();

        return collect(['drafts', 'submitted', 'changes_requested', 'approved', 'scheduled', 'published'])
            ->mapWithKeys(fn (string $key): array => [$key => [
                'count' => (int) ($summary?->{$key} ?? 0),
                'previous_count' => null,
                'delta' => null,
            ]])
            ->all();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function urgentItems(User $user, int $limit = 12): Collection
    {
        $now = $this->now();
        $slaCutoff = $now->copy()->subHours($this->approvalSlaHours())->utc();
        $soon = $now->copy()->addHours((int) config('newsroom.scheduled_soon_hours', 6))->utc();
        $items = collect();
        $authorView = $this->role($user) === 'author';

        $articles = $this->managedPosts($user)
            ->where(function (Builder $query) use ($authorView, $slaCutoff, $now, $soon): void {
                $query
                    ->where(fn (Builder $queue) => $queue
                        ->whereIn('status', config('newsroom.approval_statuses', ['submitted', 'review', 'in_review']))
                        ->whereNotNull('submitted_at')
                        ->where('submitted_at', '<', $slaCutoff))
                    ->orWhereNotNull('publication_failed_at')
                    ->orWhereHas(
                        'unresolvedBlockingEditorialComments',
                        fn (Builder $comments): Builder => $comments->when(
                            $authorView,
                            fn (Builder $visible): Builder => $visible->where('visibility', ArticleEditorialComment::VISIBILITY_AUTHOR),
                        ),
                    )
                    ->orWhere(fn (Builder $scheduled) => $scheduled
                        ->where('status', 'scheduled')
                        ->whereNull('approved_at')
                        ->whereBetween('scheduled_at', [$now->copy()->utc(), $soon]));
            })
            ->orderByDesc('publication_failed_at')
            ->orderBy('submitted_at')
            ->limit($limit * 2)
            ->get();

        foreach ($articles as $article) {
            if ($items->count() >= $limit) {
                break;
            }

            $anchor = $article->publication_failed_at
                ?? $article->submitted_at
                ?? $article->last_editorial_activity_at
                ?? $article->updated_at;

            if ($article->publication_failed_at) {
                $items->push($this->urgentArticleItem($article, 'publication_failed', 'danger', 'Неуспешно закажано објавување.', $anchor, true));
            } elseif ((int) $article->unresolved_blocking_editorial_comments_count > 0) {
                $items->push($this->urgentArticleItem($article, 'blocking_comments', 'danger', 'Има нерешени блокирачки коментари.', $anchor, true));
            } elseif ($article->status === 'scheduled' && ! $article->approved_at) {
                $items->push($this->urgentArticleItem($article, 'scheduled_without_approval', 'danger', 'Наскоро е закажана, но сè уште не е одобрена.', $article->scheduled_at, true));
            } else {
                $items->push($this->urgentArticleItem($article, 'overdue_approval', 'warning', 'Го надмина рокот од '.$this->approvalSlaHours().' часа за одобрување.', $article->submitted_at, false));
            }
        }

        if ($items->count() < $limit) {
            $this->appendMetadataIssues($items, $user, $limit);
        }

        if ($items->count() < $limit && in_array($this->role($user), ['administrator', 'editor_in_chief'], true)) {
            $this->appendPeopleIssues($items, $limit);
        }

        return $items->take($limit)->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function approvalQueue(User $user, int $limit = 24): Collection
    {
        return $this->approvalQueueQuery($user)
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (Article $article): array => $this->approvalQueueItem($article));
    }

    /**
     * Return every article that currently needs work from the signed-in author.
     * This is intentionally independent of blocking-comment severity: a
     * non-blocking request still needs a clear next action on the dashboard.
     *
     * @return Collection<int, array{
     *     article: Article,
     *     requester: User|null,
     *     requested_at: Carbon|null,
     *     deadline_at: Carbon|null,
     *     unresolved_comments: int
     * }>
     */
    public function requestedChanges(User $user): Collection
    {
        if ($this->role($user) !== 'author') {
            return collect();
        }

        return $this->dashboardArticleQuery($user)
            ->where('status', 'changes_requested')
            ->with([
                'latestChangeRequest.requester',
                'latestChangeRequest.comments' => fn ($comments) => $comments
                    ->where('visibility', ArticleEditorialComment::VISIBILITY_AUTHOR)
                    ->where('is_resolved', false),
                'changesRequester',
                'assignedEditor',
                'supervisingEditor',
            ])
            ->withCount([
                'editorialComments as unresolved_author_comments_count' => fn (Builder $comments): Builder => $comments
                    ->whereNull('parent_id')
                    ->where('visibility', ArticleEditorialComment::VISIBILITY_AUTHOR)
                    ->where('is_resolved', false),
            ])
            ->orderByDesc('changes_requested_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Article $article): array {
                $request = $article->latestChangeRequest;

                return [
                    'article' => $article,
                    'requester' => $request?->requester
                        ?? $article->changesRequester
                        ?? $article->assignedEditor
                        ?? $article->supervisingEditor,
                    'requested_at' => $request?->created_at ?? $article->changes_requested_at,
                    'deadline_at' => $request?->deadline_at,
                    'unresolved_comments' => $request
                        ? $request->comments->count()
                        : (int) $article->unresolved_author_comments_count,
                ];
            });
    }

    /**
     * Return the complete approval queue one page at a time. The total is taken
     * from the same role-scoped query as the records, so the UI never reports a
     * capped preview as the full editorial workload.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginatedApprovalQueue(
        User $user,
        int $perPage = 20,
        string $pageName = 'page',
        ?int $page = null,
    ): LengthAwarePaginator {
        $paginator = $this->approvalQueueQuery($user)->paginate(
            perPage: min(100, max(1, $perPage)),
            columns: ['*'],
            pageName: $pageName,
            page: $page,
        );

        return $paginator->through(fn (Article $article): array => $this->approvalQueueItem($article));
    }

    public function managedPosts(User $user, ?string $status = null, ?int $authorId = null): Builder
    {
        $authorView = $this->role($user) === 'author';
        $query = $this->dashboardArticleQuery($user)
            ->with([
                'author.avatar',
                'category',
                'featuredImage',
                'seoMetadata',
                'assignedEditor',
                'supervisingEditor',
            ])
            ->withCount([
                'editorialComments as unresolved_editorial_comments_count' => fn (Builder $query) => $query
                    ->where('is_resolved', false)
                    ->when(
                        $authorView,
                        fn (Builder $visible): Builder => $visible->where('visibility', ArticleEditorialComment::VISIBILITY_AUTHOR),
                    ),
                'unresolvedBlockingEditorialComments' => fn (Builder $query) => $query->when(
                    $authorView,
                    fn (Builder $visible): Builder => $visible->where('visibility', ArticleEditorialComment::VISIBILITY_AUTHOR),
                ),
                'revisions',
                'views',
            ]);

        if ($status !== null) {
            if ($status === 'published_this_month') {
                [$monthStart, $monthEnd] = $this->localMonthBounds();
                $query
                    ->where('status', 'published')
                    ->where('published_at', '>=', $monthStart)
                    ->where('published_at', '<', $monthEnd)
                    ->where('published_at', '<=', $this->now()->utc());

                return $authorId !== null
                    ? $query->where('author_id', $authorId)
                    : $query;
            }

            $statuses = match ($status) {
                'needs_approval' => config('newsroom.approval_statuses', ['submitted', 'review', 'in_review']),
                'in_review' => config('newsroom.review_statuses', ['review', 'in_review']),
                default => [$status],
            };
            $query->whereIn('status', $statuses);
        }

        if ($authorId !== null) {
            $query->where('author_id', $authorId);
        }

        return $query;
    }

    /**
     * @return Collection<int, Author>
     */
    public function managedAuthors(User $user): Collection
    {
        [$monthStart, $monthEnd] = $this->localMonthBounds();
        $query = Author::query();

        match ($this->role($user)) {
            'administrator', 'editor_in_chief' => null,
            'editor' => $query->whereHas('editorialSupervisions', fn (Builder $supervision) => $supervision
                ->active()
                ->where('editor_id', $user->id)),
            'author' => $query->whereKey($user->teamMember?->author_id ?? 0),
            default => $query->whereRaw('1 = 0'),
        };

        return $query
            ->with(['avatar', 'teamMembers.user.roles', 'primarySupervision.editor'])
            ->withCount([
                'articles as drafts_count' => fn (Builder $articles) => $articles->where('status', 'draft'),
                'articles as pending_approval_count' => fn (Builder $articles) => $articles->whereIn('status', config('newsroom.approval_statuses', ['submitted', 'review', 'in_review'])),
                'articles as changes_requested_count' => fn (Builder $articles) => $articles->where('status', 'changes_requested'),
                'articles as scheduled_count' => fn (Builder $articles) => $articles->where('status', 'scheduled'),
                'articles as published_this_month_count' => fn (Builder $articles) => $articles
                    ->where('status', 'published')
                    ->where('published_at', '>=', $monthStart)
                    ->where('published_at', '<', $monthEnd)
                    ->where('published_at', '<=', $this->now()->utc()),
            ])
            ->withMax('articles as last_activity_at', 'last_editorial_activity_at')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->each(function (Author $author): void {
                $lastActivity = $author->getAttribute('last_activity_at');
                $author->setAttribute('last_activity_at', $lastActivity ? Carbon::parse($lastActivity) : null);
            });
    }

    /**
     * Return operational editor workload with batched aggregates. An article is
     * attributed to its assigned reviewer first, then its supervising editor,
     * then the author's active primary supervisor.
     *
     * @return Collection<int, array{editor: User, authors_managed: int, posts_waiting: int, posts_overdue: int, posts_in_review: int, approved_this_week: int, avg_review_hours: float|null}>
     */
    public function editorWorkload(User $user): Collection
    {
        $role = $this->role($user);

        if (! in_array($role, ['administrator', 'editor_in_chief', 'editor'], true)) {
            return collect();
        }

        $editors = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', ['editor', 'editor_in_chief']))
            ->when($role === 'editor', fn (Builder $query) => $query->whereKey($user->id))
            ->with(['roles', 'teamMember.profileImage'])
            ->orderBy('name')
            ->get();

        if ($editors->isEmpty()) {
            return collect();
        }

        $editorIds = $editors->modelKeys();
        $authorCounts = EditorialSupervision::query()
            ->active()
            ->whereIn('editor_id', $editorIds)
            ->selectRaw('editor_id, COUNT(DISTINCT author_id) AS aggregate')
            ->groupBy('editor_id')
            ->pluck('aggregate', 'editor_id');

        $effectiveEditor = 'COALESCE(articles.assigned_editor_id, articles.supervising_editor_id, primary_supervisions.editor_id)';
        [$weekStart, $weekEnd] = $this->localWeekBounds();
        $slaCutoff = $this->now()->subHours($this->approvalSlaHours())->utc();
        $primarySupervisions = $this->activePrimarySupervisionQuery();

        $counts = Article::query()
            ->leftJoinSub($primarySupervisions, 'primary_supervisions', 'primary_supervisions.author_id', '=', 'articles.author_id')
            ->whereIn(DB::raw($effectiveEditor), $editorIds)
            ->selectRaw("{$effectiveEditor} AS editor_id")
            ->selectRaw("SUM(CASE WHEN articles.status IN ('submitted', 'review', 'in_review') AND articles.approved_at IS NULL THEN 1 ELSE 0 END) AS posts_waiting")
            ->selectRaw("SUM(CASE WHEN articles.status IN ('submitted', 'review', 'in_review') AND articles.approved_at IS NULL AND articles.submitted_at < ? THEN 1 ELSE 0 END) AS posts_overdue", [$slaCutoff])
            ->selectRaw("SUM(CASE WHEN articles.status IN ('review', 'in_review') THEN 1 ELSE 0 END) AS posts_in_review")
            ->selectRaw('SUM(CASE WHEN articles.approved_at >= ? AND articles.approved_at < ? THEN 1 ELSE 0 END) AS approved_this_week', [$weekStart, $weekEnd])
            ->groupByRaw($effectiveEditor)
            ->get()
            ->keyBy('editor_id');

        $reviewTimes = Article::query()
            ->leftJoinSub($this->activePrimarySupervisionQuery(), 'primary_supervisions', 'primary_supervisions.author_id', '=', 'articles.author_id')
            ->whereIn(DB::raw($effectiveEditor), $editorIds)
            ->whereNotNull('articles.submitted_at')
            ->whereNotNull('articles.approved_at')
            ->selectRaw("{$effectiveEditor} AS editor_id")
            ->addSelect(['articles.submitted_at', 'articles.approved_at'])
            ->get()
            ->groupBy('editor_id')
            ->map(function (Collection $articles): ?float {
                $durations = $articles
                    ->filter(fn (Article $article): bool => $article->approved_at->gte($article->submitted_at))
                    ->map(fn (Article $article): float => $article->submitted_at->diffInMinutes($article->approved_at) / 60);

                return $durations->isEmpty() ? null : round((float) $durations->avg(), 1);
            });

        return $editors->map(function (User $editor) use ($authorCounts, $counts, $reviewTimes): array {
            $metrics = $counts->get($editor->id);

            return [
                'editor' => $editor,
                'authors_managed' => (int) ($authorCounts->get($editor->id) ?? 0),
                'posts_waiting' => (int) ($metrics?->posts_waiting ?? 0),
                'posts_overdue' => (int) ($metrics?->posts_overdue ?? 0),
                'posts_in_review' => (int) ($metrics?->posts_in_review ?? 0),
                'approved_this_week' => (int) ($metrics?->approved_this_week ?? 0),
                'avg_review_hours' => $reviewTimes->get($editor->id),
            ];
        })->values();
    }

    /**
     * @return Collection<int, AdminAuditLog>
     */
    public function activity(User $user, int $limit = 30): Collection
    {
        $query = AdminAuditLog::query()
            ->whereIn('action', config('newsroom.activity_actions', []))
            ->with([
                'actor',
                'auditable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    Article::class => ['author', 'category'],
                    Author::class => ['avatar'],
                    EditorialSupervision::class => ['author', 'editor'],
                    User::class => ['teamMember.author'],
                ]),
            ]);

        $role = $this->role($user);

        if ($role === 'editor') {
            $visibleArticleIds = $this->articleQuery($user)->select('articles.id');
            $managedAuthorIds = EditorialSupervision::query()
                ->active()
                ->where('editor_id', $user->id)
                ->select('author_id');
            $managedUserIds = User::query()
                ->whereHas('teamMember', fn (Builder $teamMembers) => $teamMembers
                    ->whereIn('author_id', clone $managedAuthorIds))
                ->select('users.id');
            $managedSupervisionIds = EditorialSupervision::query()
                ->whereIn('author_id', clone $managedAuthorIds)
                ->select('id');

            $query->where(function (Builder $visible) use ($visibleArticleIds, $managedAuthorIds, $managedUserIds, $managedSupervisionIds): void {
                $visible
                    ->where(fn (Builder $articles) => $articles
                        ->where('auditable_type', Article::class)
                        ->whereIn('auditable_id', $visibleArticleIds))
                    ->orWhere(fn (Builder $authors) => $authors
                        ->where('auditable_type', Author::class)
                        ->whereIn('auditable_id', clone $managedAuthorIds))
                    ->orWhere(fn (Builder $users) => $users
                        ->where('auditable_type', User::class)
                        ->whereIn('auditable_id', $managedUserIds))
                    ->orWhere(fn (Builder $supervisions) => $supervisions
                        ->where('auditable_type', EditorialSupervision::class)
                        ->whereIn('auditable_id', $managedSupervisionIds));
            });
        } elseif (! in_array($role, ['administrator', 'editor_in_chief'], true)) {
            $visibleArticleIds = $this->articleQuery($user)->select('articles.id');
            $query
                ->where('auditable_type', Article::class)
                ->whereIn('auditable_id', $visibleArticleIds);
        }

        return $query->latest()->limit($limit)->get();
    }

    /**
     * @return Collection<int, Article>
     */
    public function calendar(User $user, Carbon $from, Carbon $to): Collection
    {
        $fromUtc = $from->copy()->timezone(config('newsroom.timezone', 'Europe/Skopje'))->startOfDay()->utc();
        $toUtc = $to->copy()->timezone(config('newsroom.timezone', 'Europe/Skopje'))->endOfDay()->addSecond()->utc();

        return $this->managedPosts($user)
            ->where(function (Builder $query) use ($fromUtc, $toUtc): void {
                $query
                    ->where(fn (Builder $scheduled) => $scheduled
                        ->where('scheduled_at', '>=', $fromUtc)
                        ->where('scheduled_at', '<', $toUtc))
                    ->orWhere(fn (Builder $failed) => $failed
                        ->where('publication_failed_at', '>=', $fromUtc)
                        ->where('publication_failed_at', '<', $toUtc))
                    ->orWhere(fn (Builder $approved) => $approved
                        ->where('status', 'approved')
                        ->whereNull('scheduled_at')
                        ->where('approved_at', '>=', $fromUtc)
                        ->where('approved_at', '<', $toUtc));
            })
            ->get()
            ->sortBy(fn (Article $article) => $article->scheduled_at
                ?? $article->publication_failed_at
                ?? $article->approved_at
                ?? $article->updated_at)
            ->values();
    }

    /**
     * @return array<string, Collection<int, Article>>
     */
    public function today(User $user): array
    {
        [$start, $end] = $this->localDayBounds();
        $slaWindowStart = $start->copy()->subHours($this->approvalSlaHours());
        $slaWindowEnd = $end->copy()->subHours($this->approvalSlaHours());
        $query = fn (): Builder => $this->managedPosts($user);

        return [
            'scheduled' => $query()->where('status', 'scheduled')->whereBetween('scheduled_at', [$start, $end])->orderBy('scheduled_at')->get(),
            'published' => $query()->where('status', 'published')->whereBetween('published_at', [$start, $end])->latest('published_at')->get(),
            'due_for_review' => $query()->whereIn('status', config('newsroom.approval_statuses', ['submitted', 'review', 'in_review']))->whereBetween('submitted_at', [$slaWindowStart, $slaWindowEnd])->orderBy('submitted_at')->get(),
            'overdue' => $query()->whereIn('status', config('newsroom.approval_statuses', ['submitted', 'review', 'in_review']))->where('submitted_at', '<', $this->now()->subHours($this->approvalSlaHours())->utc())->orderBy('submitted_at')->get(),
            'latest_submissions' => $query()->whereNotNull('submitted_at')->latest('submitted_at')->limit(8)->get(),
        ];
    }

    /**
     * @return array<string, array{label: string, state: string, complete: bool, message: string|null}>
     */
    public function checklist(Article $article, ?User $viewer = null): array
    {
        return $this->publicationReadiness->checklist($article, $viewer);
    }

    private function articleQuery(User $user): Builder
    {
        if (! $user->isActive()) {
            return Article::query()->whereRaw('1 = 0');
        }

        return Article::query()->visibleToEditorialUser($user);
    }

    /**
     * Scope dashboard numbers to the operational responsibility of the current
     * persona. Editors see assigned posts and authors they actively supervise,
     * without inflating their queue when they merely touched an unrelated post.
     */
    private function dashboardArticleQuery(User $user): Builder
    {
        if (! $user->isActive()) {
            return Article::query()->whereRaw('1 = 0');
        }

        return match ($this->role($user)) {
            'administrator', 'editor_in_chief' => Article::query(),
            'editor' => Article::query()->where(function (Builder $query) use ($user): void {
                $query
                    ->where('assigned_editor_id', $user->id)
                    ->orWhere('supervising_editor_id', $user->id)
                    ->orWhereIn(
                        'author_id',
                        $user->editorialSupervisions()->active()->select('author_id'),
                    );
            }),
            'author' => Article::query()->where('author_id', $user->teamMember?->author_id ?? 0),
            default => Article::query()->whereRaw('1 = 0'),
        };
    }

    private function approvalQueueQuery(User $user): Builder
    {
        return $this->managedPosts($user)
            ->whereIn('status', config('newsroom.approval_statuses', ['submitted', 'review', 'in_review']))
            ->whereNull('approved_at')
            // Oldest SLA anchor first. The ID tie-breaker keeps pages stable
            // when several submissions share the same timestamp.
            ->orderByRaw('COALESCE(submitted_at, review_started_at, updated_at) ASC')
            ->orderBy('id');
    }

    /** @return array<string, mixed> */
    private function approvalQueueItem(Article $article): array
    {
        $checklist = $this->checklist($article);
        $waitingSince = $article->submitted_at ?? $article->review_started_at ?? $article->updated_at;
        $cutoff = $this->now()->subHours($this->approvalSlaHours())->utc();

        return [
            'article' => $article,
            'waiting_since' => $waitingSince,
            'waiting_minutes' => $waitingSince ? max(0, (int) $waitingSince->diffInMinutes($this->now(), false)) : 0,
            'overdue' => (bool) ($waitingSince?->lt($cutoff)),
            'word_count' => $this->wordCount($article),
            'reading_time' => $article->reading_time_minutes,
            'seo' => $this->checklistProgress($checklist, ['seo_title', 'meta_description', 'canonical']),
            'images' => $this->checklistProgress($checklist, ['featured_image', 'alt_text']),
            'sources' => $this->sourceProgress($article, $checklist),
            'unresolved_comments' => (int) $article->unresolved_editorial_comments_count,
            'blocking_issues' => collect($checklist)->where('state', 'blocking')->count(),
            'checklist' => $checklist,
        ];
    }

    private function now(): Carbon
    {
        return now(config('newsroom.timezone', 'Europe/Skopje'));
    }

    /** @return array{Carbon, Carbon} */
    private function localDayBounds(?Carbon $day = null): array
    {
        $day = ($day ?? $this->now())->copy()->timezone(config('newsroom.timezone', 'Europe/Skopje'));

        return [$day->copy()->startOfDay()->utc(), $day->copy()->addDay()->startOfDay()->utc()];
    }

    /** @return array{Carbon, Carbon} */
    private function localMonthBounds(): array
    {
        $now = $this->now();

        return [$now->copy()->startOfMonth()->utc(), $now->copy()->addMonth()->startOfMonth()->utc()];
    }

    /** @return array{Carbon, Carbon} */
    private function localWeekBounds(): array
    {
        $now = $this->now();

        return [$now->copy()->startOfWeek()->utc(), $now->copy()->addWeek()->startOfWeek()->utc()];
    }

    private function approvalSlaHours(): int
    {
        return max(1, (int) config('newsroom.approval_sla_hours', 24));
    }

    /** @return array<string, mixed> */
    private function urgentArticleItem(Article $article, string $type, string $severity, string $reason, ?Carbon $occurredAt, bool $blocking): array
    {
        return [
            'key' => "article:{$article->id}:{$type}",
            'type' => $type,
            'severity' => $severity,
            'article' => $article,
            'author' => $article->author,
            'editor' => $article->assignedEditor ?? $article->supervisingEditor,
            'reason' => $reason,
            'occurred_at' => $occurredAt,
            'age' => $occurredAt?->diffForHumans($this->now()) ?? 'Непознато',
            'blocking' => $blocking,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function appendMetadataIssues(Collection $items, User $user, int $limit): void
    {
        $candidates = $this->managedPosts($user)
            ->whereIn('status', ['submitted', 'review', 'in_review', 'approved', 'scheduled'])
            ->latest('last_editorial_activity_at')
            ->limit($limit * 2)
            ->get();

        foreach ($candidates as $article) {
            if ($items->count() >= $limit) {
                break;
            }

            if ($items->contains('article.id', $article->id)) {
                continue;
            }

            $blocking = collect($this->checklist($article))
                ->except('approval_status')
                ->where('state', 'blocking')
                ->pluck('label');

            if ($blocking->isNotEmpty()) {
                $items->push($this->urgentArticleItem(
                    $article,
                    'missing_metadata',
                    'warning',
                    'Недостигаат задолжителни податоци: '.$blocking->join(', ').'.',
                    $article->last_editorial_activity_at ?? $article->updated_at,
                    true,
                ));
            }
        }
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function appendPeopleIssues(Collection $items, int $limit): void
    {
        Author::query()
            ->active()
            ->whereDoesntHave('editorialSupervisions', fn (Builder $query) => $query->active())
            ->with('avatar')
            ->orderBy('name')
            ->limit(max(0, $limit - $items->count()))
            ->get()
            ->each(function (Author $author) use ($items): void {
                $items->push([
                    'key' => "author:{$author->id}:without_supervisor",
                    'type' => 'author_without_supervisor',
                    'severity' => 'warning',
                    'article' => null,
                    'author' => $author,
                    'editor' => null,
                    'reason' => 'Авторот нема активен одговорен уредник.',
                    'occurred_at' => $author->updated_at,
                    'age' => $author->updated_at?->diffForHumans($this->now()) ?? 'Непознато',
                    'blocking' => false,
                ]);
            });

        if ($items->count() >= $limit) {
            return;
        }

        User::query()
            ->where('status', '!=', 'active')
            ->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', ['editor', 'editor_in_chief']))
            ->whereHas('editorialSupervisions', fn (Builder $supervisions) => $supervisions->active())
            ->with(['editorialSupervisions' => fn ($query) => $query->active()->with('author')])
            ->limit(max(0, $limit - $items->count()))
            ->get()
            ->each(function (User $editor) use ($items): void {
                $items->push([
                    'key' => "user:{$editor->id}:disabled_with_authors",
                    'type' => 'disabled_editor_with_authors',
                    'severity' => 'danger',
                    'article' => null,
                    'author' => $editor->editorialSupervisions->first()?->author,
                    'editor' => $editor,
                    'reason' => 'Неактивен уредник сè уште има доделени автори.',
                    'occurred_at' => $editor->updated_at,
                    'age' => $editor->updated_at?->diffForHumans($this->now()) ?? 'Непознато',
                    'blocking' => true,
                ]);
            });
    }

    /** @param array<string, array<string, mixed>> $checklist @param array<int, string> $keys @return array{complete: int, total: int, percent: int} */
    private function checklistProgress(array $checklist, array $keys): array
    {
        $items = collect($keys)
            ->filter(fn (string $key): bool => array_key_exists($key, $checklist))
            ->map(fn (string $key): array => $checklist[$key]);
        $complete = $items->where('complete', true)->count();
        $total = $items->count();

        return ['complete' => $complete, 'total' => $total, 'percent' => $total > 0 ? (int) round(($complete / $total) * 100) : 100];
    }

    /** @param array<string, array<string, mixed>> $checklist @return array{complete: int, total: int, percent: int, required: bool} */
    private function sourceProgress(Article $article, array $checklist): array
    {
        $required = $this->sourcesRequired($article);
        $complete = (bool) $checklist['sources']['complete'];

        return ['complete' => $complete ? 1 : 0, 'total' => 1, 'percent' => $complete ? 100 : 0, 'required' => $required];
    }

    private function sourcesRequired(Article $article): bool
    {
        return $this->publicationReadiness->sourcesRequired($article);
    }

    private function wordCount(Article $article): int
    {
        $plainText = html_entity_decode(strip_tags((string) $article->body_html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        preg_match_all('/[\p{L}\p{N}]+(?:[’\'-][\p{L}\p{N}]+)*/u', $plainText, $matches);

        return count($matches[0]);
    }

    private function activePrimarySupervisionQuery(): Builder
    {
        return EditorialSupervision::query()
            ->active()
            ->where('is_primary', true)
            ->selectRaw('author_id, MIN(editor_id) AS editor_id')
            ->groupBy('author_id');
    }
}
