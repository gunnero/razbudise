<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('article_category')) {
            Schema::create('article_category', function (Blueprint $table) {
                $table->foreignId('article_id')->constrained()->cascadeOnDelete();
                $table->foreignId('category_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->primary(['article_id', 'category_id']);
                $table->index(['category_id', 'article_id']);
            });
        }

        $this->backfillPrimaryCategories();
        $this->backfillWordpressCategoryMemberships();
    }

    public function down(): void
    {
        Schema::dropIfExists('article_category');
    }

    private function backfillPrimaryCategories(): void
    {
        DB::table('articles')
            ->whereNotNull('category_id')
            ->orderBy('id')
            ->select(['id', 'category_id'])
            ->chunkById(500, function ($articles): void {
                $rows = [];
                $now = now();

                foreach ($articles as $article) {
                    $rows[] = [
                        'article_id' => $article->id,
                        'category_id' => $article->category_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('article_category')->insertOrIgnore($rows);
                }
            });
    }

    private function backfillWordpressCategoryMemberships(): void
    {
        if (! Schema::hasTable('wordpress_import_records')) {
            return;
        }

        [$categoryIdByWordpressId, $categoryIdBySlug] = $this->wordpressCategoryMaps();

        if ($categoryIdByWordpressId === [] && $categoryIdBySlug === []) {
            return;
        }

        $rows = [];
        $now = now();

        DB::table('wordpress_import_records')
            ->where('wordpress_type', 'post')
            ->where('importable_type', 'App\\Models\\Article')
            ->whereNotNull('importable_id')
            ->orderBy('id')
            ->select(['id', 'importable_id', 'source_payload'])
            ->chunkById(250, function ($records) use (&$rows, $now, $categoryIdByWordpressId, $categoryIdBySlug): void {
                foreach ($records as $record) {
                    $payload = $this->decodePayload($record->source_payload);
                    $categoryIds = $this->categoryIdsFromPayload($payload, $categoryIdByWordpressId, $categoryIdBySlug);

                    foreach ($categoryIds as $categoryId) {
                        $rows[] = [
                            'article_id' => $record->importable_id,
                            'category_id' => $categoryId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if (count($rows) >= 1000) {
                        DB::table('article_category')->insertOrIgnore($rows);
                        $rows = [];
                    }
                }
            });

        if ($rows !== []) {
            DB::table('article_category')->insertOrIgnore($rows);
        }
    }

    /**
     * @return array{0: array<int, int>, 1: array<string, int>}
     */
    private function wordpressCategoryMaps(): array
    {
        $categoryIdByWordpressId = [];
        $categoryIdBySlug = [];

        if (Schema::hasTable('wordpress_import_mappings')) {
            DB::table('wordpress_import_mappings')
                ->where('source_type', 'category')
                ->where('target_type', Category::class)
                ->whereNotNull('target_id')
                ->select(['id', 'wordpress_id', 'source_slug', 'target_id'])
                ->orderBy('id')
                ->chunkById(500, function ($mappings) use (&$categoryIdByWordpressId, &$categoryIdBySlug): void {
                    foreach ($mappings as $mapping) {
                        $targetId = (int) $mapping->target_id;
                        $wordpressId = (int) $mapping->wordpress_id;

                        if ($wordpressId > 0) {
                            $categoryIdByWordpressId[$wordpressId] = $targetId;
                        }

                        foreach ($this->slugCandidates((string) $mapping->source_slug) as $candidate) {
                            $categoryIdBySlug[$candidate] = $targetId;
                        }
                    }
                });
        }

        DB::table('wordpress_import_records')
            ->where('wordpress_type', 'category')
            ->where('importable_type', Category::class)
            ->whereNotNull('importable_id')
            ->select(['id', 'wordpress_id', 'importable_id', 'source_payload'])
            ->orderBy('id')
            ->chunkById(500, function ($records) use (&$categoryIdByWordpressId, &$categoryIdBySlug): void {
                foreach ($records as $record) {
                    $categoryId = (int) $record->importable_id;
                    $wordpressId = (int) $record->wordpress_id;

                    if ($wordpressId > 0) {
                        $categoryIdByWordpressId[$wordpressId] ??= $categoryId;
                    }

                    $payload = $this->decodePayload($record->source_payload);

                    foreach ($this->slugCandidates((string) ($payload['slug'] ?? '')) as $candidate) {
                        $categoryIdBySlug[$candidate] ??= $categoryId;
                    }
                }
            });

        return [$categoryIdByWordpressId, $categoryIdBySlug];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $categoryIdByWordpressId
     * @param  array<string, int>  $categoryIdBySlug
     * @return array<int, int>
     */
    private function categoryIdsFromPayload(array $payload, array $categoryIdByWordpressId, array $categoryIdBySlug): array
    {
        $categoryIds = [];

        foreach ((array) ($payload['categories'] ?? []) as $wordpressId) {
            $wordpressId = (int) $wordpressId;

            if (isset($categoryIdByWordpressId[$wordpressId])) {
                $categoryIds[] = $categoryIdByWordpressId[$wordpressId];
            }
        }

        foreach ($this->wordpressCategoryTerms($payload) as $term) {
            $wordpressId = (int) ($term['id'] ?? 0);

            if (isset($categoryIdByWordpressId[$wordpressId])) {
                $categoryIds[] = $categoryIdByWordpressId[$wordpressId];

                continue;
            }

            foreach ($this->slugCandidates((string) ($term['slug'] ?? '')) as $candidate) {
                if (isset($categoryIdBySlug[$candidate])) {
                    $categoryIds[] = $categoryIdBySlug[$candidate];

                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($categoryIds)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function wordpressCategoryTerms(array $payload): array
    {
        $terms = [];

        foreach ((array) data_get($payload, '_embedded.wp:term', []) as $termGroup) {
            foreach ((array) $termGroup as $term) {
                if (is_array($term) && ($term['taxonomy'] ?? null) === 'category') {
                    $terms[] = $term;
                }
            }
        }

        return $terms;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, string>
     */
    private function slugCandidates(string $slug): array
    {
        $decoded = rawurldecode($slug);

        return collect([
            $slug,
            Str::lower($slug),
            str_replace('_', '-', $slug),
            $decoded,
            Str::lower($decoded),
            rawurlencode($decoded),
            Str::lower(rawurlencode($decoded)),
            Str::slug($decoded),
        ])
            ->map(fn (string $candidate): string => trim($candidate))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
};
