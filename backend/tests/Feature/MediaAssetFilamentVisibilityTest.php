<?php

namespace Tests\Feature;

use App\Filament\Resources\Articles\Pages\CreateArticle;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Role;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MediaAssetFilamentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_cannot_forge_an_anonymous_ad_creative_into_an_article_media_picker(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'author')->firstOrFail());
        $author = Author::factory()->create();
        TeamMember::factory()->create([
            'user_id' => $user->id,
            'author_id' => $author->id,
        ]);
        $category = Category::factory()->create(['is_active' => true]);
        $anonymousAdCreative = MediaAsset::factory()->create([
            'uploaded_by' => null,
            'metadata' => [
                'purpose' => 'ad_creative',
                'public_booking_upload_status' => 'pending',
            ],
        ]);
        $this->actingAs($user);

        Livewire::test(CreateArticle::class)
            ->fillForm([
                'title' => 'Објава со недозволена рекламна слика',
                'slug' => 'objava-so-nedozvolena-reklamna-slika',
                'body_html' => '<p>Содржина</p>',
                'author_id' => $author->id,
                'category_id' => $category->id,
                'featured_image_id' => $anonymousAdCreative->id,
                'status' => 'draft',
                'type' => 'editorial',
            ])
            ->call('create')
            ->assertHasFormErrors(['featured_image_id']);

        $this->assertFalse(Article::query()
            ->where('slug', 'objava-so-nedozvolena-reklamna-slika')
            ->exists());
    }
}
