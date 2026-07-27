<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleCardResource;
use App\Http\Resources\CategoryResource;
use App\Models\Article;
use App\Models\Category;
use App\Models\HomepageSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class HomepageController extends Controller
{
    private const MAX_SECTION_ARTICLES = 9;

    public function __invoke(): JsonResponse
    {
        $seenArticleIds = [];
        $sections = HomepageSection::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (HomepageSection $section) use (&$seenArticleIds): array {
                $payload = $this->sectionPayload($section, $seenArticleIds);
                $seenArticleIds = array_values(array_unique([
                    ...$seenArticleIds,
                    ...$payload['articles']->pluck('id')->all(),
                ]));

                return $payload;
            })
            ->filter(fn (array $section) => $section['articles']->isNotEmpty() || $section['promo_cards']->isNotEmpty())
            ->values()
            ->map(fn (array $section) => [
                'key' => $section['model']->key,
                'name' => $section['model']->name,
                'type' => $section['model']->type,
                'title' => $section['configuration']['title'] ?? $section['model']->name,
                'description' => $section['configuration']['description'] ?? null,
                'layout' => $this->layout($section['configuration']['layout'] ?? null),
                'ad_zone_after' => $this->adZoneAfter($section['configuration']['ad_zone_after'] ?? null),
                'rules' => $this->rules($section['model'], $section['configuration']),
                'category' => $section['category'] && ! $this->isDefaultCategory($section['category'])
                    ? CategoryResource::make($section['category'])
                    : null,
                'articles' => ArticleCardResource::collection($section['articles']),
                'promo_cards' => $section['promo_cards']->values()->all(),
            ]);

        if ($sections->isEmpty()) {
            $sections = collect([$this->fallbackSection()]);
        }

        return response()->json([
            'data' => [
                'sections' => $sections,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackSection(): array
    {
        return [
            'key' => 'latest_fallback',
            'name' => 'Најново',
            'type' => 'latest',
            'title' => 'Најново',
            'description' => 'Последни објавени текстови од редакцијата.',
            'layout' => 'grid',
            'ad_zone_after' => null,
            'rules' => [
                'type' => 'latest',
                'limit' => 6,
                'category_slug' => null,
                'article_slugs' => [],
            ],
            'category' => null,
            'articles' => ArticleCardResource::collection($this->latestArticles(6)),
            'promo_cards' => [],
        ];
    }

    /**
     * @return array{model: HomepageSection, configuration: array<string, mixed>, category: Category|null, articles: Collection<int, Article>, promo_cards: Collection<int, array<string, string>>}
     */
    private function sectionPayload(HomepageSection $section, array $excludedArticleIds = []): array
    {
        $configuration = $section->configuration ?? [];
        $limit = max(1, min((int) ($configuration['limit'] ?? 6), self::MAX_SECTION_ARTICLES));
        $category = null;
        $promoCards = $section->type === 'promo_cards'
            ? $this->promoCards($configuration)
            : collect();

        $articles = match ($section->type) {
            'manual' => $this->manualArticles($configuration, $limit, $excludedArticleIds),
            'category' => $this->categoryArticles($configuration, $limit, $category, $excludedArticleIds),
            'latest' => $this->latestArticles($limit, $excludedArticleIds, $configuration),
            default => collect(),
        };

        return [
            'model' => $section,
            'configuration' => $configuration,
            'category' => $category,
            'articles' => $articles,
            'promo_cards' => $promoCards,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return Collection<int, array<string, string>>
     */
    private function promoCards(array $configuration): Collection
    {
        return collect($configuration['cards'] ?? [])
            ->filter(fn ($card): bool => is_array($card))
            ->sortBy(fn (array $card): int => (int) ($card['sort_order'] ?? 0))
            ->map(function (array $card): ?array {
                $title = trim((string) ($card['title'] ?? ''));
                $href = trim((string) ($card['href'] ?? ''));
                $imageUrl = trim((string) ($card['image_url'] ?? ''));
                $alt = trim((string) ($card['alt'] ?? ''));

                if ($title === '' || $href === '' || $imageUrl === '') {
                    return null;
                }

                return [
                    'title' => $title,
                    'href' => $href,
                    'image_url' => $imageUrl,
                    'alt' => $alt !== '' ? $alt : $title,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return Collection<int, Article>
     */
    private function manualArticles(array $configuration, int $limit, array $excludedArticleIds = []): Collection
    {
        $slugs = collect($configuration['article_slugs'] ?? [])
            ->filter(fn ($slug) => is_string($slug) && $slug !== '')
            ->values();

        if ($slugs->isEmpty()) {
            return collect();
        }

        return Article::query()
            ->published()
            ->whereIn('slug', $slugs)
            ->when($excludedArticleIds !== [], fn ($query) => $query->whereNotIn('id', $excludedArticleIds))
            ->with(['author', 'category', 'featuredImage', 'sponsoredContentDetail'])
            ->get()
            ->sortBy(fn (Article $article) => $slugs->search($article->slug))
            ->take($limit)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return Collection<int, Article>
     */
    private function categoryArticles(array $configuration, int $limit, ?Category &$category, array $excludedArticleIds = []): Collection
    {
        $categorySlug = $configuration['category_slug'] ?? null;

        if (! is_string($categorySlug) || $categorySlug === '') {
            return collect();
        }

        $category = Category::query()
            ->where('is_active', true)
            ->where('slug', $categorySlug)
            ->with('seoMetadata.ogImage')
            ->first();

        if (! $category) {
            return collect();
        }

        $query = Article::query()
            ->inCategory($category)
            ->published()
            ->when($excludedArticleIds !== [], fn ($query) => $query->whereNotIn('articles.id', $excludedArticleIds))
            ->when($this->excludeDefaultCategory($configuration), fn ($query) => $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->whereNotIn('slug', ['uncategorized', 'pocetna'])))
            ->with(['author', 'category', 'featuredImage', 'sponsoredContentDetail']);

        return $this->freshArticles($query, $limit, $configuration);
    }

    /**
     * @return Collection<int, Article>
     */
    private function latestArticles(int $limit, array $excludedArticleIds = [], array $configuration = []): Collection
    {
        $query = Article::query()
            ->published()
            ->when($excludedArticleIds !== [], fn ($query) => $query->whereNotIn('id', $excludedArticleIds))
            ->when($this->excludeDefaultCategory($configuration), fn ($query) => $query->whereHas('category', fn ($categoryQuery) => $categoryQuery->whereNotIn('slug', ['uncategorized', 'pocetna'])))
            ->with(['author', 'category', 'featuredImage', 'sponsoredContentDetail']);

        return $this->freshArticles($query, $limit, $configuration);
    }

    private function freshArticles($query, int $limit, array $configuration): Collection
    {
        $days = max(0, min((int) ($configuration['freshness_days'] ?? 0), 3650));
        $fresh = (clone $query)
            ->when($days > 0, fn ($freshQuery) => $freshQuery->where('published_at', '>=', now()->subDays($days)))
            ->latest('published_at')
            ->limit($limit)
            ->get();

        if ($fresh->count() >= $limit || ($configuration['fallback_strategy'] ?? 'evergreen') !== 'evergreen') {
            return $fresh;
        }

        return $fresh->concat(
            (clone $query)
                ->whereNotIn('articles.id', $fresh->pluck('id'))
                ->latest('published_at')
                ->limit($limit - $fresh->count())
                ->get()
        )->values();
    }

    private function excludeDefaultCategory(array $configuration): bool
    {
        return (bool) ($configuration['exclude_default_category'] ?? true);
    }

    private function isDefaultCategory(Category $category): bool
    {
        return in_array($category->slug, ['uncategorized', 'pocetna'], true);
    }

    private function layout(mixed $layout): string
    {
        return in_array($layout, ['hero', 'grid', 'compact', 'list'], true) ? $layout : 'grid';
    }

    private function adZoneAfter(mixed $zoneKey): ?string
    {
        return is_string($zoneKey) && $zoneKey !== '' ? $zoneKey : null;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function rules(HomepageSection $section, array $configuration): array
    {
        $configuredCards = is_array($configuration['cards'] ?? null) ? $configuration['cards'] : [];

        return [
            'type' => $section->type,
            'limit' => max(1, min((int) ($configuration['limit'] ?? 6), self::MAX_SECTION_ARTICLES)),
            'category_slug' => $configuration['category_slug'] ?? null,
            'article_slugs' => array_values(array_filter($configuration['article_slugs'] ?? [])),
            'promo_card_count' => count(array_filter($configuredCards, 'is_array')),
            'freshness_days' => max(0, min((int) ($configuration['freshness_days'] ?? 0), 3650)),
            'fallback_strategy' => in_array(($configuration['fallback_strategy'] ?? 'evergreen'), ['evergreen', 'hide'], true)
                ? $configuration['fallback_strategy'] ?? 'evergreen'
                : 'evergreen',
            'exclude_default_category' => $this->excludeDefaultCategory($configuration),
        ];
    }
}
