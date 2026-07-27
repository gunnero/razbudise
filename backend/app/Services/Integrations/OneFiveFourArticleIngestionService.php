<?php

namespace App\Services\Integrations;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Tag;
use App\Services\Operations\AdminAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OneFiveFourArticleIngestionService
{
    public function __construct(private readonly AdminAuditLogger $auditLogger) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{article: Article, created: bool}
     */
    public function ingest(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $sourceId = (string) $payload['source_id'];
            $article = Article::query()
                ->where('onefivefour_source_id', $sourceId)
                ->lockForUpdate()
                ->first();
            $created = $article === null;
            $before = $article?->only([
                'title',
                'excerpt',
                'body_html',
                'status',
                'author_id',
                'category_id',
                'featured_image_id',
                'published_at',
                'scheduled_at',
                'onefivefour_source_id',
                'onefivefour_metadata',
            ]) ?? [];

            $author = $this->mapAuthor($payload['author']);
            $categories = $this->mapCategories($payload['categories']);
            $featuredImage = $this->localizeFeaturedImage($payload['featured_image_url'] ?? null, $sourceId);
            $statusDates = $this->statusDates((string) $payload['status'], $article);
            $metadata = $this->oneFiveFourMetadata($payload);

            $attributes = [
                'title' => $payload['title'],
                'excerpt' => $payload['excerpt'] ?? null,
                'body_html' => $payload['body_html'],
                'status' => $payload['status'],
                'type' => 'editorial',
                'author_id' => $author->id,
                'category_id' => $categories[0]->id,
                'featured_image_id' => $featuredImage?->id,
                'published_at' => $statusDates['published_at'],
                'scheduled_at' => $statusDates['scheduled_at'],
                'is_ai_assisted' => true,
                'is_ai_generated' => true,
                'onefivefour_source_id' => $sourceId,
                'onefivefour_metadata' => $metadata,
            ];

            if ($article === null) {
                $article = Article::query()->create([
                    ...$attributes,
                    'slug' => $this->uniqueArticleSlug((string) $payload['title']),
                ]);
            } else {
                $article->fill($attributes)->save();
            }

            $article->categories()->sync(collect($categories)->pluck('id')->all());
            $article->tags()->sync($this->mapTags($payload['tags'] ?? []));
            $this->updateSeoMetadata($article, $payload, $featuredImage);

            $article->refresh()->load(['author', 'category', 'categories', 'tags', 'featuredImage', 'seoMetadata']);

            $this->auditLogger->record(
                action: $created ? 'onefivefour.article_created' : 'onefivefour.article_updated',
                auditable: $article,
                before: $before,
                after: $article->only([
                    'title',
                    'excerpt',
                    'body_html',
                    'status',
                    'author_id',
                    'category_id',
                    'featured_image_id',
                    'published_at',
                    'scheduled_at',
                    'onefivefour_source_id',
                    'onefivefour_metadata',
                ]),
                metadata: [
                    'integration' => 'onefivefour',
                    'source_id' => $sourceId,
                    'request_hash' => $metadata['request_hash'],
                    'featured_image_localized' => $featuredImage !== null,
                ],
            );

            return [
                'article' => $article,
                'created' => $created,
            ];
        });
    }

    /**
     * @param  array{name: string, email?: string|null, slug?: string|null}  $payload
     */
    private function mapAuthor(array $payload): Author
    {
        $slug = $this->slug((string) ($payload['slug'] ?? $payload['name']), 'author');
        $email = $payload['email'] ?? null;

        $author = Author::query()
            ->when($email, fn ($query) => $query->where('email', $email))
            ->when(! $email, fn ($query) => $query->where('slug', $slug))
            ->first();

        if ($author) {
            $author->update([
                'name' => $payload['name'],
                'slug' => $author->slug ?: $this->uniqueSlug(Author::class, $slug),
                'email' => $email ?: $author->email,
                'is_active' => true,
            ]);

            return $author->refresh();
        }

        return Author::query()->create([
            'name' => $payload['name'],
            'slug' => $this->uniqueSlug(Author::class, $slug),
            'email' => $email,
            'show_email' => false,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * @param  array<int, array{name: string, slug?: string|null}>  $payload
     * @return array<int, Category>
     */
    private function mapCategories(array $payload): array
    {
        return collect($payload)
            ->map(function (array $categoryPayload): Category {
                $slug = $this->slug((string) ($categoryPayload['slug'] ?? $categoryPayload['name']), 'category');
                $category = Category::query()->where('slug', $slug)->first();

                if ($category) {
                    $category->update([
                        'name' => $categoryPayload['name'],
                        'is_active' => true,
                    ]);

                    return $category->refresh();
                }

                return Category::query()->create([
                    'name' => $categoryPayload['name'],
                    'slug' => $this->uniqueSlug(Category::class, $slug),
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
            })
            ->all();
    }

    /**
     * @param  array<int, string>  $payload
     * @return array<int, int>
     */
    private function mapTags(array $payload): array
    {
        return collect($payload)
            ->filter(fn (string $name): bool => trim($name) !== '')
            ->map(function (string $name): int {
                $slug = $this->slug($name, 'tag');

                return Tag::query()->firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $name],
                )->id;
            })
            ->all();
    }

    private function localizeFeaturedImage(?string $url, string $sourceId): ?MediaAsset
    {
        if (! $url) {
            return null;
        }

        $mimeType = null;
        $extension = $this->extensionFromUrl($url);
        $path = 'media/onefivefour/'.sha1($sourceId.'|'.$url).".{$extension}";
        $existing = MediaAsset::query()
            ->where('disk', 'public')
            ->where('path', $path)
            ->first();

        if ($existing) {
            return $existing;
        }

        $response = Http::timeout(15)->get($url);

        if (! $response->successful() || $response->body() === '') {
            throw ValidationException::withMessages([
                'featured_image_url' => 'The featured image could not be downloaded.',
            ]);
        }

        $contentType = (string) $response->header('Content-Type');
        $mimeType = trim(explode(';', $contentType)[0]) ?: null;

        if ($mimeType !== null && ! str_starts_with($mimeType, 'image/')) {
            throw ValidationException::withMessages([
                'featured_image_url' => 'The featured image must be an image.',
            ]);
        }

        $extension = $this->extensionFromMime($mimeType) ?? $extension;
        $path = 'media/onefivefour/'.sha1($sourceId.'|'.$url).".{$extension}";

        Storage::disk('public')->put($path, $response->body());

        $metadata = $this->mediaMetadata($path);

        return MediaAsset::query()->updateOrCreate(
            [
                'disk' => 'public',
                'path' => $path,
            ],
            [
                'url' => Storage::disk('public')->url($path),
                'mime_type' => $mimeType ?: $metadata['mime_type'],
                'alt_text' => null,
                'caption' => null,
                'width' => $metadata['width'],
                'height' => $metadata['height'],
                'size_bytes' => $metadata['size_bytes'],
                'metadata' => [
                    'source' => 'onefivefour',
                    'source_url' => $url,
                    'onefivefour_source_id' => $sourceId,
                ],
            ],
        );
    }

    /**
     * @return array{published_at: mixed, scheduled_at: mixed}
     */
    private function statusDates(string $status, ?Article $article): array
    {
        return match ($status) {
            'published' => [
                'published_at' => $article?->published_at ?? now(),
                'scheduled_at' => null,
            ],
            'scheduled' => [
                'published_at' => null,
                'scheduled_at' => $article?->scheduled_at,
            ],
            default => [
                'published_at' => null,
                'scheduled_at' => null,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function oneFiveFourMetadata(array $payload): array
    {
        return [
            'source' => 'onefivefour',
            'source_id' => $payload['source_id'],
            'request_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'received_at' => now()->toISOString(),
            'author' => $payload['author'],
            'categories' => $payload['categories'],
            'tags' => $payload['tags'] ?? [],
            'featured_image_url' => $payload['featured_image_url'] ?? null,
            'seo' => $payload['seo'] ?? [],
            'og' => $payload['og'] ?? [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function updateSeoMetadata(Article $article, array $payload, ?MediaAsset $featuredImage): void
    {
        $seo = $payload['seo'] ?? [];
        $og = $payload['og'] ?? [];

        $article->seoMetadata()->updateOrCreate([], [
            'seo_title' => $seo['title'] ?? $payload['title'],
            'meta_description' => $seo['meta_description'] ?? $seo['description'] ?? $payload['excerpt'] ?? null,
            'canonical_url' => $seo['canonical_url'] ?? null,
            'og_title' => $og['title'] ?? $seo['title'] ?? $payload['title'],
            'og_description' => $og['description'] ?? $seo['meta_description'] ?? $seo['description'] ?? $payload['excerpt'] ?? null,
            'og_image_id' => $featuredImage?->id,
            'breadcrumbs' => $seo['breadcrumbs'] ?? null,
            'schema_json' => $seo['schema_json'] ?? null,
        ]);
    }

    private function uniqueArticleSlug(string $title): string
    {
        return $this->uniqueSlug(Article::class, $this->slug($title, 'article'));
    }

    /**
     * @param  class-string  $modelClass
     */
    private function uniqueSlug(string $modelClass, string $baseSlug): string
    {
        $slug = $baseSlug;
        $suffix = 2;

        while ($modelClass::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function slug(string $value, string $fallbackPrefix): string
    {
        $slug = Str::slug($value);

        return $slug !== '' ? $slug : $fallbackPrefix.'-'.substr(sha1($value), 0, 10);
    }

    private function extensionFromUrl(string $url): string
    {
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? $extension : 'jpg';
    }

    private function extensionFromMime(?string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }

    /**
     * @return array{mime_type: string|null, width: int|null, height: int|null, size_bytes: int|null}
     */
    private function mediaMetadata(string $path): array
    {
        $filesystem = Storage::disk('public');
        $localPath = method_exists($filesystem, 'path') ? $filesystem->path($path) : null;
        $dimensions = $localPath ? @getimagesize($localPath) : null;

        return [
            'mime_type' => $filesystem->mimeType($path) ?: null,
            'width' => $dimensions[0] ?? null,
            'height' => $dimensions[1] ?? null,
            'size_bytes' => $filesystem->size($path) ?: null,
        ];
    }
}
