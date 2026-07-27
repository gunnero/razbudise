<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\SeoMetadata;
use App\Models\Tag;
use App\Services\Seo\SeoMetadataRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoMetadataRepairServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_imported_article_metadata_can_dry_run_and_apply_canonical_fixes(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001/']);

        $article = Article::factory()->published()->create([
            'title' => 'Стара WordPress објава',
            'slug' => 'stara-wordpress-objava',
            'excerpt' => 'Краток опис за старата објава.',
            'old_wordpress_id' => 123,
            'old_url' => 'https://razbudise.mk/stara-wordpress-objava/',
        ]);

        $article->seoMetadata()->create([
            'seo_title' => null,
            'meta_description' => null,
            'canonical_url' => 'https://razbudise.mk/stara-wordpress-objava/',
        ]);

        $dryRun = app(SeoMetadataRepairService::class)->repairArticles(apply: false, onlyImported: true);

        $this->assertSame([
            'articles_checked' => 1,
            'article_excerpts_repaired' => 0,
            'metadata_created' => 0,
            'metadata_updated' => 1,
            'canonical_repaired' => 1,
            'og_images_repaired' => 0,
            'dry_run' => true,
        ], $dryRun);
        $this->assertDatabaseHas(SeoMetadata::class, [
            'seoable_type' => Article::class,
            'seoable_id' => $article->id,
            'canonical_url' => 'https://razbudise.mk/stara-wordpress-objava/',
        ]);

        $applied = app(SeoMetadataRepairService::class)->repairArticles(apply: true, onlyImported: true);

        $this->assertSame([
            'articles_checked' => 1,
            'article_excerpts_repaired' => 0,
            'metadata_created' => 0,
            'metadata_updated' => 1,
            'canonical_repaired' => 1,
            'og_images_repaired' => 0,
            'dry_run' => false,
        ], $applied);
        $this->assertDatabaseHas(SeoMetadata::class, [
            'seoable_type' => Article::class,
            'seoable_id' => $article->id,
            'seo_title' => 'Стара WordPress објава',
            'meta_description' => 'Краток опис за старата објава.',
            'canonical_url' => 'http://localhost:3001/stara-wordpress-objava',
        ]);
    }

    public function test_repair_imported_article_metadata_derives_excerpt_description_and_og_image_from_body(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001/']);

        $image = MediaAsset::factory()->create();
        $article = Article::factory()->published()->create([
            'title' => 'Убава приказна од Битола',
            'slug' => 'ubava-prikazna-od-bitola',
            'excerpt' => null,
            'body_html' => '<p>Ова е чист опис извлечен од увезениот WordPress текст со доволно информации за читателите.</p><p>[post_tags]</p>',
            'featured_image_id' => $image->id,
            'old_wordpress_id' => 456,
        ]);

        $dryRun = app(SeoMetadataRepairService::class)->repairArticles(apply: false, onlyImported: true);

        $this->assertSame([
            'articles_checked' => 1,
            'article_excerpts_repaired' => 1,
            'metadata_created' => 1,
            'metadata_updated' => 0,
            'canonical_repaired' => 1,
            'og_images_repaired' => 1,
            'dry_run' => true,
        ], $dryRun);
        $this->assertNull($article->fresh()->excerpt);
        $this->assertDatabaseMissing(SeoMetadata::class, [
            'seoable_type' => Article::class,
            'seoable_id' => $article->id,
        ]);

        $applied = app(SeoMetadataRepairService::class)->repairArticles(apply: true, onlyImported: true);

        $this->assertSame([
            'articles_checked' => 1,
            'article_excerpts_repaired' => 1,
            'metadata_created' => 1,
            'metadata_updated' => 0,
            'canonical_repaired' => 1,
            'og_images_repaired' => 1,
            'dry_run' => false,
        ], $applied);

        $expectedDescription = 'Ова е чист опис извлечен од увезениот WordPress текст со доволно информации за читателите.';

        $this->assertSame($expectedDescription, $article->fresh()->excerpt);
        $this->assertDatabaseHas(SeoMetadata::class, [
            'seoable_type' => Article::class,
            'seoable_id' => $article->id,
            'seo_title' => 'Убава приказна од Битола',
            'meta_description' => $expectedDescription,
            'canonical_url' => 'http://localhost:3001/ubava-prikazna-od-bitola',
            'og_image_id' => $image->id,
        ]);
    }

    public function test_repair_command_reports_summary_and_defaults_to_dry_run(): void
    {
        Article::factory()->published()->create([
            'title' => 'Објава без metadata',
            'slug' => 'objava-bez-metadata',
        ]);

        $this->artisan('seo:repair-metadata')
            ->expectsOutput('Mode: dry run')
            ->expectsOutput('Articles checked: 1')
            ->expectsOutput('Article excerpts repaired: 0')
            ->expectsOutput('Public pages checked: 2')
            ->expectsOutput('Metadata created: 3')
            ->expectsOutput('Metadata updated: 0')
            ->expectsOutput('Canonical URLs repaired: 3')
            ->expectsOutput('OG images repaired: 0')
            ->assertSuccessful();

        $this->assertDatabaseMissing(SeoMetadata::class, [
            'seoable_type' => Article::class,
            'canonical_url' => 'http://localhost:3001/objava-bez-metadata',
        ]);
    }

    public function test_repair_public_pages_creates_macedonian_seo_fallbacks(): void
    {
        config(['app.frontend_url' => 'http://localhost:3001/']);

        $category = Category::factory()->create([
            'name' => 'Живот',
            'slug' => 'zivot',
            'description' => null,
            'is_active' => true,
        ]);

        $tag = Tag::query()->create([
            'name' => 'Настани',
            'slug' => 'nastani',
        ]);

        $author = Author::factory()->create([
            'name' => 'Разбуди Се Редакција',
            'slug' => 'razbudi-se-redakcija',
            'short_bio' => null,
            'is_active' => true,
        ]);

        Author::factory()->create([
            'name' => 'Скриен автор',
            'slug' => 'skrien-avtor',
            'short_bio' => null,
            'is_active' => false,
        ]);

        $dryRun = app(SeoMetadataRepairService::class)->repairPublicPages(apply: false);

        $this->assertSame([
            'pages_checked' => 3,
            'metadata_created' => 3,
            'metadata_updated' => 0,
            'canonical_repaired' => 3,
            'dry_run' => true,
        ], $dryRun);

        $applied = app(SeoMetadataRepairService::class)->repairPublicPages(apply: true);

        $this->assertSame([
            'pages_checked' => 3,
            'metadata_created' => 3,
            'metadata_updated' => 0,
            'canonical_repaired' => 3,
            'dry_run' => false,
        ], $applied);

        $this->assertDatabaseHas(SeoMetadata::class, [
            'seoable_type' => Category::class,
            'seoable_id' => $category->id,
            'seo_title' => 'Живот - Разбуди се',
            'meta_description' => 'Животни приказни, навики и инспирација за секојдневие со повеќе радост, љубопитност и добри идеи.',
            'canonical_url' => 'http://localhost:3001/category/zivot',
        ]);
        $this->assertDatabaseHas(SeoMetadata::class, [
            'seoable_type' => Tag::class,
            'seoable_id' => $tag->id,
            'seo_title' => '#Настани - Разбуди се',
            'meta_description' => 'Сите објави означени со Настани на Разбуди се.',
            'canonical_url' => 'http://localhost:3001/tag/nastani',
        ]);
        $this->assertDatabaseHas(SeoMetadata::class, [
            'seoable_type' => Author::class,
            'seoable_id' => $author->id,
            'seo_title' => 'Разбуди Се Редакција - Разбуди се',
            'meta_description' => 'Текстови и авторски објави од Разбуди Се Редакција на Разбуди се.',
            'canonical_url' => 'http://localhost:3001/profil/razbudi-se-redakcija',
        ]);
        $this->assertDatabaseMissing(SeoMetadata::class, [
            'seoable_type' => Author::class,
            'seo_title' => 'Скриен автор - Разбуди се',
        ]);
    }
}
