<?php

namespace App\Services\Articles;

use App\Models\Article;
use App\Services\Media\MediaAssetUploader;
use App\Services\Newsroom\NewsroomWorkspaceService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ArticleFormDataService
{
    /** @var list<string> */
    private const WORKFLOW_FIELDS = [
        'published_at',
        'published_by',
        'scheduled_at',
        'submitted_at',
        'submitted_by',
        'review_started_at',
        'reviewed_by',
        'changes_requested_at',
        'changes_requested_by',
        'approved_at',
        'approved_by',
        'archived_at',
        'archived_by',
        'publication_failed_at',
        'publication_failure_reason',
        'assigned_editor_id',
        'supervising_editor_id',
    ];

    public function __construct(
        private readonly MediaAssetUploader $mediaUploader,
        private readonly NewsroomWorkspaceService $newsroom,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(array $data, ?Article $article = null): array
    {
        $user = auth()->user();
        $isDirectPublisher = $user !== null
            && ($user->isAdmin() || $user->hasRole('editor_in_chief') || $user->hasRole('editor'))
            && $user->hasPermission('articles.publish');

        if (! $isDirectPublisher && ($user?->hasRole('author') || $user?->hasRole('contributor'))) {
            abort_unless($user->teamMember?->author_id, 403, 'Author account is not linked to an Author profile.');
            abort_if($article && $article->author_id !== $user->teamMember->author_id, 403);

            $data['author_id'] = $user->teamMember->author_id;
            $data['status'] = in_array($data['status'] ?? 'draft', ['draft', 'submitted', 'changes_requested', 'review'], true)
                ? $data['status']
                : ($article?->status ?? 'draft');
        } elseif ($user?->hasRole('editor')) {
            $authorId = (int) ($data['author_id'] ?? 0);
            $managedAuthorIds = $this->newsroom->managedAuthors($user)->modelKeys();
            $ownAuthorId = (int) ($user->teamMember?->author_id ?? 0);
            $preservesCurrentAuthor = $article && $authorId === (int) $article->author_id;

            abort_unless(
                $authorId > 0 && (
                    $preservesCurrentAuthor
                    || $authorId === $ownAuthorId
                    || in_array($authorId, $managedAuthorIds, true)
                ),
                403,
                'The selected author is outside your editorial scope.',
            );
        } elseif ($user && ! $user->isAdmin() && ! $user->hasRole('editor_in_chief')) {
            abort(403, 'This account cannot assign an article author.');
        }

        $uploadedPath = Arr::pull($data, 'featured_image_upload');
        $uploadedPath = is_array($uploadedPath) ? Arr::first($uploadedPath) : $uploadedPath;
        $existingPath = $article?->featuredImage?->path;

        if (filled($uploadedPath) && $uploadedPath !== $existingPath) {
            $data['featured_image_id'] = $this->mediaUploader->registerStoredPath(
                (string) $uploadedPath,
                ['uploaded_by' => auth()->id(), 'alt_text' => $data['title'] ?? null],
            )->getKey();
        }

        if (! $article) {
            $data['slug'] = $this->uniqueSlug((string) ($data['title'] ?? ''));
            $data['created_by'] = auth()->id();
        } else {
            $data['slug'] = $article->slug;
        }

        $data['updated_by'] = auth()->id();

        $presentationType = $data['presentation_type'] ?? 'standard';
        $data['presentation_type'] = in_array($presentationType, ['standard', 'event_guide', 'interview', 'column'], true)
            ? $presentationType
            : 'standard';

        $data['guide_metadata'] = $data['presentation_type'] === 'event_guide'
            ? $this->cleanGuideMetadata($data['guide_metadata'] ?? [])
            : null;

        if ($article) {
            // Workflow state is controlled exclusively by
            // ArticleEditorialWorkflow. Hidden Livewire fields are not a
            // trusted transition channel and cannot publish, schedule,
            // archive, or roll an article backwards during a content save.
            $data['status'] = $article->status;

            foreach (self::WORKFLOW_FIELDS as $field) {
                $data[$field] = $article->getAttribute($field);
            }

            return $data;
        }

        foreach (self::WORKFLOW_FIELDS as $field) {
            unset($data[$field]);
        }

        $status = $data['status'] ?? 'draft';

        if ($status === 'submitted' || $status === 'review') {
            $data['status'] = 'submitted';
            $data['submitted_at'] ??= now();
            $data['submitted_by'] ??= auth()->id();
            $data['published_at'] = null;
            $data['scheduled_at'] = null;
            $data['last_editorial_activity_at'] = now();
        } elseif ($status === 'draft') {
            $data['published_at'] = null;
            $data['scheduled_at'] = null;
        } else {
            abort(422, 'Use an authorized editorial workflow action to change publication status.');
        }

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'objava';
        $slug = $base;
        $suffix = 2;

        while (Article::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function cleanGuideMetadata(mixed $metadata): array
    {
        $metadata = is_array($metadata) ? $metadata : [];

        $events = collect($metadata['events'] ?? [])
            ->filter(fn ($event): bool => is_array($event) && filled($event['title'] ?? null))
            ->values()
            ->map(function (array $event, int $index): array {
                $title = trim((string) $event['title']);

                return array_filter([
                    'id' => trim((string) ($event['id'] ?? '')) ?: Str::slug($title) ?: 'event-'.($index + 1),
                    'title' => $title,
                    'start_date' => $this->stringOrNull($event['start_date'] ?? null),
                    'end_date' => $this->stringOrNull($event['end_date'] ?? null),
                    'time' => $this->stringOrNull($event['time'] ?? null),
                    'location' => $this->stringOrNull($event['location'] ?? null),
                    'price_label' => $this->stringOrNull($event['price_label'] ?? null),
                    'short_description' => $this->stringOrNull($event['short_description'] ?? null),
                    'source_label' => $this->stringOrNull($event['source_label'] ?? null),
                    'source_url' => $this->safeUrl($event['source_url'] ?? null),
                    'booking_label' => $this->stringOrNull($event['booking_label'] ?? null),
                    'booking_url' => $this->safeUrl($event['booking_url'] ?? null),
                    'display_order' => (int) ($event['display_order'] ?? ($index + 1) * 10),
                ], fn ($value): bool => $value !== null && $value !== '');
            })
            ->all();

        return array_filter([
            'meta' => array_filter([
                'period_label' => $this->stringOrNull(data_get($metadata, 'meta.period_label')),
                'city_label' => $this->stringOrNull(data_get($metadata, 'meta.city_label')),
                'last_verified_at' => $this->stringOrNull(data_get($metadata, 'meta.last_verified_at')),
            ]),
            'events' => $events,
            'practical_notes' => collect($metadata['practical_notes'] ?? [])
                ->map(fn ($note): ?string => $this->stringOrNull(is_array($note) ? ($note['note'] ?? null) : $note))
                ->filter()
                ->values()
                ->all(),
            'sources' => collect($metadata['sources'] ?? [])
                ->filter(fn ($source): bool => is_array($source) && filled($source['label'] ?? null) && filled($source['url'] ?? null))
                ->map(fn (array $source): array => [
                    'label' => trim((string) $source['label']),
                    'url' => $this->safeUrl($source['url'] ?? null),
                ])
                ->filter(fn (array $source): bool => filled($source['url']))
                ->values()
                ->all(),
        ], fn ($value): bool => $value !== [] && $value !== null);
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function safeUrl(mixed $value): ?string
    {
        $url = $this->stringOrNull($value);

        if (! $url || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }
}
