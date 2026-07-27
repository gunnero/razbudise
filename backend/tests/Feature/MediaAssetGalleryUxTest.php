<?php

namespace Tests\Feature;

use App\Filament\Resources\MediaAssets\Pages\EditMediaAsset;
use App\Filament\Resources\MediaAssets\Pages\ListMediaAssets;
use App\Filament\Tables\MediaAssetGalleryTable;
use App\Models\Article;
use App\Models\HomepageSection;
use App\Models\MediaAsset;
use App\Models\User;
use Filament\Forms\Components\TableSelect\Livewire\TableSelectLivewireComponent;
use Filament\Tables\Columns\ViewColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MediaAssetGalleryUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_library_renders_compact_gallery_cards_with_useful_context(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        MediaAsset::factory()->create([
            'path' => 'media/bitola-culture-evening.jpg',
            'title' => 'Bitola culture evening',
            'display_name' => 'Culture desk hero',
            'alt_text' => 'Audience at a cultural event in Bitola',
            'caption' => null,
            'width' => 1600,
            'height' => 900,
            'size_bytes' => 512000,
            'metadata' => ['credit' => 'RazbudiSe archive'],
        ]);

        Livewire::test(ListMediaAssets::class)
            ->call('loadTable')
            ->assertOk()
            ->assertSee('Bitola culture evening')
            ->assertSee('bitola-culture-evening.jpg')
            ->assertSee('1600×900')
            ->assertSee('500 KB')
            ->assertSeeHtml('razbudise-media-card__preview');
    }

    public function test_gallery_filters_orientation_metadata_and_sort_order(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        $landscape = MediaAsset::factory()->create([
            'path' => 'media/landscape.jpg',
            'title' => 'Landscape image',
            'alt_text' => null,
            'caption' => 'Landscape caption',
            'width' => 1800,
            'height' => 900,
            'size_bytes' => 900000,
        ]);
        $portrait = MediaAsset::factory()->create([
            'path' => 'media/portrait.jpg',
            'title' => 'Portrait image',
            'alt_text' => 'Portrait alt',
            'caption' => 'Portrait caption',
            'width' => 800,
            'height' => 1200,
            'size_bytes' => 100000,
        ]);

        $component = Livewire::test(ListMediaAssets::class)
            ->call('loadTable')
            ->filterTable('orientation', 'landscape');

        $component
            ->assertCanSeeTableRecords([$landscape])
            ->assertCanNotSeeTableRecords([$portrait]);

        $component
            ->resetTableFilters()
            ->filterTable('missing_alt_text', true)
            ->assertCanSeeTableRecords([$landscape])
            ->assertCanNotSeeTableRecords([$portrait]);

        $component
            ->resetTableFilters()
            ->filterTable('sort', ['value' => 'largest']);

        $recordIds = $component->instance()->getTableRecords()->pluck('id')->values();
        $this->assertLessThan(
            $recordIds->search($portrait->id),
            $recordIds->search($landscape->id),
        );
    }

    public function test_gallery_usage_filter_matches_exact_reference_usage_and_safe_deletion_state(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        $unused = MediaAsset::factory()->create();
        MediaAsset::factory()->count(8)->create();
        $used = MediaAsset::factory()->create();

        HomepageSection::query()->create([
            'key' => 'gallery-exact-media-id-usage',
            'name' => 'Gallery exact media ID usage',
            'configuration' => ['cards' => [['media_asset_id' => $used->id]]],
            'is_active' => true,
        ]);
        $newestUnused = MediaAsset::factory()->create();

        $component = Livewire::test(ListMediaAssets::class)
            ->call('loadTable')
            ->filterTable('usage', 'used');

        $component
            ->assertCanSeeTableRecords([$used])
            ->assertCanNotSeeTableRecords([$unused]);

        $component
            ->resetTableFilters()
            ->filterTable('usage', 'unused')
            ->assertCanSeeTableRecords([$unused])
            ->assertCanNotSeeTableRecords([$used]);

        $component
            ->resetTableFilters()
            ->filterTable('sort', ['value' => 'most_used']);

        $recordIds = $component->instance()->getTableRecords()->pluck('id')->values();

        $this->assertLessThan(
            $recordIds->search($newestUnused->id),
            $recordIds->search($used->id),
        );
    }

    public function test_article_media_picker_hydrates_fast_relational_usage_counts_without_accessor_queries(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        $asset = MediaAsset::factory()->create([
            'path' => 'media/picker-usage.jpg',
            'url' => '/storage/media/picker-usage.jpg',
        ]);
        Article::factory()->create([
            'featured_image_id' => $asset->id,
            'body_html' => '<img src="/storage/media/picker-usage.jpg">',
        ]);
        HomepageSection::query()->create([
            'key' => 'picker-usage',
            'name' => 'Picker usage',
            'configuration' => ['media_asset_id' => $asset->id],
        ]);

        $component = Livewire::test(TableSelectLivewireComponent::class, [
            'isDisabled' => false,
            'model' => Article::class,
            'record' => Article::factory()->create(),
            'relationshipName' => 'featuredImage',
            'tableConfiguration' => base64_encode(MediaAssetGalleryTable::class),
            'tableArguments' => [],
            'state' => null,
        ])->call('loadTable');

        $record = $component->instance()->getTableRecords()->firstWhere('id', $asset->id);

        $this->assertNotNull($record);
        $this->assertSame(1, $record->getAttributes()['usage_count']);
        $component->assertSee(trans_choice('media.usage_count', 1, ['count' => 1]));
    }

    public function test_media_detail_form_exposes_editorial_metadata_without_raw_json(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        $asset = MediaAsset::factory()->create([
            'path' => 'media/editorial-image.jpg',
            'title' => 'Editorial image',
        ]);

        $component = Livewire::test(EditMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->assertOk()
            ->assertFormFieldExists('display_name')
            ->assertFormFieldExists('title')
            ->assertFormFieldExists('filename')
            ->assertFormFieldExists('alt_text')
            ->assertFormFieldExists('caption')
            ->assertFormFieldExists('description')
            ->assertFormFieldExists('metadata.credit')
            ->assertFormFieldExists('photographer')
            ->assertFormFieldExists('source_name')
            ->assertFormFieldExists('license')
            ->assertFormFieldExists('copyright_notice')
            ->assertFormFieldExists('seo_filename_suggestion')
            ->assertFormFieldExists('usage_notes')
            ->assertFormFieldDoesNotExist('metadata');

        $component
            ->assertActionExists('replace')
            ->assertActionExists('archive')
            ->assertActionExists('delete')
            ->mountAction('replace')
            ->assertFormFieldExists('replacement_file');
    }

    public function test_edit_page_uses_audited_metadata_and_safe_filename_workflow(): void
    {
        Storage::fake('public');
        $this->seed();
        $this->actingAs($this->seededAdmin());

        $path = UploadedFile::fake()->image('original-name.jpg', 1600, 900)->store('media', 'public');
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
            'title' => 'Old title',
            'metadata' => [],
        ]);

        Livewire::test(EditMediaAsset::class, ['record' => $asset->getRouteKey()])
            ->fillForm([
                'display_name' => 'Culture desk cover',
                'title' => 'Bitola summer culture',
                'filename' => 'bitola-summer-culture.jpg',
                'alt_text' => 'Audience attending an outdoor cultural event in Bitola',
                'caption' => 'Summer cultural programme in Bitola',
                'metadata' => [
                    'credit' => 'RazbudiSe archive',
                    'source_url' => 'https://razbudise.mk',
                    'focal_point' => ['x' => 45, 'y' => 55],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $asset->refresh();

        $this->assertSame('Culture desk cover', $asset->getRawOriginal('display_name'));
        $this->assertSame('Bitola summer culture', $asset->title);
        $this->assertSame('media/bitola-summer-culture.jpg', $asset->path);
        $this->assertSame('RazbudiSe archive', $asset->metadata['credit']);
        $this->assertSame(['x' => 45, 'y' => 55], $asset->metadata['focal_point']);
        Storage::disk('public')->assertExists('media/bitola-summer-culture.jpg');
    }

    public function test_media_library_uses_visual_card_column_and_deferred_loading(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());
        $asset = MediaAsset::factory()->create();

        $component = Livewire::test(ListMediaAssets::class);
        $table = $component->instance()->getTable();
        $column = $table->getColumn('gallery_card');

        $this->assertInstanceOf(ViewColumn::class, $column);
        $this->assertTrue($table->isLoadingDeferred());
        $this->assertSame(30, $table->getDefaultPaginationPageOption());
        $this->assertSame(5, $table->getContentGrid()['2xl']);
        $this->assertSame('view', $table->getRecordAction($asset));
        $this->assertNull($table->getRecordUrl($asset));
    }

    public function test_gallery_detail_action_opens_the_uncropped_information_panel(): void
    {
        $this->seed();
        $this->actingAs($this->seededAdmin());

        $asset = MediaAsset::factory()->create([
            'path' => 'media/detail-panel.jpg',
            'title' => 'Detail panel image',
            'description' => 'Editorial image description',
            'photographer' => 'RazbudiSe photographer',
            'width' => 1920,
            'height' => 1080,
        ]);

        $component = Livewire::test(ListMediaAssets::class)
            ->call('loadTable')
            ->mountTableAction('view', $asset->getRouteKey())
            ->assertMountedActionModalSee([
                'Detail panel image',
                'Editorial image description',
                'RazbudiSe photographer',
                '1920×1080',
            ])
            ->assertMountedActionModalSeeHtml('window.navigator.clipboard.writeText');

        $visibleActionNames = collect($component->instance()->getMountedAction()?->getVisibleModalFooterActions() ?? [])
            ->map(fn ($action): string => $action->getName())
            ->all();

        $this->assertContains('edit_metadata', $visibleActionNames);
        $this->assertContains('replace', $visibleActionNames);
        $this->assertContains('archive', $visibleActionNames);

        $component
            ->mountAction('edit_metadata')
            ->assertFormFieldDisabled('path')
            ->assertFormFieldExists('alt_text')
            ->assertFormFieldExists('metadata.credit')
            ->assertFormFieldExists('metadata.focal_point.x')
            ->assertFormFieldExists('metadata.focal_point.y');
    }

    private function seededAdmin(): User
    {
        return User::query()->where('email', 'admin@example.test')->firstOrFail();
    }
}
