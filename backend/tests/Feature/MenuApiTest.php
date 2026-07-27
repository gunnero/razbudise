<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_menu_endpoint_returns_active_items_in_order_with_resolved_urls(): void
    {
        $category = Category::factory()->create(['slug' => 'zivot']);
        $article = Article::factory()->published()->create(['slug' => 'intervju-so-avtor']);
        $author = Author::factory()->create(['slug' => 'razbudi-se-redakcija']);
        $tag = Tag::query()->create(['name' => 'Култура', 'slug' => 'kultura']);

        $menu = Menu::query()->create([
            'name' => 'Header navigation',
            'location' => 'header',
            'is_active' => true,
        ]);

        MenuItem::query()->create([
            'menu_id' => $menu->id,
            'title' => 'Hidden',
            'link_type' => 'custom',
            'url' => '/hidden',
            'is_active' => false,
            'sort_order' => 1,
        ]);

        MenuItem::query()->create([
            'menu_id' => $menu->id,
            'title' => 'Живот',
            'link_type' => 'category',
            'linkable_type' => Category::class,
            'linkable_id' => $category->id,
            'sort_order' => 10,
            'is_active' => true,
        ]);

        MenuItem::query()->create([
            'menu_id' => $menu->id,
            'title' => 'Интервју',
            'link_type' => 'article',
            'linkable_type' => Article::class,
            'linkable_id' => $article->id,
            'sort_order' => 20,
            'is_active' => true,
        ]);

        MenuItem::query()->create([
            'menu_id' => $menu->id,
            'title' => 'Редакција',
            'link_type' => 'author',
            'linkable_type' => Author::class,
            'linkable_id' => $author->id,
            'sort_order' => 30,
            'is_active' => true,
            'opens_in_new_tab' => true,
        ]);

        MenuItem::query()->create([
            'menu_id' => $menu->id,
            'title' => 'Култура',
            'link_type' => 'tag',
            'linkable_type' => Tag::class,
            'linkable_id' => $tag->id,
            'sort_order' => 40,
            'is_active' => true,
        ]);

        MenuItem::query()->create([
            'menu_id' => $menu->id,
            'title' => 'Маркетинг',
            'link_type' => 'custom',
            'url' => 'https://ads.example.test',
            'sort_order' => 50,
            'is_active' => true,
        ]);

        $this->getJson('/api/menus/header')
            ->assertOk()
            ->assertJsonPath('data.location', 'header')
            ->assertJsonCount(5, 'data.items')
            ->assertJsonPath('data.items.0.title', 'Живот')
            ->assertJsonPath('data.items.0.url', '/category/zivot')
            ->assertJsonPath('data.items.1.url', '/intervju-so-avtor')
            ->assertJsonPath('data.items.2.url', '/profil/razbudi-se-redakcija')
            ->assertJsonPath('data.items.2.opens_in_new_tab', true)
            ->assertJsonPath('data.items.3.url', '/tag/kultura')
            ->assertJsonPath('data.items.4.url', 'https://ads.example.test');
    }

    public function test_menu_endpoint_returns_empty_items_for_missing_or_inactive_menu(): void
    {
        Menu::query()->create([
            'name' => 'Footer',
            'location' => 'footer',
            'is_active' => false,
        ]);

        $this->getJson('/api/menus/footer')
            ->assertOk()
            ->assertJsonPath('data.location', 'footer')
            ->assertJsonCount(0, 'data.items');

        $this->getJson('/api/menus/mobile')
            ->assertOk()
            ->assertJsonPath('data.location', 'mobile')
            ->assertJsonCount(0, 'data.items');
    }
}
