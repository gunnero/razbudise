<?php

use App\Models\Category;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('categories')->exists()) {
            return;
        }

        foreach ($this->categories() as $category) {
            DB::table('categories')->updateOrInsert(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'meta_description' => $category['meta_description'],
                    'is_active' => true,
                    'sort_order' => $category['sort_order'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        foreach ($this->mappings() as $mapping) {
            $target = DB::table('categories')->where('slug', $mapping['target_slug'])->first();

            if (! $target) {
                continue;
            }

            DB::table('wordpress_import_mappings')
                ->where('source_type', 'category')
                ->where('source_slug', $mapping['source_slug'])
                ->where(function ($query) use ($mapping): void {
                    $query->whereNull('wordpress_id')
                        ->orWhere('wordpress_id', '!=', $mapping['wordpress_id']);
                })
                ->delete();

            DB::table('wordpress_import_mappings')->updateOrInsert(
                [
                    'source_type' => 'category',
                    'wordpress_id' => $mapping['wordpress_id'],
                ],
                [
                    'source_slug' => $mapping['source_slug'],
                    'target_type' => Category::class,
                    'target_id' => $target->id,
                    'notes' => $mapping['notes'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );

            DB::table('wordpress_import_records')
                ->where('wordpress_type', 'category')
                ->where('wordpress_id', $mapping['wordpress_id'])
                ->update([
                    'importable_type' => Category::class,
                    'importable_id' => $target->id,
                    'new_url' => '/category/'.$mapping['target_slug'],
                    'status' => 'imported',
                    'updated_at' => now(),
                ]);

            $source = DB::table('categories')->where('slug', $mapping['source_slug'])->first();

            if ($source && $source->id !== $target->id) {
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

            foreach ($this->redirectSources($mapping['source_slug'], $mapping['target_slug']) as $sourceUrl) {
                DB::table('redirects')->updateOrInsert(
                    ['source_url' => $sourceUrl],
                    [
                        'target_url' => '/category/'.$mapping['target_slug'],
                        'status_code' => 301,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        $wordpressIds = collect($this->mappings())->pluck('wordpress_id')->all();
        $redirectSources = collect($this->mappings())
            ->flatMap(fn (array $mapping): array => $this->redirectSources($mapping['source_slug'], $mapping['target_slug']))
            ->all();

        DB::table('wordpress_import_mappings')
            ->where('source_type', 'category')
            ->whereIn('wordpress_id', $wordpressIds)
            ->delete();

        DB::table('redirects')
            ->whereIn('source_url', $redirectSources)
            ->delete();
    }

    /**
     * @return array<int, array{name: string, slug: string, description: string, meta_description: string, sort_order: int}>
     */
    private function categories(): array
    {
        return [
            ['name' => 'Видеата на Марјан', 'slug' => 'videata-na-marjan', 'description' => 'Видео приказни и избори од Марјан.', 'meta_description' => 'Видеата на Марјан со избор на локални и урбани приказни.', 'sort_order' => 32],
            ['name' => 'Мими Југо', 'slug' => 'mimi-yugo', 'description' => 'Автомобилски и патувачки приказни со Мими Југо.', 'meta_description' => 'Мими Југо носи лесни автомобилски приказни, патувања и локални авантури.', 'sort_order' => 34],
            ['name' => 'Нена Колумни', 'slug' => 'nena-kolumni', 'description' => 'Колумни и лични размислувања од Нена.', 'meta_description' => 'Нена Колумни со лични, искрени и културни размислувања.', 'sort_order' => 36],
            ['name' => 'Холивудски Трачарлуци', 'slug' => 'xolivudski-tracarluci', 'description' => 'Забавни вести и лесни приказни од поп културата.', 'meta_description' => 'Холивудски трачарлуци, поп култура и лесни забавни приказни.', 'sort_order' => 55],
            ['name' => 'Брчиме со Дуле', 'slug' => 'brchime-so-dule', 'description' => 'Локални разговори и видео приказни со Дуле.', 'meta_description' => 'Брчиме со Дуле со локални муабети, гостувања и видео приказни.', 'sort_order' => 125],
            ['name' => 'Во деловен кодекс', 'slug' => 'vo-deloven-kodeks', 'description' => 'Деловни приказни, компании и професионална култура.', 'meta_description' => 'Во деловен кодекс со деловни приказни, компании и професионални теми.', 'sort_order' => 135],
        ];
    }

    /**
     * @return array<int, array{wordpress_id: int, source_slug: string, target_slug: string, notes: string}>
     */
    private function mappings(): array
    {
        return [
            ['wordpress_id' => 136, 'source_slug' => '%d0%b6%d0%b8%d0%b2%d0%be%d1%82', 'target_slug' => 'zivot', 'notes' => 'Preserve old WordPress Живот category under canonical RazbudiSe slug.'],
            ['wordpress_id' => 145, 'source_slug' => 'hedonizam-i-gastronomija', 'target_slug' => 'hedonizam-i-gastronomija', 'notes' => 'Preserve old WordPress Хедонизам и Гастрономија category.'],
            ['wordpress_id' => 137, 'source_slug' => 'opusteno', 'target_slug' => 'opusteno', 'notes' => 'Preserve old WordPress Опуштено category.'],
            ['wordpress_id' => 1495, 'source_slug' => 'videata-na-marjan', 'target_slug' => 'videata-na-marjan', 'notes' => 'Preserve old WordPress Видеата на Марјан category.'],
            ['wordpress_id' => 1496, 'source_slug' => 'mimi-yugo', 'target_slug' => 'mimi-yugo', 'notes' => 'Preserve old WordPress Мими Југо category.'],
            ['wordpress_id' => 1497, 'source_slug' => 'nena-kolumni', 'target_slug' => 'nena-kolumni', 'notes' => 'Preserve old WordPress Нена Колумни category.'],
            ['wordpress_id' => 138, 'source_slug' => 'psihologija', 'target_slug' => 'psihologija', 'notes' => 'Preserve old WordPress Психологија category.'],
            ['wordpress_id' => 139, 'source_slug' => 'modna-soba', 'target_slug' => 'modna-soba', 'notes' => 'Preserve old WordPress Модна Соба category.'],
            ['wordpress_id' => 3020, 'source_slug' => '%d1%85%d0%be%d0%bb%d0%b8%d0%b2%d1%83%d0%b4%d1%81%d0%ba%d0%b8-%d1%82%d1%80%d0%b0%d1%87%d0%b0%d1%80%d0%bb%d1%83%d1%86%d0%b8', 'target_slug' => 'xolivudski-tracarluci', 'notes' => 'Preserve old WordPress Холивудски Трачарлуци category.'],
            ['wordpress_id' => 140, 'source_slug' => '%d1%83%d1%80%d0%b1%d0%b0%d0%bd%d0%be', 'target_slug' => 'urbano', 'notes' => 'Preserve old WordPress Урбано category under canonical RazbudiSe slug.'],
            ['wordpress_id' => 144, 'source_slug' => 'intervju', 'target_slug' => 'intervju', 'notes' => 'Preserve old WordPress Интервју category.'],
            ['wordpress_id' => 141, 'source_slug' => 'dom-i-dizajn', 'target_slug' => 'dom-i-dizajn', 'notes' => 'Preserve old WordPress Дом и Дизајн category.'],
            ['wordpress_id' => 142, 'source_slug' => 'kniga', 'target_slug' => 'kniga', 'notes' => 'Preserve old WordPress Книга category.'],
            ['wordpress_id' => 143, 'source_slug' => 'art-time', 'target_slug' => 'art-time', 'notes' => 'Preserve old WordPress Art Time category.'],
            ['wordpress_id' => 180, 'source_slug' => '%d0%bd%d0%b0%d1%81%d1%82%d0%b0%d0%bd%d0%b8', 'target_slug' => 'nastani', 'notes' => 'Preserve old WordPress Настани category.'],
            ['wordpress_id' => 156, 'source_slug' => 'bitolski-muabeti', 'target_slug' => 'bitolski-muabeti', 'notes' => 'Preserve old WordPress Битолски Муабети category.'],
            ['wordpress_id' => 3206, 'source_slug' => 'brchime_so_dule', 'target_slug' => 'brchime-so-dule', 'notes' => 'Merge old WordPress Брчиме со Дуле underscore slug into canonical hyphenated slug.'],
            ['wordpress_id' => 2137, 'source_slug' => 'kolumni', 'target_slug' => 'kolumni', 'notes' => 'Preserve old WordPress Колумни category.'],
            ['wordpress_id' => 2745, 'source_slug' => '%d0%b2%d0%be-%d0%b4%d0%b5%d0%bb%d0%be%d0%b2%d0%b5%d0%bd-%d0%ba%d0%be%d0%b4%d0%b5%d0%ba%d1%81', 'target_slug' => 'vo-deloven-kodeks', 'notes' => 'Preserve old WordPress Во деловен кодекс category.'],
            ['wordpress_id' => 3363, 'source_slug' => 'investicii-i-finansii', 'target_slug' => 'finansii', 'notes' => 'Merge old WordPress investment finance category into canonical Финансии.'],
            ['wordpress_id' => 2915, 'source_slug' => '%d0%b2%d0%b5%d1%81%d1%82%d0%b8', 'target_slug' => 'vesti', 'notes' => 'Preserve old WordPress Вести category under canonical RazbudiSe slug.'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function redirectSources(string $sourceSlug, string $targetSlug): array
    {
        $sources = [
            '/kategorija/'.$sourceSlug,
            'https://razbudise.mk/kategorija/'.$sourceSlug,
        ];

        if ($sourceSlug !== $targetSlug) {
            $sources[] = '/category/'.$sourceSlug;
            $sources[] = 'https://razbudise.mk/category/'.$sourceSlug;
        }

        return $sources;
    }
};
