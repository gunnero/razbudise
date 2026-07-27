<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SOURCE_WORDPRESS_ID = 3363;

    private const SOURCE_SLUG = 'investicii-i-finansii';

    private const TARGET_SLUG = 'finansii';

    public function up(): void
    {
        $target = DB::table('categories')->where('slug', self::TARGET_SLUG)->first();
        $source = DB::table('categories')->where('slug', self::SOURCE_SLUG)->first();

        if (! $target) {
            return;
        }

        DB::table('wordpress_import_mappings')
            ->where('source_type', 'category')
            ->where('source_slug', self::SOURCE_SLUG)
            ->where(function ($query): void {
                $query->whereNull('wordpress_id')
                    ->orWhere('wordpress_id', '!=', self::SOURCE_WORDPRESS_ID);
            })
            ->delete();

        DB::table('wordpress_import_mappings')->updateOrInsert(
            [
                'source_type' => 'category',
                'wordpress_id' => self::SOURCE_WORDPRESS_ID,
            ],
            [
                'source_slug' => self::SOURCE_SLUG,
                'target_type' => Category::class,
                'target_id' => $target->id,
                'notes' => 'Merge old WordPress investment finance category into canonical Финансии.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        if ($source) {
            DB::table('articles')
                ->where('category_id', $source->id)
                ->update([
                    'category_id' => $target->id,
                    'updated_at' => now(),
                ]);

            DB::table('categories')
                ->where('id', $source->id)
                ->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
        }

        DB::table('wordpress_import_records')
            ->where('wordpress_type', 'category')
            ->where('wordpress_id', self::SOURCE_WORDPRESS_ID)
            ->update([
                'importable_type' => Category::class,
                'importable_id' => $target->id,
                'new_url' => '/category/'.self::TARGET_SLUG,
                'status' => 'imported',
                'updated_at' => now(),
            ]);

        foreach ($this->redirectSources() as $sourceUrl) {
            DB::table('redirects')->updateOrInsert(
                ['source_url' => $sourceUrl],
                [
                    'target_url' => '/category/'.self::TARGET_SLUG,
                    'status_code' => 301,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        $source = DB::table('categories')->where('slug', self::SOURCE_SLUG)->first();

        DB::table('wordpress_import_mappings')
            ->where('source_type', 'category')
            ->where('wordpress_id', self::SOURCE_WORDPRESS_ID)
            ->delete();

        DB::table('redirects')
            ->whereIn('source_url', $this->redirectSources())
            ->delete();

        if ($source) {
            DB::table('categories')
                ->where('id', $source->id)
                ->update([
                    'is_active' => true,
                    'updated_at' => now(),
                ]);

            DB::table('wordpress_import_records')
                ->where('wordpress_type', 'category')
                ->where('wordpress_id', self::SOURCE_WORDPRESS_ID)
                ->update([
                    'importable_type' => Category::class,
                    'importable_id' => $source->id,
                    'new_url' => '/category/'.self::SOURCE_SLUG,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function redirectSources(): array
    {
        return [
            '/category/'.self::SOURCE_SLUG,
            '/kategorija/'.self::SOURCE_SLUG,
            'https://razbudise.mk/category/'.self::SOURCE_SLUG,
            'https://razbudise.mk/kategorija/'.self::SOURCE_SLUG,
        ];
    }
};
