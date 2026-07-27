<?php

namespace App\Services\Homepage;

use App\Models\AdZone;
use App\Models\Article;
use App\Models\Category;
use App\Models\HomepageSection;

class HomepageSectionQaService
{
    /**
     * @return array{totals: array<string, int>, issues: array<int, array<string, mixed>>}
     */
    public function summary(): array
    {
        $issues = [];
        $sections = HomepageSection::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        if ($sections->isEmpty()) {
            $issues[] = [
                'code' => 'no_active_sections',
                'message' => 'Homepage has no active sections to render.',
                'section_id' => null,
                'section_key' => null,
                'section_name' => null,
                'context' => [],
            ];
        }

        $sections->each(function (HomepageSection $section) use (&$issues): void {
            $configuration = $section->configuration ?? [];

            if ($section->type === 'manual') {
                $issues = array_merge($issues, $this->manualIssues($section, $configuration));
            }

            if ($section->type === 'category') {
                $issues = array_merge($issues, $this->categoryIssues($section, $configuration));
            }

            if ($section->type === 'latest' && ! Article::query()->published()->exists()) {
                $issues[] = $this->issue($section, 'empty_section', 'Latest section has no published articles to display.');
            }

            $adZone = trim((string) ($configuration['ad_zone_after'] ?? ''));

            if ($adZone !== '' && ! AdZone::query()->where('key', $adZone)->exists()) {
                $issues[] = $this->issue($section, 'unknown_ad_zone', "Configured ad zone {$adZone} does not exist.", [
                    'ad_zone_after' => $adZone,
                ]);
            }
        });

        $issueCounts = collect($issues)->countBy('code');

        return [
            'totals' => [
                'sections_checked' => $sections->count(),
                'issues' => count($issues),
                'no_active_sections' => (int) $issueCounts->get('no_active_sections', 0),
                'empty_sections' => (int) $issueCounts->get('empty_section', 0),
                'manual_article_missing' => (int) $issueCounts->get('manual_article_missing', 0),
                'manual_article_unpublished' => (int) $issueCounts->get('manual_article_unpublished', 0),
                'category_missing' => (int) $issueCounts->get('category_missing', 0),
                'category_inactive' => (int) $issueCounts->get('category_inactive', 0),
                'unknown_ad_zones' => (int) $issueCounts->get('unknown_ad_zone', 0),
            ],
            'issues' => $issues,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<int, array<string, mixed>>
     */
    private function manualIssues(HomepageSection $section, array $configuration): array
    {
        $slugs = collect($configuration['article_slugs'] ?? [])
            ->filter(fn ($slug): bool => is_string($slug) && trim($slug) !== '')
            ->values();

        if ($slugs->isEmpty()) {
            return [$this->issue($section, 'empty_section', 'Manual section has no article slugs configured.')];
        }

        return $slugs
            ->flatMap(function (string $slug) use ($section): array {
                $article = Article::query()->where('slug', $slug)->first();

                if (! $article) {
                    return [$this->issue($section, 'manual_article_missing', "Manual article {$slug} does not exist.", ['slug' => $slug])];
                }

                if (! $article->shouldBeSearchable()) {
                    return [$this->issue($section, 'manual_article_unpublished', "Manual article {$slug} is not published.", ['slug' => $slug])];
                }

                return [];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<int, array<string, mixed>>
     */
    private function categoryIssues(HomepageSection $section, array $configuration): array
    {
        $slug = trim((string) ($configuration['category_slug'] ?? ''));

        if ($slug === '') {
            return [$this->issue($section, 'category_missing', 'Category section has no category slug configured.')];
        }

        $category = Category::query()->where('slug', $slug)->first();

        if (! $category) {
            return [$this->issue($section, 'category_missing', "Category {$slug} does not exist.", ['category_slug' => $slug])];
        }

        if (! $category->is_active) {
            return [$this->issue($section, 'category_inactive', "Category {$slug} is inactive.", ['category_slug' => $slug])];
        }

        if (! Article::query()->inCategory($category)->published()->exists()) {
            return [$this->issue($section, 'empty_section', "Category {$slug} has no published articles.", ['category_slug' => $slug])];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function issue(HomepageSection $section, string $code, string $message, array $context = []): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'section_id' => $section->id,
            'section_key' => $section->key,
            'section_name' => $section->name,
            'context' => $context,
        ];
    }
}
