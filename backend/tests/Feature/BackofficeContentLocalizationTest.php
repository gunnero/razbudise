<?php

namespace Tests\Feature;

use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackofficeContentLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_macedonian_article_list_translates_custom_resource_and_table_labels(): void
    {
        $user = User::factory()->create(['locale' => 'mk']);
        $role = Role::query()->create(['name' => 'admin', 'label' => 'Administrator']);
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->get(ArticleResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Содржина')
            ->assertSee('Објави')
            ->assertSee('Објава')
            ->assertSee('Статус')
            ->assertSee('Тип')
            ->assertSee('Автор')
            ->assertSee('Категорија')
            ->assertSee('Нова објава');
    }

    public function test_resource_labels_remain_english_for_english_users(): void
    {
        app()->setLocale('en');

        $this->assertSame('Content', ArticleResource::getNavigationGroup());
        $this->assertSame('Articles', ArticleResource::getNavigationLabel());
        $this->assertSame('Article', ArticleResource::getModelLabel());
    }

    public function test_resource_labels_are_macedonian_when_locale_is_macedonian(): void
    {
        app()->setLocale('mk');

        $this->assertSame('Содржина', ArticleResource::getNavigationGroup());
        $this->assertSame('Објави', ArticleResource::getNavigationLabel());
        $this->assertSame('објава', ArticleResource::getModelLabel());
    }
}
