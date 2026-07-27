<?php

namespace Tests\Feature;

use App\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use App\Models\Article;
use App\Models\MediaAsset;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MediaAssetAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_media_actions_are_owner_scoped_and_cannot_mutate_live_media(): void
    {
        $this->seed();

        $author = $this->userWithRole('author');
        $contributor = $this->userWithRole('contributor');
        $otherUser = User::factory()->create();
        $owned = MediaAsset::factory()->create(['uploaded_by' => $author->id]);
        $contributorOwned = MediaAsset::factory()->create(['uploaded_by' => $contributor->id]);
        $foreign = MediaAsset::factory()->create(['uploaded_by' => $otherUser->id]);
        $unowned = MediaAsset::factory()->create(['uploaded_by' => null]);

        $this->assertTrue($author->can('view', $foreign));
        $this->assertFalse($author->can('update', $foreign));
        $this->assertFalse($author->can('replace', $foreign));
        $this->assertFalse($author->can('archive', $foreign));
        $this->assertFalse($author->can('delete', $foreign));
        $this->assertFalse($author->can('update', $unowned));
        $this->assertFalse($contributor->can('replace', $foreign));
        $this->assertFalse($contributor->can('archive', $foreign));
        $this->assertFalse($contributor->can('delete', $foreign));

        $this->assertTrue($author->can('update', $owned));
        $this->assertTrue($author->can('replace', $owned));
        $this->assertTrue($author->can('archive', $owned));
        $this->assertTrue($author->can('delete', $owned));
        $this->assertTrue($contributor->can('replace', $contributorOwned));

        Article::factory()->published()->create(['featured_image_id' => $owned->id]);

        $this->assertTrue($author->can('update', $owned));
        $this->assertFalse($author->can('replace', $owned));
        $this->assertFalse($author->can('archive', $owned));
        $this->assertFalse($author->can('delete', $owned));
    }

    public function test_editors_can_manage_arbitrary_media_but_safe_delete_still_requires_no_usage(): void
    {
        $this->seed();

        $editor = $this->userWithRole('editor');
        $asset = MediaAsset::factory()->create(['uploaded_by' => User::factory()->create()->id]);

        $this->assertTrue($editor->can('update', $asset));
        $this->assertTrue($editor->can('replace', $asset));
        $this->assertTrue($editor->can('archive', $asset));
        $this->assertTrue($editor->can('delete', $asset));

        Article::factory()->published()->create(['featured_image_id' => $asset->id]);

        $this->assertTrue($editor->can('replace', $asset));
        $this->assertTrue($editor->can('archive', $asset));
        $this->assertFalse($editor->can('delete', $asset));
    }

    public function test_detail_slide_over_only_exposes_actions_authorized_for_the_mounted_record(): void
    {
        $this->seed();

        $author = $this->userWithRole('author');
        $owned = MediaAsset::factory()->create(['uploaded_by' => $author->id]);
        $foreign = MediaAsset::factory()->create(['uploaded_by' => User::factory()->create()->id]);

        $this->actingAs($author);

        $foreignActions = $this->mountedDetailActionNames($foreign);
        $ownedActions = $this->mountedDetailActionNames($owned);

        $this->assertNotContains('edit_metadata', $foreignActions);
        $this->assertNotContains('replace', $foreignActions);
        $this->assertNotContains('archive', $foreignActions);
        $this->assertNotContains('safe_delete', $foreignActions);

        $this->assertContains('edit_metadata', $ownedActions);
        $this->assertContains('replace', $ownedActions);
        $this->assertContains('archive', $ownedActions);
        $this->assertContains('safe_delete', $ownedActions);

        Article::factory()->published()->create(['featured_image_id' => $owned->id]);

        $liveActions = $this->mountedDetailActionNames($owned->fresh());

        $this->assertContains('edit_metadata', $liveActions);
        $this->assertNotContains('replace', $liveActions);
        $this->assertNotContains('archive', $liveActions);
        $this->assertNotContains('safe_delete', $liveActions);
    }

    public function test_bulk_lifecycle_and_cleanup_actions_authorize_each_selected_asset(): void
    {
        $this->seed();
        $this->actingAs($this->userWithRole('author'));

        $table = Livewire::test(ListMediaAssets::class)->instance()->getTable();

        $this->assertTrue($table->getBulkAction('cleanup_quality')->shouldAuthorizeIndividualRecords());
        $this->assertTrue($table->getBulkAction('archive')->shouldAuthorizeIndividualRecords());
        $this->assertTrue($table->getBulkAction('safe_delete')->shouldAuthorizeIndividualRecords());
    }

    /**
     * @return array<int, string>
     */
    private function mountedDetailActionNames(MediaAsset $asset): array
    {
        $component = Livewire::test(ListMediaAssets::class)
            ->call('loadTable')
            ->mountTableAction('view', $asset->getRouteKey());

        return collect($component->instance()->getMountedAction()?->getVisibleModalFooterActions() ?? [])
            ->map(fn ($action): string => $action->getName())
            ->values()
            ->all();
    }

    private function userWithRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $roleName)->firstOrFail());

        return $user;
    }
}
