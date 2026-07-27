<?php

namespace Database\Seeders;

use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdPrice;
use App\Models\Advertiser;
use App\Models\AdZone;
use App\Models\Article;
use App\Models\ArticleBlock;
use App\Models\Author;
use App\Models\Category;
use App\Models\FinanceCategory;
use App\Models\HomepageSection;
use App\Models\MediaAsset;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\SponsoredContentDetail;
use App\Models\Tag;
use App\Models\TeamDepartment;
use App\Models\TeamMember;
use App\Models\TeamRole;
use App\Models\User;
use App\Models\WordpressImportMapping;
use App\Services\Ads\AdBillingProfileService;
use App\Services\Auth\RazbudiseRolePermissionMatrix;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRolesAndPermissions();
        $this->seedFinanceCategories();

        $categories = [
            ['name' => 'Живот', 'slug' => 'zivot', 'description' => 'Секојдневие, навики и инспирација.', 'meta_description' => 'Животни приказни, навики и инспирација за секојдневие со повеќе радост, љубопитност и добри идеи.', 'sort_order' => 10],
            ['name' => 'Хедонизам и Гастрономија', 'slug' => 'hedonizam-i-gastronomija', 'description' => 'Храна, пијалаци, вкусови и уживање.', 'meta_description' => 'Хедонизам, гастрономија, вкусови и места за уживање низ Македонија и регионот.', 'sort_order' => 20],
            ['name' => 'Опуштено', 'slug' => 'opusteno', 'description' => 'Лесни приказни, идеи и моменти за одмор.', 'meta_description' => 'Опуштени приказни, лесни теми и мали паузи за читање кога ти треба здив од денот.', 'sort_order' => 30],
            ['name' => 'Видеата на Марјан', 'slug' => 'videata-na-marjan', 'description' => 'Видео приказни и избори од Марјан.', 'meta_description' => 'Видеата на Марјан со избор на локални и урбани приказни.', 'sort_order' => 32],
            ['name' => 'Мими Југо', 'slug' => 'mimi-yugo', 'description' => 'Автомобилски и патувачки приказни со Мими Југо.', 'meta_description' => 'Мими Југо носи лесни автомобилски приказни, патувања и локални авантури.', 'sort_order' => 34],
            ['name' => 'Нена Колумни', 'slug' => 'nena-kolumni', 'description' => 'Колумни и лични размислувања од Нена.', 'meta_description' => 'Нена Колумни со лични, искрени и културни размислувања.', 'sort_order' => 36],
            ['name' => 'Психологија', 'slug' => 'psihologija', 'description' => 'Ментално здравје, односи и личен развој.', 'meta_description' => 'Психологија, односи, ментално здравје и личен развој низ разбирливи и топли текстови.', 'sort_order' => 40],
            ['name' => 'Модна Соба', 'slug' => 'modna-soba', 'description' => 'Мода, стил и локални модни приказни.', 'meta_description' => 'Мода, стил, локални креативци и модни приказни од македонската и регионалната сцена.', 'sort_order' => 50],
            ['name' => 'Холивудски Трачарлуци', 'slug' => 'xolivudski-tracarluci', 'description' => 'Забавни вести и лесни приказни од поп културата.', 'meta_description' => 'Холивудски трачарлуци, поп култура и лесни забавни приказни.', 'sort_order' => 55],
            ['name' => 'Урбано', 'slug' => 'urbano', 'description' => 'Градски живот, трендови и урбана култура.', 'meta_description' => 'Урбана култура, градски живот, трендови и приказни за луѓето и местата што го движат градот.', 'sort_order' => 60],
            ['name' => 'Интервју', 'slug' => 'intervju', 'description' => 'Разговори со луѓе кои вреди да се слушнат.', 'meta_description' => 'Интервјуа и разговори со луѓе кои создаваат, размислуваат и оставаат трага.', 'sort_order' => 70],
            ['name' => 'Дом и Дизајн', 'slug' => 'dom-i-dizajn', 'description' => 'Ентериер, архитектура и идеи за домот.', 'meta_description' => 'Дом, ентериер, архитектура, дизајн и практични идеи за простор со карактер.', 'sort_order' => 80],
            ['name' => 'Книга', 'slug' => 'kniga', 'description' => 'Книги, автори, читање и книжевни препораки.', 'meta_description' => 'Книги, автори, читање, книжевни препораки и приказни за љубителите на литературата.', 'sort_order' => 90],
            ['name' => 'Art Time', 'slug' => 'art-time', 'description' => 'Уметност, сцена, изложби и креативни луѓе.', 'meta_description' => 'Art Time носи уметност, изложби, сцена и креативни луѓе на едно место.', 'sort_order' => 100],
            ['name' => 'Настани', 'slug' => 'nastani', 'description' => 'Најави, случувања и културен календар.', 'meta_description' => 'Настани, најави, случувања и културен календар за градски и локални приказни.', 'sort_order' => 110],
            ['name' => 'Битолски Муабети', 'slug' => 'bitolski-muabeti', 'description' => 'Локални битолски приказни, луѓе и места.', 'meta_description' => 'Битолски муабети, локални приказни, луѓе, места и моменти од Битола.', 'sort_order' => 120],
            ['name' => 'Брчиме со Дуле', 'slug' => 'brchime-so-dule', 'description' => 'Локални разговори и видео приказни со Дуле.', 'meta_description' => 'Брчиме со Дуле со локални муабети, гостувања и видео приказни.', 'sort_order' => 125],
            ['name' => 'Колумни', 'slug' => 'kolumni', 'description' => 'Авторски ставови, размислувања и коментари.', 'meta_description' => 'Колумни, авторски ставови, размислувања и коментари за темите што вреди да се отворат.', 'sort_order' => 130],
            ['name' => 'Во деловен кодекс', 'slug' => 'vo-deloven-kodeks', 'description' => 'Деловни приказни, компании и професионална култура.', 'meta_description' => 'Во деловен кодекс со деловни приказни, компании и професионални теми.', 'sort_order' => 135],
            ['name' => 'Бизнис', 'slug' => 'biznis', 'description' => 'Претприемништво, компании и деловни приказни.', 'meta_description' => 'Бизнис приказни, претприемништво, компании и идеи што ја движат деловната сцена.', 'sort_order' => 140],
            ['name' => 'Финансии', 'slug' => 'finansii', 'description' => 'Лични финансии, економија и финансиски теми.', 'meta_description' => 'Финансии, економија, лични финансии и корисни теми за паметни одлуки.', 'sort_order' => 150],
            ['name' => 'Вести', 'slug' => 'vesti', 'description' => 'Актуелни информации и кратки вести.', 'meta_description' => 'Вести, актуелности и кратки информации од Македонија, регионот и локалната сцена.', 'sort_order' => 160],
        ];

        foreach ($categories as $category) {
            Category::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['is_active' => true],
            );
        }

        $demoAuthor = Author::query()->updateOrCreate(
            ['slug' => 'razbudi-se-redakcija'],
            [
                'name' => 'Разбуди Се Редакција',
                'short_bio' => 'Демо автор за почетна локална содржина.',
                'full_bio' => 'Овој профил служи како почетен автор за тестирање на CMS структурата.',
                'job_title' => 'Редакција',
                'show_email' => false,
                'is_active' => true,
                'sort_order' => 1,
                'seo_title' => 'Разбуди Се Редакција',
                'meta_description' => 'Авторски профил за редакцијата на RazbudiSe.',
            ],
        );

        $this->seedTeamCrew();

        $categoryModels = Category::query()
            ->whereIn('slug', collect($categories)->pluck('slug'))
            ->get()
            ->keyBy('slug');

        foreach ($this->originalWordpressCategoryMappings() as $mapping) {
            $targetCategory = $categoryModels->get($mapping['target_slug']);

            if (! $targetCategory) {
                continue;
            }

            WordpressImportMapping::query()->updateOrCreate(
                [
                    'source_type' => 'category',
                    'wordpress_id' => $mapping['wordpress_id'],
                ],
                [
                    'source_slug' => $mapping['source_slug'],
                    'target_type' => Category::class,
                    'target_id' => $targetCategory->id,
                    'notes' => $mapping['notes'],
                ],
            );
        }

        $tagModels = collect($this->tags())
            ->mapWithKeys(fn (array $tag): array => [
                $tag['slug'] => Tag::query()->updateOrCreate(
                    ['slug' => $tag['slug']],
                    ['name' => $tag['name']],
                ),
            ]);

        $demoArticles = $this->demoArticles();
        $demoMediaAssets = collect($demoArticles)
            ->mapWithKeys(fn (array $article): array => [
                $article['slug'] => MediaAsset::query()->updateOrCreate(
                    ['path' => "demo/{$article['slug']}.png"],
                    [
                        'disk' => 'external',
                        'url' => "/brand/demo/{$article['slug']}.png",
                        'mime_type' => 'image/png',
                        'alt_text' => $article['title'],
                        'caption' => $article['excerpt'],
                        'width' => 1200,
                        'height' => 675,
                        'size_bytes' => 245000,
                        'metadata' => [
                            'source' => 'demo_seed',
                            'credit' => 'RazbudiSe demo archive',
                            'frontend_public_path' => "/brand/demo/{$article['slug']}.png",
                        ],
                    ],
                ),
            ]);

        $headerMenu = Menu::query()->updateOrCreate(
            ['location' => 'header'],
            [
                'name' => 'Главна навигација',
                'is_active' => true,
            ],
        );

        foreach ($this->headerMenuItems() as $item) {
            $this->upsertMenuItem($headerMenu, $item, $categoryModels);
        }

        $headerMenu->items()->whereIn('title', ['Автори', 'Финансии'])->delete();

        $footerMenu = Menu::query()->updateOrCreate(
            ['location' => 'footer'],
            [
                'name' => 'Подножје навигација',
                'is_active' => true,
            ],
        );

        foreach ($this->footerMenuItems() as $item) {
            MenuItem::query()->updateOrCreate(
                [
                    'menu_id' => $footerMenu->id,
                    'title' => $item['title'],
                ],
                [
                    'parent_id' => null,
                    'link_type' => 'custom',
                    'url' => $item['url'],
                    'linkable_type' => null,
                    'linkable_id' => null,
                    'opens_in_new_tab' => false,
                    'is_active' => true,
                    'sort_order' => $item['sort_order'],
                ],
            );
        }

        $footerMenu->items()
            ->whereIn('title', ['Настани', 'Вести'])
            ->delete();

        foreach ($demoArticles as $article) {
            $articleModel = Article::query()->updateOrCreate(
                ['slug' => $article['slug']],
                [
                    'title' => $article['title'],
                    'excerpt' => $article['excerpt'],
                    'body_html' => $article['body_html'],
                    'status' => 'published',
                    'type' => $article['type'],
                    'author_id' => $demoAuthor->id,
                    'category_id' => $categoryModels[$article['category_slug']]?->id,
                    'featured_image_id' => $demoMediaAssets[$article['slug']]?->id,
                    'published_at' => now()->subYears(5)->subDays($article['days_ago']),
                    'scheduled_at' => null,
                    'is_sponsored' => $article['is_sponsored'] ?? false,
                    'is_ai_generated' => false,
                    'is_ai_assisted' => false,
                ],
            );

            $articleModel->tags()->sync(
                collect($article['tag_slugs'] ?? [])
                    ->map(fn (string $slug) => $tagModels[$slug]?->id)
                    ->filter()
                    ->values(),
            );

            foreach ($article['blocks'] ?? [] as $block) {
                ArticleBlock::query()->updateOrCreate(
                    [
                        'article_id' => $articleModel->id,
                        'sort_order' => $block['sort_order'],
                    ],
                    [
                        'type' => $block['type'],
                        'data' => $block['data'],
                    ],
                );
            }

            if (isset($article['sponsored_content'])) {
                SponsoredContentDetail::query()->updateOrCreate(
                    ['article_id' => $articleModel->id],
                    $article['sponsored_content'],
                );
            }
        }

        foreach ($this->homepageSections() as $section) {
            HomepageSection::query()->updateOrCreate(
                ['key' => $section['key']],
                $section + ['is_active' => true],
            );
        }

        foreach ($this->adZones() as $zone) {
            AdZone::query()->updateOrCreate(
                ['key' => $zone['key']],
                $zone + ['is_active' => true],
            );
        }

        $this->seedAdPrices();
        $this->seedDemoAds();

        foreach ($this->siteSettings() as $setting) {
            SiteSetting::query()->updateOrCreate(
                ['key' => $setting['key']],
                $setting,
            );
        }

        $this->seedLocalAdminUser();
    }

    private function seedRolesAndPermissions(): void
    {
        app(RazbudiseRolePermissionMatrix::class)->sync();
    }

    private function seedFinanceCategories(): void
    {
        $categories = [
            ['name' => 'Хостинг', 'slug' => 'xosting', 'type' => 'expense', 'sort_order' => 10],
            ['name' => 'Домени', 'slug' => 'domeni', 'type' => 'expense', 'sort_order' => 20],
            ['name' => 'Софтвер', 'slug' => 'softver', 'type' => 'expense', 'sort_order' => 30],
            ['name' => 'Реклама и маркетинг', 'slug' => 'reklama-i-marketing', 'type' => 'expense', 'sort_order' => 40],
            ['name' => 'Дизајн', 'slug' => 'dizajn', 'type' => 'expense', 'sort_order' => 50],
            ['name' => 'Фотографија', 'slug' => 'fotografija', 'type' => 'expense', 'sort_order' => 60],
            ['name' => 'Автори и соработници', 'slug' => 'avtori-i-sorabotnici', 'type' => 'expense', 'sort_order' => 70],
            ['name' => 'Патни трошоци', 'slug' => 'patni-trosoci', 'type' => 'expense', 'sort_order' => 80],
            ['name' => 'Опрема', 'slug' => 'oprema', 'type' => 'expense', 'sort_order' => 90],
            ['name' => 'Банкарски трошоци', 'slug' => 'bankarski-trosoci', 'type' => 'expense', 'sort_order' => 100],
            ['name' => 'Канцеларија', 'slug' => 'kancelarija', 'type' => 'expense', 'sort_order' => 110],
            ['name' => 'Рекламирање', 'slug' => 'reklamiranje', 'type' => 'income', 'sort_order' => 120],
            ['name' => 'Спонзорирана содржина', 'slug' => 'sponzorirana-sodrzina', 'type' => 'income', 'sort_order' => 130],
            ['name' => 'ПР текст', 'slug' => 'pr-tekst', 'type' => 'income', 'sort_order' => 140],
            ['name' => 'Услуги', 'slug' => 'uslugi', 'type' => 'income', 'sort_order' => 150],
            ['name' => 'Друго', 'slug' => 'drugo', 'type' => 'both', 'sort_order' => 160],
        ];

        foreach ($categories as $category) {
            FinanceCategory::query()->updateOrCreate(
                ['slug' => $category['slug']],
                $category + ['description' => null, 'is_active' => true],
            );
        }
    }

    private function seedTeamCrew(): void
    {
        $departments = $this->seedTeamDepartments();
        $roles = collect([
            ['name' => 'Co-owner', 'slug' => 'co-owner', 'group_key' => 'owners', 'sort_order' => 10],
            ['name' => 'Editor-in-Chief', 'slug' => 'editor-in-chief', 'group_key' => 'leadership', 'sort_order' => 20],
            ['name' => 'Managing Editor', 'slug' => 'managing-editor', 'group_key' => 'leadership', 'sort_order' => 30],
            ['name' => 'Editor', 'slug' => 'editor', 'group_key' => 'editorial', 'sort_order' => 40],
            ['name' => 'Author', 'slug' => 'author', 'group_key' => 'authors', 'sort_order' => 50],
            ['name' => 'SEO', 'slug' => 'seo', 'group_key' => 'seo', 'sort_order' => 60],
            ['name' => 'Marketing', 'slug' => 'marketing', 'group_key' => 'marketing', 'sort_order' => 70],
            ['name' => 'Social Media', 'slug' => 'social-media', 'group_key' => 'social_media', 'sort_order' => 80],
            ['name' => 'AI Employee', 'slug' => 'ai-employee', 'group_key' => 'ai_crew', 'sort_order' => 90],
            ['name' => 'Contributor', 'slug' => 'contributor', 'group_key' => 'editorial', 'sort_order' => 100],
            ['name' => 'Research', 'slug' => 'research', 'group_key' => 'editorial', 'sort_order' => 110],
            ['name' => 'Fact Checker', 'slug' => 'fact-checker', 'group_key' => 'editorial', 'sort_order' => 120],
            ['name' => 'Creative / Design', 'slug' => 'creative-design', 'group_key' => 'creative_design', 'sort_order' => 130],
        ])->mapWithKeys(fn (array $role): array => [
            $role['slug'] => TeamRole::query()->updateOrCreate(
                ['slug' => $role['slug']],
                $role + [
                    'description' => null,
                    'status' => 'active',
                ],
            ),
        ]);

        $authorIdsBySlug = Author::query()
            ->whereIn('slug', ['elena', 'ramona', 'mila-andonova'])
            ->pluck('id', 'slug');

        $teamMembers = collect();

        foreach ($this->teamMembers() as $member) {
            $teamMember = TeamMember::query()->updateOrCreate(
                ['slug' => $member['slug']],
                [
                    'name' => $member['name'],
                    'display_title' => $member['display_title'],
                    'short_bio' => $member['short_bio'],
                    'full_bio' => $member['full_bio'],
                    'profile_image_id' => null,
                    'primary_department_id' => null,
                    'manager_team_member_id' => null,
                    'email' => null,
                    'member_type' => $member['member_type'],
                    'status' => 'active',
                    'sort_order' => $member['sort_order'],
                    'joined_at' => null,
                    'social_links' => [],
                    'metadata' => [
                        'seeded' => true,
                        'profile_image_guidance' => $member['member_type'] === 'ai'
                            ? 'Professional newsroom staff portrait; RazbudiSe-branded polo-shirt style supported later.'
                            : null,
                    ],
                    'author_id' => $member['author_slug']
                        ? ($authorIdsBySlug[$member['author_slug']] ?? null)
                        : null,
                ],
            );

            $teamMember->roles()->sync(
                collect($member['roles'])
                    ->map(fn (string $roleSlug): int => $roles[$roleSlug]->id)
                    ->all(),
            );

            $teamMembers[$member['slug']] = $teamMember;
        }

        foreach ($this->teamMembers() as $member) {
            $teamMembers[$member['slug']]->update([
                'primary_department_id' => $departments[$member['department_slug']]->id,
                'manager_team_member_id' => $member['manager_slug']
                    ? ($teamMembers[$member['manager_slug']]->id ?? null)
                    : null,
            ]);
        }

        foreach ($this->teamDepartmentManagers() as $departmentSlug => $managerSlug) {
            $departments[$departmentSlug]->update([
                'manager_team_member_id' => $teamMembers[$managerSlug]->id ?? null,
            ]);
        }
    }

    private function seedTeamDepartments(): Collection
    {
        $departments = collect($this->teamDepartments())
            ->mapWithKeys(fn (array $department): array => [
                $department['slug'] => TeamDepartment::query()->updateOrCreate(
                    ['slug' => $department['slug']],
                    [
                        'name' => $department['name'],
                        'description' => $department['description'],
                        'group_key' => $department['group_key'],
                        'status' => 'active',
                        'sort_order' => $department['sort_order'],
                        'parent_department_id' => null,
                        'manager_team_member_id' => null,
                        'metadata' => [
                            'seeded' => true,
                        ],
                    ],
                ),
            ]);

        foreach ($this->teamDepartments() as $department) {
            $departments[$department['slug']]->update([
                'parent_department_id' => $department['parent_slug']
                    ? ($departments[$department['parent_slug']]->id ?? null)
                    : null,
            ]);
        }

        return $departments;
    }

    private function teamDepartments(): array
    {
        return [
            ['name' => 'Leadership', 'slug' => 'leadership', 'description' => 'Ownership and strategic direction for RazbudiSe.', 'group_key' => 'leadership', 'sort_order' => 10, 'parent_slug' => null],
            ['name' => 'Editorial', 'slug' => 'editorial', 'description' => 'Editorial planning, standards, review, and publishing direction.', 'group_key' => 'editorial', 'sort_order' => 20, 'parent_slug' => 'leadership'],
            ['name' => 'Research', 'slug' => 'research', 'description' => 'Research support, source gathering, and background preparation.', 'group_key' => 'research', 'sort_order' => 30, 'parent_slug' => 'editorial'],
            ['name' => 'Writing', 'slug' => 'writing', 'description' => 'Original writing, drafting, and author-led story development.', 'group_key' => 'writing', 'sort_order' => 40, 'parent_slug' => 'editorial'],
            ['name' => 'Fact Checking', 'slug' => 'fact-checking', 'description' => 'Fact checks, consistency review, and editorial quality control.', 'group_key' => 'fact_checking', 'sort_order' => 50, 'parent_slug' => 'editorial'],
            ['name' => 'SEO', 'slug' => 'seo', 'description' => 'Search metadata, discovery quality, and SEO packaging.', 'group_key' => 'seo', 'sort_order' => 60, 'parent_slug' => 'editorial'],
            ['name' => 'Creative / Design', 'slug' => 'creative-design', 'description' => 'Visual direction, design support, and creative presentation.', 'group_key' => 'creative_design', 'sort_order' => 70, 'parent_slug' => 'leadership'],
            ['name' => 'Marketing', 'slug' => 'marketing', 'description' => 'Audience growth, campaigns, and brand communication.', 'group_key' => 'marketing', 'sort_order' => 80, 'parent_slug' => 'leadership'],
            ['name' => 'Social Media', 'slug' => 'social-media', 'description' => 'Social distribution, platform copy, and community packaging.', 'group_key' => 'social_media', 'sort_order' => 90, 'parent_slug' => 'marketing'],
            ['name' => 'Operations', 'slug' => 'operations', 'description' => 'Operational coordination and internal support.', 'group_key' => 'operations', 'sort_order' => 100, 'parent_slug' => 'leadership'],
            ['name' => 'AI Crew', 'slug' => 'ai-crew', 'description' => 'AI crew coordination across editorial, research, SEO, social, and quality workflows.', 'group_key' => 'ai_crew', 'sort_order' => 110, 'parent_slug' => 'leadership'],
        ];
    }

    private function teamDepartmentManagers(): array
    {
        return [
            'leadership' => 'aleksandar-dimovski',
            'editorial' => 'elena',
            'research' => 'martin',
            'writing' => 'elena',
            'fact-checking' => 'david',
            'seo' => 'viktor',
            'social-media' => 'sara',
            'ai-crew' => 'elena',
        ];
    }

    private function teamMembers(): array
    {
        return [
            [
                'name' => 'Aleksandar Dimovski',
                'slug' => 'aleksandar-dimovski',
                'display_title' => 'Co-owner / CEO',
                'short_bio' => 'Co-owner focused on product direction, publishing operations, and RazbudiSe growth.',
                'full_bio' => 'Placeholder bio for Aleksandar Dimovski. Replace with the approved public biography when available.',
                'member_type' => 'human',
                'sort_order' => 10,
                'author_slug' => null,
                'department_slug' => 'leadership',
                'manager_slug' => null,
                'roles' => ['co-owner'],
            ],
            [
                'name' => 'Elena',
                'slug' => 'elena',
                'display_title' => 'Co-owner / Editor / Author',
                'short_bio' => 'Co-owner and editorial voice helping shape RazbudiSe stories and publishing standards.',
                'full_bio' => 'Placeholder bio for Elena. Replace with the approved public biography when available.',
                'member_type' => 'human',
                'sort_order' => 20,
                'author_slug' => 'elena',
                'department_slug' => 'editorial',
                'manager_slug' => 'aleksandar-dimovski',
                'roles' => ['co-owner', 'editor', 'author'],
            ],
            [
                'name' => 'Jane',
                'slug' => 'jane',
                'display_title' => 'Co-owner',
                'short_bio' => 'Co-owner supporting RazbudiSe business direction and long-term editorial development.',
                'full_bio' => 'Placeholder bio for Jane. Replace with the approved public biography when available.',
                'member_type' => 'human',
                'sort_order' => 30,
                'author_slug' => null,
                'department_slug' => 'leadership',
                'manager_slug' => 'aleksandar-dimovski',
                'roles' => ['co-owner'],
            ],
            [
                'name' => 'Ramona',
                'slug' => 'ramona',
                'display_title' => 'Author',
                'short_bio' => 'Author contributing public-facing stories and editorial features for RazbudiSe.',
                'full_bio' => 'Placeholder bio for Ramona. Replace with the approved public biography when available.',
                'member_type' => 'human',
                'sort_order' => 40,
                'author_slug' => 'ramona',
                'department_slug' => 'writing',
                'manager_slug' => 'elena',
                'roles' => ['author'],
            ],
            [
                'name' => 'Mila Andonova',
                'slug' => 'mila-andonova',
                'display_title' => 'AI Author / Senior Staff Writer',
                'short_bio' => 'AI crew member supporting newsroom writing, research synthesis, and editorial drafting.',
                'full_bio' => 'Placeholder AI crew bio for Mila Andonova. Keep public presentation professional and newsroom-staff oriented.',
                'member_type' => 'ai',
                'sort_order' => 50,
                'author_slug' => 'mila-andonova',
                'department_slug' => 'writing',
                'manager_slug' => 'elena',
                'roles' => ['ai-employee', 'author'],
            ],
            [
                'name' => 'Martin',
                'slug' => 'martin',
                'display_title' => 'AI Research Specialist',
                'short_bio' => 'AI crew member focused on research support, source review, and topic preparation.',
                'full_bio' => 'Placeholder AI crew bio for Martin. Keep public presentation professional and newsroom-staff oriented.',
                'member_type' => 'ai',
                'sort_order' => 60,
                'author_slug' => null,
                'department_slug' => 'research',
                'manager_slug' => 'elena',
                'roles' => ['ai-employee', 'research'],
            ],
            [
                'name' => 'Viktor',
                'slug' => 'viktor',
                'display_title' => 'AI SEO Specialist',
                'short_bio' => 'AI crew member supporting SEO checks, metadata quality, and search-friendly story packaging.',
                'full_bio' => 'Placeholder AI crew bio for Viktor. Keep public presentation professional and newsroom-staff oriented.',
                'member_type' => 'ai',
                'sort_order' => 70,
                'author_slug' => null,
                'department_slug' => 'seo',
                'manager_slug' => 'elena',
                'roles' => ['ai-employee', 'seo'],
            ],
            [
                'name' => 'Sara',
                'slug' => 'sara',
                'display_title' => 'AI Social Media',
                'short_bio' => 'AI crew member helping adapt published stories into social media-ready summaries and ideas.',
                'full_bio' => 'Placeholder AI crew bio for Sara. Keep public presentation professional and newsroom-staff oriented.',
                'member_type' => 'ai',
                'sort_order' => 80,
                'author_slug' => null,
                'department_slug' => 'social-media',
                'manager_slug' => 'elena',
                'roles' => ['ai-employee', 'social-media'],
            ],
            [
                'name' => 'David',
                'slug' => 'david',
                'display_title' => 'AI Fact Checker',
                'short_bio' => 'AI crew member supporting fact-checking workflows, consistency checks, and editorial review.',
                'full_bio' => 'Placeholder AI crew bio for David. Keep public presentation professional and newsroom-staff oriented.',
                'member_type' => 'ai',
                'sort_order' => 90,
                'author_slug' => null,
                'department_slug' => 'fact-checking',
                'manager_slug' => 'elena',
                'roles' => ['ai-employee', 'fact-checker'],
            ],
        ];
    }

    private function seedLocalAdminUser(): void
    {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            return;
        }

        $adminRole = Role::query()->where('name', 'admin')->firstOrFail();
        $adminUser = User::query()->firstOrCreate(
            ['email' => env('LOCAL_ADMIN_EMAIL', 'admin@example.test')],
            [
                'name' => env('LOCAL_ADMIN_NAME', 'Local Admin'),
                'password' => Hash::make(env('LOCAL_ADMIN_PASSWORD', 'password')),
            ],
        );

        $adminUser->roles()->syncWithoutDetaching([$adminRole->id]);
    }

    private function seedDemoAds(): void
    {
        $advertiser = Advertiser::query()->updateOrCreate(
            ['slug' => 'razbudi-se-demo-ads'],
            [
                'name' => 'RazbudiSe Demo Ads',
                'contact_name' => 'Local Admin',
                'contact_email' => 'ads@example.test',
                'website_url' => rtrim((string) config('app.frontend_url'), '/'),
                'notes' => 'Local-only demo advertiser for validating direct ad placements.',
                'is_active' => true,
            ],
        );

        $campaign = AdCampaign::query()->updateOrCreate(
            [
                'advertiser_id' => $advertiser->id,
                'name' => 'RazbudiSe local demo campaign',
            ],
            [
                'status' => 'active',
                'start_at' => now()->subDay(),
                'end_at' => now()->addYear(),
                'priority' => 25,
                'device_targeting' => [],
                'category_targeting' => [],
                'article_targeting' => [],
                'max_impressions' => null,
                'max_clicks' => null,
                'daily_impression_cap' => null,
                'notes' => 'Seeded campaign for local frontend ad visibility.',
            ],
        );

        $fallbackCampaign = AdCampaign::query()->updateOrCreate(
            [
                'advertiser_id' => $advertiser->id,
                'name' => 'RazbudiSe house fallback campaign',
            ],
            [
                'status' => 'paused',
                'start_at' => now()->subDay(),
                'end_at' => now()->addYear(),
                'priority' => 0,
                'device_targeting' => [],
                'category_targeting' => [],
                'article_targeting' => [],
                'max_impressions' => null,
                'max_clicks' => null,
                'daily_impression_cap' => null,
                'notes' => 'Fallback creative used when no direct campaign matches.',
            ],
        );

        $billboardImage = MediaAsset::query()->updateOrCreate(
            ['path' => 'demo/ads/local-billboard.svg'],
            [
                'disk' => 'external',
                'url' => '/brand/demo/ads/local-billboard.svg',
                'mime_type' => 'image/svg+xml',
                'alt_text' => 'Разбуди се демо реклама',
                'caption' => 'Локален демо банер за проверка на рекламните позиции.',
                'width' => 970,
                'height' => 250,
                'size_bytes' => 3200,
                'metadata' => [
                    'source' => 'demo_seed',
                    'credit' => 'RazbudiSe demo archive',
                    'frontend_public_path' => '/brand/demo/ads/local-billboard.svg',
                ],
            ],
        );

        $homeBillboard = AdZone::query()->where('key', 'home_top_billboard')->first();

        if ($homeBillboard) {
            AdCreative::query()->updateOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'name' => 'Local demo billboard',
                ],
                [
                    'zone_id' => $homeBillboard->id,
                    'type' => 'html',
                    'image_id' => null,
                    'html' => $this->fallbackAdHtml($homeBillboard),
                    'click_url' => rtrim((string) config('app.frontend_url'), '/').'/advertise',
                    'width' => 970,
                    'height' => 250,
                    'is_fallback' => false,
                    'is_active' => true,
                ],
            );
        }

        AdCreative::query()->updateOrCreate(
            [
                'campaign_id' => $fallbackCampaign->id,
                'name' => 'RazbudiSe house fallback',
            ],
            [
                'zone_id' => null,
                'type' => 'html',
                'image_id' => null,
                'html' => '<div class="ad-house-creative"><strong>Разбуди се</strong><span>Резервен рекламен простор за сите позиции.</span></div>',
                'click_url' => null,
                'width' => 728,
                'height' => 90,
                'is_fallback' => true,
                'is_active' => true,
            ],
        );

        AdZone::query()->orderBy('key')->get()->each(function (AdZone $zone) use ($fallbackCampaign): void {
            [$width, $height] = $this->firstAdZoneSize($zone);

            AdCreative::query()->updateOrCreate(
                [
                    'campaign_id' => $fallbackCampaign->id,
                    'name' => 'RazbudiSe house fallback - '.$zone->key,
                ],
                [
                    'zone_id' => $zone->id,
                    'type' => 'html',
                    'image_id' => null,
                    'html' => $this->fallbackAdHtml($zone),
                    'click_url' => null,
                    'width' => $width,
                    'height' => $height,
                    'is_fallback' => true,
                    'is_active' => true,
                ],
            );
        });
    }

    private function seedAdPrices(): void
    {
        $weeklyRates = [
            'home_top_billboard' => 18000,
            'home_after_featured' => 12000,
            'home_in_feed_1' => 7500,
            'home_in_feed_1_slot_2' => 7500,
            'home_in_feed_1_slot_3' => 7500,
            'home_in_feed_1_slot_4' => 7500,
            'home_in_feed_2' => 7500,
            'home_in_feed_2_slot_2' => 7500,
            'home_in_feed_2_slot_3' => 7500,
            'home_in_feed_2_slot_4' => 7500,
            'home_in_feed_3' => 7500,
            'home_in_feed_3_slot_2' => 7500,
            'home_in_feed_3_slot_3' => 7500,
            'home_in_feed_3_slot_4' => 7500,
            'category_top' => 9000,
            'category_in_feed' => 6000,
            'category_in_feed_slot_2' => 6000,
            'category_in_feed_slot_3' => 6000,
            'category_in_feed_slot_4' => 6000,
            'article_after_title' => 9000,
            'article_after_paragraph_3' => 7500,
            'article_after_paragraph_7' => 7500,
            'article_before_related' => 7000,
            'article_sidebar_top' => 6500,
            'article_sidebar_top_slot_2' => 6500,
            'article_sidebar_top_slot_3' => 6500,
            'article_sidebar_sticky' => 8500,
            'article_sidebar_sticky_slot_2' => 8500,
            'mobile_sticky_bottom' => 4500,
            'search_results_top' => 5000,
            'author_page_top' => 5000,
            'event_page_top' => 5000,
            'newsletter_inline' => 5000,
            'newsletter_inline_slot_2' => 5000,
        ];

        foreach ($weeklyRates as $zoneKey => $weeklyRate) {
            $zone = AdZone::query()->where('key', $zoneKey)->first();
            if (! $zone) {
                continue;
            }

            foreach ([1, 2, 4] as $weeks) {
                AdPrice::query()->firstOrCreate(
                    [
                        'ad_zone_id' => $zone->id,
                        'duration_weeks' => $weeks,
                        'creative_type' => 'image',
                    ],
                    [
                        'price_denars' => $weeklyRate * $weeks,
                        'political_multiplier' => 2,
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function firstAdZoneSize(AdZone $zone): array
    {
        $size = collect($zone->allowed_sizes ?? [])
            ->first(fn (mixed $candidate): bool => is_array($candidate) && count($candidate) >= 2);

        if (! is_array($size)) {
            return [728, 90];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    private function fallbackAdHtml(AdZone $zone): string
    {
        return sprintf(
            '<div class="ad-house-creative"><strong>%s</strong><span>Вашиот бренд може да биде тука.</span></div>',
            e($zone->name),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsertMenuItem(Menu $menu, array $item, mixed $categoryModels, ?int $parentId = null): MenuItem
    {
        $category = isset($item['category_slug'])
            ? $categoryModels->get($item['category_slug'])
            : null;

        $menuItem = MenuItem::query()->updateOrCreate(
            [
                'menu_id' => $menu->id,
                'title' => $item['title'],
            ],
            [
                'parent_id' => $parentId,
                'link_type' => $item['link_type'],
                'url' => $item['url'] ?? null,
                'linkable_type' => $category ? Category::class : null,
                'linkable_id' => $category?->id,
                'opens_in_new_tab' => false,
                'is_active' => true,
                'sort_order' => $item['sort_order'],
            ],
        );

        foreach ($item['children'] ?? [] as $child) {
            $this->upsertMenuItem($menu, $child, $categoryModels, $menuItem->id);
        }

        return $menuItem;
    }

    /**
     * @return array<int, array{wordpress_id: int, source_slug: string, target_slug: string, notes: string}>
     */
    private function originalWordpressCategoryMappings(): array
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
     * @return array<int, array<string, mixed>>
     */
    private function headerMenuItems(): array
    {
        return [
            ['title' => 'Почетна', 'link_type' => 'custom', 'url' => '/', 'sort_order' => 5],
            [
                'title' => 'Живот',
                'link_type' => 'category',
                'category_slug' => 'zivot',
                'sort_order' => 10,
                'children' => [
                    ['title' => 'Хедонизам и Гастрономија', 'link_type' => 'category', 'category_slug' => 'hedonizam-i-gastronomija', 'sort_order' => 10],
                    ['title' => 'Опуштено', 'link_type' => 'category', 'category_slug' => 'opusteno', 'sort_order' => 20],
                    ['title' => 'Видеата на Марјан', 'link_type' => 'category', 'category_slug' => 'videata-na-marjan', 'sort_order' => 30],
                    ['title' => 'Мими Југо', 'link_type' => 'category', 'category_slug' => 'mimi-yugo', 'sort_order' => 40],
                    ['title' => 'Нена Колумни', 'link_type' => 'category', 'category_slug' => 'nena-kolumni', 'sort_order' => 50],
                    ['title' => 'Психологија', 'link_type' => 'category', 'category_slug' => 'psihologija', 'sort_order' => 60],
                    ['title' => 'Модна Соба', 'link_type' => 'category', 'category_slug' => 'modna-soba', 'sort_order' => 70],
                    ['title' => 'Холивудски Трачарлуци', 'link_type' => 'category', 'category_slug' => 'xolivudski-tracarluci', 'sort_order' => 80],
                ],
            ],
            [
                'title' => 'Урбано',
                'link_type' => 'category',
                'category_slug' => 'urbano',
                'sort_order' => 60,
                'children' => [
                    ['title' => 'Интервју', 'link_type' => 'category', 'category_slug' => 'intervju', 'sort_order' => 10],
                    ['title' => 'Дом и Дизајн', 'link_type' => 'category', 'category_slug' => 'dom-i-dizajn', 'sort_order' => 20],
                    ['title' => 'Книга', 'link_type' => 'category', 'category_slug' => 'kniga', 'sort_order' => 30],
                    ['title' => 'Art Time', 'link_type' => 'category', 'category_slug' => 'art-time', 'sort_order' => 40],
                    ['title' => 'Настани', 'link_type' => 'custom', 'url' => '/nastani', 'sort_order' => 50],
                ],
            ],
            ['title' => 'Битолски Муабети', 'link_type' => 'category', 'category_slug' => 'bitolski-muabeti', 'sort_order' => 120],
            ['title' => 'Колумни', 'link_type' => 'category', 'category_slug' => 'kolumni', 'sort_order' => 130],
            [
                'title' => 'Во деловен кодекс',
                'link_type' => 'category',
                'category_slug' => 'vo-deloven-kodeks',
                'sort_order' => 140,
                'children' => [
                    ['title' => 'Бизнис', 'link_type' => 'category', 'category_slug' => 'biznis', 'sort_order' => 10],
                    ['title' => 'Инвестиции и финансии', 'link_type' => 'category', 'category_slug' => 'finansii', 'sort_order' => 20],
                ],
            ],
            ['title' => 'Вести', 'link_type' => 'category', 'category_slug' => 'vesti', 'sort_order' => 160],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function footerMenuItems(): array
    {
        return [
            ['title' => 'Почетна', 'url' => '/', 'sort_order' => 10],
            ['title' => 'Пребарување', 'url' => '/search', 'sort_order' => 20],
            ['title' => 'Автори', 'url' => '/avtor', 'sort_order' => 30],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function adZones(): array
    {
        return [
            ['key' => 'home_top_billboard', 'name' => 'Почетна - горен билборд', 'description' => 'Голем рекламен простор над главната почетна содржина.', 'page_context' => 'home', 'allowed_sizes' => [[970, 250], [728, 90]]],
            ['key' => 'home_after_featured', 'name' => 'Почетна - после главна приказна', 'description' => 'Реклама под издвоените содржини на почетната страница.', 'page_context' => 'home', 'allowed_sizes' => [[728, 90], [970, 90]]],
            ['key' => 'home_in_feed_1', 'name' => 'Почетна - во фид 1', 'description' => 'Прва native/display позиција меѓу почетните статии.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'home_in_feed_1_slot_2', 'name' => 'Почетна - во фид 1 / слот 2', 'description' => 'Втор рекламен слот во првата почетна in-feed група.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'home_in_feed_1_slot_3', 'name' => 'Почетна - во фид 1 / слот 3', 'description' => 'Трет рекламен слот во првата почетна in-feed група.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'home_in_feed_1_slot_4', 'name' => 'Почетна - во фид 1 / слот 4', 'description' => 'Четврт рекламен слот во првата почетна in-feed група.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'home_in_feed_2', 'name' => 'Почетна - во фид 2', 'description' => 'Втора native/display позиција меѓу почетните статии.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'home_in_feed_2_slot_2', 'name' => 'Почетна - во фид 2 / слот 2', 'description' => 'Втор рекламен слот во втората почетна in-feed група.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'home_in_feed_2_slot_3', 'name' => 'Почетна - во фид 2 / слот 3', 'description' => 'Трет рекламен слот во втората почетна in-feed група.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'home_in_feed_2_slot_4', 'name' => 'Почетна - во фид 2 / слот 4', 'description' => 'Четврт рекламен слот во втората почетна in-feed група.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'home_in_feed_3', 'name' => 'Почетна - во фид 3', 'description' => 'Трета native/display позиција меѓу почетните статии.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'home_in_feed_3_slot_2', 'name' => 'Почетна - во фид 3 / слот 2', 'description' => 'Втор рекламен слот во третата почетна in-feed група.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'home_in_feed_3_slot_3', 'name' => 'Почетна - во фид 3 / слот 3', 'description' => 'Трет рекламен слот во третата почетна in-feed група.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'home_in_feed_3_slot_4', 'name' => 'Почетна - во фид 3 / слот 4', 'description' => 'Четврт рекламен слот во третата почетна in-feed група.', 'page_context' => 'home', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'category_top', 'name' => 'Категорија - горна реклама', 'description' => 'Горна реклама на архиви по категорија.', 'page_context' => 'category', 'allowed_sizes' => [[970, 250], [728, 90]]],
            ['key' => 'category_in_feed', 'name' => 'Категорија - во фид', 'description' => 'Реклама меѓу статии во категорија.', 'page_context' => 'category', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'category_in_feed_slot_2', 'name' => 'Категорија - во фид / слот 2', 'description' => 'Втор рекламен слот меѓу статии во категорија.', 'page_context' => 'category', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'category_in_feed_slot_3', 'name' => 'Категорија - во фид / слот 3', 'description' => 'Трет рекламен слот меѓу статии во категорија.', 'page_context' => 'category', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'category_in_feed_slot_4', 'name' => 'Категорија - во фид / слот 4', 'description' => 'Четврт рекламен слот меѓу статии во категорија.', 'page_context' => 'category', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'article_after_title', 'name' => 'Статија - после наслов', 'description' => 'Реклама веднаш под насловот и мета податоците.', 'page_context' => 'article', 'allowed_sizes' => [[728, 90], [300, 250]]],
            ['key' => 'article_after_paragraph_3', 'name' => 'Статија - после параграф 3', 'description' => 'Inline реклама по третиот параграф.', 'page_context' => 'article', 'allowed_sizes' => [[728, 90], [300, 250], [336, 280]]],
            ['key' => 'article_after_paragraph_7', 'name' => 'Статија - после параграф 7', 'description' => 'Inline реклама подлабоко во текстот.', 'page_context' => 'article', 'allowed_sizes' => [[728, 90], [300, 250], [336, 280]]],
            ['key' => 'article_before_related', 'name' => 'Статија - пред поврзани', 'description' => 'Реклама пред блокот со поврзани содржини.', 'page_context' => 'article', 'allowed_sizes' => [[728, 90], [970, 90]]],
            ['key' => 'article_sidebar_top', 'name' => 'Статија - sidebar горе', 'description' => 'Горна sidebar позиција на desktop приказ.', 'page_context' => 'article', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'article_sidebar_top_slot_2', 'name' => 'Статија - sidebar горе / слот 2', 'description' => 'Втор горен sidebar слот на desktop приказ.', 'page_context' => 'article', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'article_sidebar_top_slot_3', 'name' => 'Статија - sidebar горе / слот 3', 'description' => 'Трет горен sidebar слот на desktop приказ.', 'page_context' => 'article', 'allowed_sizes' => [[300, 250], [336, 280]]],
            ['key' => 'article_sidebar_sticky', 'name' => 'Статија - sticky sidebar', 'description' => 'Sticky sidebar позиција за подолги статии.', 'page_context' => 'article', 'allowed_sizes' => [[300, 600], [300, 250]]],
            ['key' => 'article_sidebar_sticky_slot_2', 'name' => 'Статија - sticky sidebar / слот 2', 'description' => 'Втор sticky sidebar слот за подолги статии.', 'page_context' => 'article', 'allowed_sizes' => [[300, 600], [300, 250]]],
            ['key' => 'mobile_sticky_bottom', 'name' => 'Мобилен sticky bottom', 'description' => 'Фиксна долна реклама на мобилен приказ.', 'page_context' => 'global', 'allowed_sizes' => [[320, 50], [320, 100]]],
            ['key' => 'search_results_top', 'name' => 'Пребарување - горна реклама', 'description' => 'Горна реклама над резултати од пребарување.', 'page_context' => 'search', 'allowed_sizes' => [[728, 90], [970, 90]]],
            ['key' => 'author_page_top', 'name' => 'Автор - горна реклама', 'description' => 'Реклама на врвот на авторска страница.', 'page_context' => 'author', 'allowed_sizes' => [[728, 90], [970, 90]]],
            ['key' => 'event_page_top', 'name' => 'Настани - горна реклама', 'description' => 'Реклама на врвот на страници за настани.', 'page_context' => 'event', 'allowed_sizes' => [[728, 90], [970, 90]]],
            ['key' => 'newsletter_inline', 'name' => 'Newsletter inline', 'description' => 'Inline промотивна позиција во newsletter блок.', 'page_context' => 'newsletter', 'allowed_sizes' => [[600, 200], [728, 90]]],
            ['key' => 'newsletter_inline_slot_2', 'name' => 'Newsletter inline / слот 2', 'description' => 'Втор inline промотивен слот во newsletter блок.', 'page_context' => 'newsletter', 'allowed_sizes' => [[600, 200], [728, 90]]],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function siteSettings(): array
    {
        return [
            [
                'key' => 'site_name',
                'value' => ['Разбуди се'],
                'group' => 'general',
                'is_public' => true,
            ],
            [
                'key' => 'site_url',
                'value' => ['https://razbudise.mk'],
                'group' => 'general',
                'is_public' => true,
            ],
            [
                'key' => 'default_meta_title',
                'value' => ['Разбуди се - lifestyle, култура, настани и битолски приказни'],
                'group' => 'seo',
                'is_public' => true,
            ],
            [
                'key' => 'default_meta_description',
                'value' => ['Разбуди се е македонски портал за живот, култура, хедонизам, интервјуа, настани и локални приказни.'],
                'group' => 'seo',
                'is_public' => true,
            ],
            [
                'key' => 'default_og_image',
                'value' => ['/brand/razbudise-logo.png'],
                'group' => 'seo',
                'is_public' => true,
            ],
            [
                'key' => AdBillingProfileService::SETTING_KEY,
                'value' => app(AdBillingProfileService::class)->defaults(),
                'group' => 'billing',
                'is_public' => false,
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, slug: string}>
     */
    private function tags(): array
    {
        return [
            ['name' => 'Битола', 'slug' => 'bitola'],
            ['name' => 'Култура', 'slug' => 'kultura'],
            ['name' => 'Настани', 'slug' => 'nastani'],
            ['name' => 'Интервју', 'slug' => 'intervju'],
            ['name' => 'Живот', 'slug' => 'zivot'],
            ['name' => 'Гастрономија', 'slug' => 'gastronomija'],
            ['name' => 'Бизнис', 'slug' => 'biznis'],
            ['name' => 'Спонзорирано', 'slug' => 'sponzorirano'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function demoArticles(): array
    {
        return [
            [
                'title' => 'Битола се буди со нови културни приказни',
                'slug' => 'bitola-se-budi-so-novi-kulturni-prikazni',
                'excerpt' => 'Кратка прошетка низ луѓето, местата и идеите кои и даваат нов ритам на градската сцена.',
                'body_html' => '<p>Битола секогаш имала посебен начин да ги раскажува своите приказни. Овој демо текст ја поставува почетната содржина за новата платформа.</p><p>Во следните фази овие демо објави ќе бидат заменети со мигрирана и редакциски подготвена содржина.</p>',
                'type' => 'editorial',
                'category_slug' => 'bitolski-muabeti',
                'tag_slugs' => ['bitola', 'kultura'],
                'days_ago' => 1,
                'blocks' => [
                    [
                        'type' => 'paragraph',
                        'sort_order' => 10,
                        'data' => ['html' => '<p>Битола секогаш имала посебен начин да ги раскажува своите приказни, низ кафе-муабети, мали сцени и луѓе кои тивко ја движат културата.</p>'],
                    ],
                    [
                        'type' => 'paragraph',
                        'sort_order' => 20,
                        'data' => ['html' => '<p>Новата платформа треба да го задржи тој ритам: брзо читање, јасна навигација и простор за авторски гласови што звучат локално.</p>'],
                    ],
                    [
                        'type' => 'paragraph',
                        'sort_order' => 30,
                        'data' => ['html' => '<p>Во оваа демо поставка содржината е намерно кратка, но структурата веќе поддржува блокови, реклами, тагови и SEO податоци.</p>'],
                    ],
                    [
                        'type' => 'quote',
                        'sort_order' => 40,
                        'data' => ['text' => 'Градот најдобро се чита преку луѓето што секој ден му додаваат нова реченица.', 'attribution' => 'Разбуди Се Редакција'],
                    ],
                    [
                        'type' => 'paragraph',
                        'sort_order' => 50,
                        'data' => ['html' => '<p>Следната фаза ќе ја замени демо содржината со мигрирани WordPress текстови, зачувани автори и чисти URL патеки.</p>'],
                    ],
                ],
            ],
            [
                'title' => 'Разговор со луѓето што ја движат локалната сцена',
                'slug' => 'razgovor-so-lugjeto-sto-ja-dvizat-lokalnata-scena',
                'excerpt' => 'Интервју формат за автори, артисти и иницијативи кои оставаат трага.',
                'body_html' => '<p>Интервјуата се важен дел од идниот RazbudiSe CMS затоа што ги поврзуваат читателите со вистинските гласови зад приказните.</p>',
                'type' => 'interview',
                'category_slug' => 'intervju',
                'tag_slugs' => ['intervju', 'kultura'],
                'days_ago' => 2,
            ],
            [
                'title' => 'Викенд календар: настани што вреди да се следат',
                'slug' => 'vikend-kalendar-nastani-sto-vredi-da-se-sledat',
                'excerpt' => 'Избор на културни, урбани и семејни случувања за наредните денови.',
                'body_html' => '<p>Настаните ќе имаат посебен простор во платформата, со можност подоцна да се развие календар и архивирање по датум.</p>',
                'type' => 'event',
                'category_slug' => 'nastani',
                'tag_slugs' => ['nastani', 'kultura'],
                'days_ago' => 3,
            ],
            [
                'title' => 'Мали ритуали за подобро утро',
                'slug' => 'mali-rituali-za-podobro-utro',
                'excerpt' => 'Лесен lifestyle водич за навики, темпо и малку повеќе простор за себе.',
                'body_html' => '<p>Животните теми на RazbudiSe носат практични, топли и читливи содржини за секојдневието.</p>',
                'type' => 'guide',
                'category_slug' => 'zivot',
                'tag_slugs' => ['zivot'],
                'days_ago' => 4,
            ],
            [
                'title' => 'Гастрономски места со приказна',
                'slug' => 'gastronomski-mesta-so-prikazna',
                'excerpt' => 'Хедонизам, локални вкусови и места што се паметат по атмосферата.',
                'body_html' => '<p>Гастрономијата е дел од културниот пулс: рецепти, локали, луѓе и мали задоволства.</p>',
                'type' => 'editorial',
                'category_slug' => 'hedonizam-i-gastronomija',
                'tag_slugs' => ['gastronomija'],
                'days_ago' => 5,
            ],
            [
                'title' => 'Психологија на секојдневниот баланс',
                'slug' => 'psihologija-na-sekojdnevniot-balans',
                'excerpt' => 'Како малите избори влијаат на фокусот, односите и личното темпо.',
                'body_html' => '<p>Оваа демо објава ја претставува психологија рубриката со пристапен тон и практичен фокус.</p>',
                'type' => 'column',
                'category_slug' => 'psihologija',
                'tag_slugs' => ['zivot'],
                'days_ago' => 6,
            ],
            [
                'title' => 'Платена промотивна приказна за локален бренд',
                'slug' => 'platen-promotivna-prikazna-za-lokalen-brend',
                'excerpt' => 'Демо пример за јасно означена спонзорирана содржина со sponsor детали и тагови.',
                'body_html' => '<p>Спонзорираната содржина мора да биде јасно означена и одвоена од редакциските текстови, но сепак да се прикажува во истиот квалитетен читачки формат.</p><p>Овој демо текст покажува како платформата ќе ја носи disclosure ознаката, sponsor информацијата и кампањските датуми.</p>',
                'type' => 'sponsored',
                'category_slug' => 'biznis',
                'tag_slugs' => ['biznis', 'sponzorirano'],
                'days_ago' => 7,
                'is_sponsored' => true,
                'sponsored_content' => [
                    'sponsor_name' => 'Демо партнер',
                    'sponsor_url' => 'https://example.test',
                    'disclosure_label' => 'Спонзорирана објава',
                    'campaign_start_at' => now()->subDays(10),
                    'campaign_end_at' => now()->addDays(20),
                ],
            ],
            [
                'title' => 'Кратки вести за градски иницијативи',
                'slug' => 'kratki-vesti-za-gradski-inicijativi',
                'excerpt' => 'Демо news формат за брзи објави, локални информации и корисни насоки.',
                'body_html' => '<p>Вестите на платформата треба да бидат кратки, проверливи и лесни за скенирање на мобилен приказ.</p>',
                'type' => 'news',
                'category_slug' => 'vesti',
                'tag_slugs' => ['bitola'],
                'days_ago' => 8,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function homepageSections(): array
    {
        return [
            [
                'key' => 'featured',
                'name' => 'Главни приказни',
                'type' => 'latest',
                'configuration' => [
                    'title' => 'Главни приказни',
                    'description' => 'Избор од редакцијата за почеток на денот.',
                    'layout' => 'hero',
                    'ad_zone_after' => 'home_after_featured',
                    'limit' => 9,
                ],
                'sort_order' => 10,
            ],
            [
                'key' => 'special_rubrics',
                'name' => 'Специјални рубрики',
                'type' => 'promo_cards',
                'configuration' => [
                    'title' => 'Специјални рубрики',
                    'description' => 'Брзи влезови до авторски и видео рубрики.',
                    'cards' => [
                        [
                            'title' => 'Мими Yugo',
                            'href' => '/category/mimi-yugo',
                            'image_url' => '/homepage/promos/mimi-yugo.webp',
                            'alt' => 'Мими Yugo рубрика',
                            'sort_order' => 10,
                        ],
                        [
                            'title' => 'Нена колумни',
                            'href' => '/category/nena-kolumni',
                            'image_url' => '/homepage/promos/nena-kolumni.webp',
                            'alt' => 'Нена колумни рубрика',
                            'sort_order' => 20,
                        ],
                        [
                            'title' => 'Брчиме со Дуле',
                            'href' => '/category/brchime_so_dule',
                            'image_url' => '/homepage/promos/brchime-so-dule.webp',
                            'alt' => 'Брчиме со Дуле рубрика',
                            'sort_order' => 30,
                        ],
                    ],
                ],
                'sort_order' => 15,
            ],
            [
                'key' => 'latest',
                'name' => 'Најново',
                'type' => 'latest',
                'configuration' => [
                    'title' => 'Најново',
                    'description' => 'Последни објави од сите рубрики.',
                    'layout' => 'grid',
                    'ad_zone_after' => 'home_in_feed_1',
                    'limit' => 6,
                ],
                'sort_order' => 20,
            ],
            [
                'key' => 'bitola',
                'name' => 'Битолски Муабети',
                'type' => 'category',
                'configuration' => [
                    'title' => 'Битолски Муабети',
                    'description' => 'Локални приказни и градски пулс.',
                    'layout' => 'compact',
                    'category_slug' => 'bitolski-muabeti',
                    'limit' => 4,
                ],
                'sort_order' => 30,
            ],
            [
                'key' => 'news',
                'name' => 'Вести',
                'type' => 'category',
                'configuration' => [
                    'title' => 'Вести',
                    'description' => 'Кратки информации, актуелности и важни локални сигнали.',
                    'layout' => 'grid',
                    'category_slug' => 'vesti',
                    'limit' => 6,
                ],
                'sort_order' => 35,
            ],
            [
                'key' => 'interviews',
                'name' => 'Интервју',
                'type' => 'category',
                'configuration' => [
                    'title' => 'Интервју',
                    'description' => 'Разговори со луѓе кои имаат што да кажат.',
                    'layout' => 'grid',
                    'category_slug' => 'intervju',
                    'limit' => 4,
                ],
                'sort_order' => 40,
            ],
            [
                'key' => 'events',
                'name' => 'Настани',
                'type' => 'category',
                'configuration' => [
                    'title' => 'Настани',
                    'description' => 'Календар на идеи за следното излегување.',
                    'layout' => 'compact',
                    'category_slug' => 'nastani',
                    'limit' => 4,
                ],
                'sort_order' => 50,
            ],
        ];
    }
}
