<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\Advertiser;
use App\Models\AdZone;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Menu;
use App\Models\SiteSetting;
use App\Models\TeamDepartment;
use App\Models\TeamMember;
use App\Models\TeamRole;
use App\Models\WordpressImportMapping;
use App\Services\Media\MediaQualityReportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InitialPlatformSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_platform_seed_data_is_idempotent_and_url_safe(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $expectedCategories = [
            'Живот' => 'zivot',
            'Хедонизам и Гастрономија' => 'hedonizam-i-gastronomija',
            'Опуштено' => 'opusteno',
            'Видеата на Марјан' => 'videata-na-marjan',
            'Мими Југо' => 'mimi-yugo',
            'Нена Колумни' => 'nena-kolumni',
            'Психологија' => 'psihologija',
            'Модна Соба' => 'modna-soba',
            'Холивудски Трачарлуци' => 'xolivudski-tracarluci',
            'Урбано' => 'urbano',
            'Интервју' => 'intervju',
            'Дом и Дизајн' => 'dom-i-dizajn',
            'Книга' => 'kniga',
            'Art Time' => 'art-time',
            'Настани' => 'nastani',
            'Битолски Муабети' => 'bitolski-muabeti',
            'Брчиме со Дуле' => 'brchime-so-dule',
            'Колумни' => 'kolumni',
            'Во деловен кодекс' => 'vo-deloven-kodeks',
            'Бизнис' => 'biznis',
            'Финансии' => 'finansii',
            'Вести' => 'vesti',
        ];

        $this->assertSame(22, Category::query()->count());
        $this->assertSame(34, AdZone::query()->count());
        $this->assertSame(1, Advertiser::query()->where('slug', 'razbudi-se-demo-ads')->count());
        $this->assertSame(1, AdCampaign::query()->where('name', 'RazbudiSe local demo campaign')->count());
        $this->assertSame(1, AdCreative::query()->where('name', 'Local demo billboard')->count());
        $this->assertSame(1, AdCreative::query()->where('name', 'RazbudiSe house fallback')->where('is_fallback', true)->count());
        $this->assertSame(34, AdCreative::query()->where('name', 'like', 'RazbudiSe house fallback - %')->where('is_fallback', true)->count());
        $this->assertSame(1, MediaAsset::query()->where('path', 'demo/ads/local-billboard.svg')->count());
        $this->assertSame(0, app(MediaQualityReportService::class)->summary()['totals']['issues']);
        $this->assertSame(1, Menu::query()->where('location', 'header')->count());
        $this->assertSame(1, Menu::query()->where('location', 'footer')->count());
        $headerItems = Menu::query()
            ->where('location', 'header')
            ->firstOrFail()
            ->items()
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->pluck('title')
            ->all();

        $this->assertSame([
            'Почетна',
            'Живот',
            'Урбано',
            'Битолски Муабети',
            'Колумни',
            'Во деловен кодекс',
            'Вести',
        ], $headerItems);

        $headerMenu = Menu::query()->where('location', 'header')->firstOrFail();
        $lifeChildren = $headerMenu->items()
            ->where('parent_id', $headerMenu->items()->where('title', 'Живот')->firstOrFail()->id)
            ->orderBy('sort_order')
            ->pluck('title')
            ->all();

        $this->assertSame([
            'Хедонизам и Гастрономија',
            'Опуштено',
            'Видеата на Марјан',
            'Мими Југо',
            'Нена Колумни',
            'Психологија',
            'Модна Соба',
            'Холивудски Трачарлуци',
        ], $lifeChildren);
        $footerItems = Menu::query()
            ->where('location', 'footer')
            ->firstOrFail()
            ->items()
            ->orderBy('sort_order')
            ->pluck('title')
            ->all();

        $this->assertSame([
            'Почетна',
            'Пребарување',
            'Автори',
        ], $footerItems);
        $this->assertDatabaseMissing('menu_items', [
            'menu_id' => Menu::query()->where('location', 'footer')->firstOrFail()->id,
            'title' => 'Настани',
        ]);
        $this->assertDatabaseMissing('menu_items', [
            'menu_id' => Menu::query()->where('location', 'footer')->firstOrFail()->id,
            'title' => 'Вести',
        ]);
        $this->assertSame(1, Author::query()->where('slug', 'razbudi-se-redakcija')->count());
        $this->assertSame(9, TeamMember::query()->count());
        $this->assertSame(13, TeamRole::query()->count());
        $this->assertSame(11, TeamDepartment::query()->count());
        $this->assertSame(1, TeamMember::query()
            ->where('slug', 'aleksandar-dimovski')
            ->where('display_title', 'Co-owner / CEO')
            ->where('status', 'active')
            ->count());
        $this->assertSame('ai', TeamMember::query()->where('slug', 'mila-andonova')->value('member_type'));
        $this->assertSame('leadership', TeamMember::query()
            ->where('slug', 'aleksandar-dimovski')
            ->firstOrFail()
            ->primaryDepartment
            ?->slug);
        $this->assertSame('writing', TeamMember::query()
            ->where('slug', 'mila-andonova')
            ->firstOrFail()
            ->primaryDepartment
            ?->slug);
        $this->assertSame('elena', TeamMember::query()
            ->where('slug', 'mila-andonova')
            ->firstOrFail()
            ->manager
            ?->slug);
        $this->assertContains(
            'AI Employee',
            TeamMember::query()->where('slug', 'mila-andonova')->firstOrFail()->roles()->pluck('name')->all(),
        );

        $financeCategory = Category::query()->where('slug', 'finansii')->firstOrFail();
        $this->assertDatabaseHas(WordpressImportMapping::class, [
            'source_type' => 'category',
            'wordpress_id' => 3363,
            'source_slug' => 'investicii-i-finansii',
            'target_type' => Category::class,
            'target_id' => $financeCategory->id,
        ]);

        foreach ($expectedCategories as $name => $slug) {
            $category = Category::query()->where('slug', $slug)->first();

            $this->assertNotNull($category, "Missing category [{$name}]");
            $this->assertSame($name, $category->name);
            $this->assertTrue($category->is_active);
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $category->slug);
        }

        $author = Author::query()->where('slug', 'razbudi-se-redakcija')->firstOrFail();

        $this->assertSame('Разбуди Се Редакција', $author->name);
        $this->assertSame('Редакција', $author->job_title);
        $this->assertTrue($author->is_active);

        $this->assertSame(['Разбуди се'], SiteSetting::query()->where('key', 'site_name')->value('value'));
        $this->assertSame(['https://razbudise.mk'], SiteSetting::query()->where('key', 'site_url')->value('value'));
        $this->assertSame(5, SiteSetting::query()->whereIn('key', [
            'site_name',
            'site_url',
            'default_meta_title',
            'default_meta_description',
            'default_og_image',
        ])->count());
        $billingProfile = SiteSetting::query()->where('key', 'organization_billing_profile')->firstOrFail();

        $this->assertSame('billing', $billingProfile->group);
        $this->assertFalse($billingProfile->is_public);
        $this->assertSame('Здружение за културно информирање РАЗБУДИ СЕ ДЕНЕС Битола', $billingProfile->value['legal_name']);
        $this->assertSame('7760388', $billingProfile->value['registration_number']);
        $this->assertSame('4002024573440', $billingProfile->value['tax_number']);
        $this->assertSame('210077603880171', $billingProfile->value['bank_account']);

        $this->getJson('/api/ads/home_top_billboard')
            ->assertOk()
            ->assertJsonPath('data.name', 'Local demo billboard')
            ->assertJsonPath('data.type', 'html')
            ->assertJsonPath('data.image', null)
            ->assertJsonPath('data.width', 970)
            ->assertJsonPath('data.height', 250);

        $this->getJson('/api/ads/newsletter_inline')
            ->assertOk()
            ->assertJsonPath('data.name', 'RazbudiSe house fallback - newsletter_inline')
            ->assertJsonPath('data.width', 600)
            ->assertJsonPath('data.height', 200)
            ->assertJsonPath('data.is_fallback', true);

        $demoImage = MediaAsset::query()->where('path', 'demo/'.$this->demoArticleSlug().'.png')->firstOrFail();
        $billboard = MediaAsset::query()->where('path', 'demo/ads/local-billboard.svg')->firstOrFail();

        $this->assertSame('external', $demoImage->disk);
        $this->assertSame('RazbudiSe demo archive', $demoImage->metadata['credit']);
        $this->assertSame('external', $billboard->disk);
        $this->assertSame('RazbudiSe demo archive', $billboard->metadata['credit']);
    }

    private function demoArticleSlug(): string
    {
        return 'bitola-se-budi-so-novi-kulturni-prikazni';
    }
}
