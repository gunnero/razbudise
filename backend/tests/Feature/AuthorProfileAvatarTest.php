<?php

namespace Tests\Feature;

use App\Http\Resources\TeamMemberResource;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Authors\AuthorProfileFormDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthorProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_author_api_returns_female_default_avatar_when_no_custom_photo_exists(): void
    {
        $author = Author::factory()->create([
            'slug' => 'elena-naumovski',
            'name' => 'Elena Naumovski',
            'sex' => Author::SEX_FEMALE,
            'avatar_id' => null,
        ]);

        Article::factory()->published()->create(['author_id' => $author->id]);

        $this->getJson('/api/authors/elena-naumovski')
            ->assertOk()
            ->assertJsonPath('data.avatar.url', '/images/authors/default-female-avatar.png')
            ->assertJsonPath('data.avatar.width', 1254)
            ->assertJsonPath('data.avatar.height', 1254)
            ->assertJsonPath('data.avatar.purpose', 'author_profile_default');
    }

    public function test_public_author_api_uses_male_default_avatar_when_sex_is_missing(): void
    {
        $author = Author::factory()->create([
            'slug' => 'razbudi-se-redakcija',
            'sex' => null,
            'avatar_id' => null,
        ]);

        Article::factory()->published()->create(['author_id' => $author->id]);

        $this->getJson('/api/authors/razbudi-se-redakcija')
            ->assertOk()
            ->assertJsonPath('data.avatar.url', '/images/authors/default-male-avatar.png');
    }

    public function test_custom_author_avatar_overrides_default_avatar(): void
    {
        $asset = MediaAsset::factory()->create([
            'url' => 'https://cdn.example.test/authors/custom-elena.jpg',
            'path' => 'media/authors/custom-elena.jpg',
            'width' => 900,
            'height' => 900,
        ]);

        $author = Author::factory()->create([
            'slug' => 'custom-author',
            'sex' => Author::SEX_FEMALE,
            'avatar_id' => $asset->id,
        ]);

        Article::factory()->published()->create(['author_id' => $author->id]);

        $this->getJson('/api/authors/custom-author')
            ->assertOk()
            ->assertJsonPath('data.avatar.url', 'https://cdn.example.test/authors/custom-elena.jpg')
            ->assertJsonPath('data.avatar.width', 900);
    }

    public function test_article_bylines_include_resolved_default_author_avatar(): void
    {
        $author = Author::factory()->create([
            'sex' => Author::SEX_FEMALE,
            'avatar_id' => null,
        ]);
        $article = Article::factory()->published()->create([
            'slug' => 'objava-so-avtor',
            'author_id' => $author->id,
            'category_id' => Category::factory(),
        ]);

        $this->getJson('/api/articles/'.$article->slug)
            ->assertOk()
            ->assertJsonPath('data.author.avatar.url', '/images/authors/default-female-avatar.png');
    }

    public function test_team_member_resource_uses_linked_author_sex_for_default_profile_image(): void
    {
        $author = Author::factory()->create(['sex' => Author::SEX_FEMALE]);
        $member = TeamMember::factory()->create([
            'author_id' => $author->id,
            'sex' => null,
            'profile_image_id' => null,
        ])->load(['author.avatar', 'profileImage']);

        $payload = TeamMemberResource::make($member)->resolve(request());

        $this->assertSame('/images/authors/default-female-avatar.png', $payload['profile_image']['url']);
    }

    public function test_author_profile_upload_registers_media_asset_and_hides_temporary_form_field(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $path = UploadedFile::fake()->image('profile.png', 600, 600)->store('media/authors', 'public');

        $prepared = app(AuthorProfileFormDataService::class)->prepare([
            'name' => 'New Author',
            'sex' => Author::SEX_MALE,
            'avatar_upload' => $path,
        ]);

        $this->assertArrayHasKey('avatar_id', $prepared);
        $this->assertArrayNotHasKey('avatar_upload', $prepared);
        $this->assertDatabaseHas('media_assets', [
            'id' => $prepared['avatar_id'],
            'path' => $path,
            'mime_type' => 'image/png',
            'alt_text' => 'Профилна слика за New Author',
        ]);
    }

    public function test_default_avatar_assets_exist_in_backend_and_frontend_public_roots(): void
    {
        $this->assertFileExists(base_path('public/images/authors/default-male-avatar.png'));
        $this->assertFileExists(base_path('public/images/authors/default-female-avatar.png'));
        $this->assertFileExists(base_path('../frontend/public/images/authors/default-male-avatar.png'));
        $this->assertFileExists(base_path('../frontend/public/images/authors/default-female-avatar.png'));
    }
}
