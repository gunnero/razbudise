<?php

namespace Tests\Feature\ManagedAds;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdCreativeRevision;
use App\Models\AdCustomer;
use App\Models\AdDiscountCode;
use App\Models\AdInvoice;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdBookingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_only_one_ad_customer_profile(): void
    {
        $user = User::factory()->create();

        AdCustomer::factory()->create([
            'user_id' => $user->id,
        ]);

        $this->expectException(QueryException::class);

        AdCustomer::factory()->create([
            'user_id' => $user->id,
        ]);
    }

    public function test_booking_request_can_have_only_one_invoice(): void
    {
        $bookingRequest = AdBookingRequest::factory()->create();

        AdInvoice::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->create([
                'number' => 'PRO-2026-0001',
            ]);

        $this->expectException(QueryException::class);

        AdInvoice::factory()
            ->for($bookingRequest, 'bookingRequest')
            ->create([
                'number' => 'PRO-2026-0002',
            ]);
    }

    public function test_discount_code_is_stored_uppercase_and_trimmed(): void
    {
        $discountCode = AdDiscountCode::factory()->create([
            'code' => ' raz10 ',
        ]);

        $this->assertSame('RAZ10', $discountCode->refresh()->code);
    }

    public function test_ad_creative_revision_factory_creates_revision_for_line_item(): void
    {
        $revision = AdCreativeRevision::factory()->create();

        $this->assertSame('image', $revision->creative_type);
        $this->assertSame(300, $revision->width);
        $this->assertSame(250, $revision->height);
        $this->assertSame('pending_review', $revision->status);
        $this->assertTrue($revision->bookingLineItem()->exists());
    }

    public function test_ad_creative_can_access_revisions_through_booking_line_items(): void
    {
        $campaign = AdCampaign::factory()->create();
        $creative = AdCreative::query()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Managed creative',
            'type' => 'image',
            'width' => 300,
            'height' => 250,
            'is_fallback' => false,
            'is_active' => true,
        ]);
        $lineItem = AdBookingLineItem::factory()->create([
            'ad_creative_id' => $creative->id,
        ]);
        $revision = AdCreativeRevision::factory()
            ->for($lineItem, 'bookingLineItem')
            ->create();

        $this->assertSame([$revision->id], $creative->revisions()->pluck('ad_creative_revisions.id')->all());
    }

    public function test_managed_ad_booking_tables_have_explicit_child_foreign_key_indexes(): void
    {
        $this->assertTableHasIndexes('ad_customers', [
            'ad_customers_advertiser_id_idx',
        ]);
        $this->assertTableHasIndexes('ad_booking_requests', [
            'ad_booking_requests_customer_id_idx',
            'ad_booking_requests_discount_code_id_idx',
        ]);
        $this->assertTableHasIndexes('ad_booking_line_items', [
            'ad_booking_line_items_request_id_idx',
            'ad_booking_line_items_campaign_id_idx',
            'ad_booking_line_items_creative_id_idx',
            'ad_booking_line_items_image_id_idx',
        ]);
        $this->assertTableHasIndexes('ad_creative_revisions', [
            'ad_creative_revisions_line_item_id_idx',
            'ad_creative_revisions_submitted_by_idx',
            'ad_creative_revisions_image_id_idx',
            'ad_creative_revisions_reviewed_by_idx',
        ]);
    }

    /**
     * @param  array<int, string>  $expectedIndexes
     */
    private function assertTableHasIndexes(string $table, array $expectedIndexes): void
    {
        $indexNames = collect(app(ConnectionInterface::class)->getSchemaBuilder()->getIndexes($table))
            ->pluck('name')
            ->all();

        foreach ($expectedIndexes as $expectedIndex) {
            $this->assertContains($expectedIndex, $indexNames, "Missing index [{$expectedIndex}] on [{$table}].");
        }
    }
}
