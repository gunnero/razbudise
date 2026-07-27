<?php

namespace App\Services\Ads;

use App\Models\AdBookingLineItem;
use App\Models\AdBookingRequest;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdCustomer;
use App\Models\Advertiser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdBookingPublishingService
{
    private const PUBLISHABLE_LINE_ITEM_STATUSES = ['approved', 'published'];

    private const PAYABLE_PAYMENT_STATUSES = ['unpaid', 'pending'];

    private const TERMINAL_BOOKING_STATUSES = ['rejected', 'cancelled'];

    private const TERMINAL_LINE_REVIEW_BOOKING_STATUSES = ['rejected', 'cancelled', 'archived', 'published'];

    public function __construct(private readonly AdCreativeValidationService $creativeValidationService) {}

    public function publish(AdBookingRequest $bookingRequest, User $admin): void
    {
        $this->assertCanPublish($bookingRequest);

        DB::transaction(function () use ($bookingRequest, $admin): void {
            $advertiser = $this->createOrUpdateAdvertiser($bookingRequest);
            $this->createOrUpdateCustomer($bookingRequest, $advertiser);
            $publishedAny = false;

            // A paid line item can reserve inventory, but publishing still requires approval or an existing published record.
            $bookingRequest->lineItems()
                ->with(['zone', 'image'])
                ->whereIn('status', self::PUBLISHABLE_LINE_ITEM_STATUSES)
                ->get()
                ->each(function (AdBookingLineItem $lineItem) use ($advertiser, $bookingRequest, $admin, &$publishedAny): void {
                    $this->validateLineItemCreative($lineItem);

                    $campaign = $this->publishCampaign($lineItem, $bookingRequest, $advertiser, $admin);
                    $creative = $this->publishCreative($lineItem, $campaign);

                    $lineItem->forceFill([
                        'ad_campaign_id' => $campaign->id,
                        'ad_creative_id' => $creative->id,
                        'status' => 'published',
                    ])->save();

                    $publishedAny = true;
                });

            if ($publishedAny) {
                $bookingRequest->forceFill(['status' => 'published'])->save();
            }
        });
    }

    public function canMarkPaid(AdBookingRequest $bookingRequest): bool
    {
        return in_array($bookingRequest->payment_status, self::PAYABLE_PAYMENT_STATUSES, true)
            && ! $this->isTerminalBooking($bookingRequest);
    }

    public function canPublish(AdBookingRequest $bookingRequest): bool
    {
        return $bookingRequest->payment_status === 'paid'
            && ! $this->isTerminalBooking($bookingRequest)
            && $this->hasPublishableLineItems($bookingRequest);
    }

    public function canRejectBooking(AdBookingRequest $bookingRequest): bool
    {
        return ! $this->isTerminalBooking($bookingRequest);
    }

    public function canReviewLineItem(AdBookingLineItem $lineItem): bool
    {
        return $lineItem->status !== 'published'
            && ! $this->hasTerminalLineReviewParent($lineItem);
    }

    public function canRejectLineItem(AdBookingLineItem $lineItem): bool
    {
        return $lineItem->status !== 'rejected'
            && ! $this->hasTerminalLineReviewParent($lineItem);
    }

    private function assertCanPublish(AdBookingRequest $bookingRequest): void
    {
        if ($bookingRequest->payment_status !== 'paid') {
            throw ValidationException::withMessages([
                'payment_status' => 'Booking must be paid before campaigns can be published.',
            ]);
        }

        if ($this->isTerminalBooking($bookingRequest)) {
            throw ValidationException::withMessages([
                'status' => 'Rejected or cancelled bookings cannot be published.',
            ]);
        }

        if (! $this->hasPublishableLineItems($bookingRequest)) {
            throw ValidationException::withMessages([
                'line_items' => 'Booking must have at least one approved or published line item before publishing.',
            ]);
        }
    }

    private function hasPublishableLineItems(AdBookingRequest $bookingRequest): bool
    {
        return $bookingRequest->lineItems()
            ->whereIn('status', self::PUBLISHABLE_LINE_ITEM_STATUSES)
            ->exists();
    }

    private function isTerminalBooking(AdBookingRequest $bookingRequest): bool
    {
        return in_array($bookingRequest->status, self::TERMINAL_BOOKING_STATUSES, true);
    }

    public function markPaid(AdBookingRequest $bookingRequest, array $paymentData, User $admin): void
    {
        $this->assertCanMarkPaid($bookingRequest);

        DB::transaction(function () use ($admin, $bookingRequest, $paymentData): void {
            $paidAt = $paymentData['paid_at'] ?? now();

            $bookingRequest->forceFill([
                'payment_status' => 'paid',
                'payment_method' => $paymentData['payment_method'] ?? $paymentData['method'] ?? $bookingRequest->payment_method,
                'payment_reference' => $paymentData['payment_reference'] ?? $paymentData['reference'] ?? $bookingRequest->payment_reference,
                'paid_at' => $paidAt,
                'external_payment_provider' => $paymentData['external_payment_provider'] ?? $paymentData['provider'] ?? $bookingRequest->external_payment_provider,
                'external_payment_id' => $paymentData['external_payment_id'] ?? $paymentData['external_id'] ?? $bookingRequest->external_payment_id,
                'payment_metadata' => $paymentData['metadata'] ?? $paymentData,
                'admin_notes' => trim(($bookingRequest->admin_notes ? $bookingRequest->admin_notes."\n" : '').'Marked paid by admin #'.$admin->id),
            ])->save();

            $invoice = $bookingRequest->invoice;

            if ($invoice && $invoice->status !== 'void') {
                $invoice->forceFill([
                    'status' => 'paid',
                    'paid_at' => $paidAt,
                ])->save();
            }
        });
    }

    private function assertCanMarkPaid(AdBookingRequest $bookingRequest): void
    {
        if (! in_array($bookingRequest->payment_status, self::PAYABLE_PAYMENT_STATUSES, true)) {
            throw ValidationException::withMessages([
                'payment_status' => 'Only unpaid or pending bookings can be marked paid.',
            ]);
        }

        if ($this->isTerminalBooking($bookingRequest)) {
            throw ValidationException::withMessages([
                'status' => 'Rejected or cancelled bookings cannot be marked paid.',
            ]);
        }
    }

    public function rejectBooking(AdBookingRequest $bookingRequest, string $reason, User $admin): void
    {
        $this->assertCanRejectBooking($bookingRequest);

        DB::transaction(function () use ($admin, $bookingRequest, $reason): void {
            $bookingRequest->lineItems()
                ->with(['campaign', 'creative'])
                ->get()
                ->each(fn (AdBookingLineItem $lineItem): bool => $this->rejectLineItemInTransaction($lineItem, $reason));

            $bookingRequest->forceFill([
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'admin_notes' => $this->appendAdminNote(
                    $bookingRequest->admin_notes,
                    'Rejection reason: '.$reason.' (admin #'.$admin->id.')',
                ),
            ])->save();
        });
    }

    public function rejectLineItem(AdBookingLineItem $lineItem, string $reason, User $admin): void
    {
        $this->assertCanRejectLineItem($lineItem);

        DB::transaction(fn (): bool => $this->rejectLineItemInTransaction($lineItem, $reason));
    }

    public function createOrUpdateAdvertiser(AdBookingRequest $bookingRequest): Advertiser
    {
        $customer = $bookingRequest->customer;

        if ($customer?->advertiser) {
            return $customer->advertiser;
        }

        return Advertiser::query()->updateOrCreate(
            ['slug' => $this->uniqueAdvertiserSlug($bookingRequest)],
            [
                'name' => $bookingRequest->billing_company_name,
                'contact_name' => $bookingRequest->contact_name,
                'contact_email' => $bookingRequest->contact_email,
                'website_url' => null,
                'is_active' => true,
            ],
        );
    }

    public function createOrUpdateCustomer(AdBookingRequest $bookingRequest, Advertiser $advertiser): AdCustomer
    {
        $customer = $bookingRequest->customer ?: new AdCustomer([
            'tax_number' => $bookingRequest->billing_tax_number,
        ]);

        $customer->forceFill([
            'advertiser_id' => $advertiser->id,
            'company_name' => $bookingRequest->billing_company_name,
            'tax_number' => $bookingRequest->billing_tax_number,
            'registration_number' => $bookingRequest->billing_registration_number,
            'billing_address' => $bookingRequest->billing_address,
            'billing_city' => $bookingRequest->billing_city,
            'billing_country' => $bookingRequest->billing_country,
            'contact_name' => $bookingRequest->contact_name,
            'contact_email' => $bookingRequest->contact_email,
            'contact_phone' => $bookingRequest->contact_phone,
            'status' => 'active',
            'last_active_at' => now(),
        ])->save();

        if (! $bookingRequest->ad_customer_id) {
            $bookingRequest->forceFill(['ad_customer_id' => $customer->id])->save();
        }

        return $customer;
    }

    private function validateLineItemCreative(AdBookingLineItem $lineItem): void
    {
        if ($lineItem->creative_type !== 'image') {
            return;
        }

        if (! $lineItem->image) {
            throw ValidationException::withMessages([
                'image_id' => 'Image creative requires a media asset before publishing.',
            ]);
        }

        $this->creativeValidationService->validateImageDimensions($lineItem->zone, $lineItem->image);
    }

    private function rejectLineItemInTransaction(AdBookingLineItem $lineItem, string $reason): bool
    {
        $lineItem->loadMissing(['campaign', 'creative']);

        if ($lineItem->campaign) {
            $this->assertCampaignBelongsToManagedLineItem($lineItem->campaign, $lineItem);
        }

        if ($lineItem->creative) {
            $this->assertCreativeBelongsToManagedLineItem($lineItem->creative, $lineItem, $lineItem->campaign);
        }

        if ($lineItem->creative) {
            $lineItem->creative->forceFill(['is_active' => false])->save();
        }

        if ($lineItem->campaign) {
            $lineItem->campaign->forceFill(['status' => 'paused'])->save();
        }

        return $lineItem->forceFill([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'technical_review_required' => false,
        ])->save();
    }

    private function appendAdminNote(?string $existingNotes, string $note): string
    {
        return trim(($existingNotes ? $existingNotes."\n" : '').$note);
    }

    private function hasTerminalLineReviewParent(AdBookingLineItem $lineItem): bool
    {
        $lineItem->loadMissing('bookingRequest');

        return $lineItem->bookingRequest
            && in_array($lineItem->bookingRequest->status, self::TERMINAL_LINE_REVIEW_BOOKING_STATUSES, true);
    }

    private function assertCanRejectBooking(AdBookingRequest $bookingRequest): void
    {
        if (! $this->canRejectBooking($bookingRequest)) {
            throw ValidationException::withMessages([
                'status' => 'Rejected or cancelled bookings cannot be rejected again.',
            ]);
        }
    }

    private function assertCanRejectLineItem(AdBookingLineItem $lineItem): void
    {
        if (! $this->canRejectLineItem($lineItem)) {
            throw ValidationException::withMessages([
                'status' => 'This booking line item cannot be rejected in its current state.',
            ]);
        }
    }

    private function publishCampaign(
        AdBookingLineItem $lineItem,
        AdBookingRequest $bookingRequest,
        Advertiser $advertiser,
        User $admin,
    ): AdCampaign {
        $attributes = [
            'advertiser_id' => $advertiser->id,
            'name' => "{$bookingRequest->reference} - {$lineItem->zone->name}",
            'status' => 'active',
            'start_at' => $lineItem->start_at,
            'end_at' => $lineItem->end_at,
            'priority' => 0,
            'notes' => $this->campaignNotes($lineItem, $admin),
        ];

        if ($lineItem->ad_campaign_id) {
            $campaign = AdCampaign::query()->find($lineItem->ad_campaign_id);
            $this->assertCampaignCanBeUpdated($campaign, $lineItem, $advertiser);
            $campaign->forceFill($attributes)->save();

            return $campaign;
        }

        return AdCampaign::query()->create($attributes);
    }

    private function publishCreative(AdBookingLineItem $lineItem, AdCampaign $campaign): AdCreative
    {
        $attributes = [
            'campaign_id' => $campaign->id,
            'zone_id' => $lineItem->ad_zone_id,
            'name' => $this->creativeName($campaign, $lineItem),
            'type' => $lineItem->creative_type,
            'image_id' => $lineItem->image_id,
            'html' => $lineItem->html,
            'click_url' => $lineItem->click_url,
            'width' => $this->creativeWidth($lineItem),
            'height' => $this->creativeHeight($lineItem),
            'is_fallback' => false,
            'is_active' => true,
        ];

        if ($lineItem->ad_creative_id) {
            $creative = AdCreative::query()->find($lineItem->ad_creative_id);
            $this->assertCreativeCanBeUpdated($creative, $lineItem, $campaign);
            $creative->forceFill($attributes)->save();

            return $creative;
        }

        return AdCreative::query()->create($attributes);
    }

    private function creativeWidth(AdBookingLineItem $lineItem): int
    {
        if ($lineItem->creative_type === 'image' && $lineItem->image) {
            return (int) $lineItem->image->width;
        }

        return (int) $lineItem->width;
    }

    private function creativeHeight(AdBookingLineItem $lineItem): int
    {
        if ($lineItem->creative_type === 'image' && $lineItem->image) {
            return (int) $lineItem->image->height;
        }

        return (int) $lineItem->height;
    }

    private function assertCampaignCanBeUpdated(
        ?AdCampaign $campaign,
        AdBookingLineItem $lineItem,
        Advertiser $advertiser,
    ): void {
        $this->assertCampaignBelongsToManagedLineItem($campaign, $lineItem, $advertiser);
    }

    private function assertCampaignBelongsToManagedLineItem(
        ?AdCampaign $campaign,
        AdBookingLineItem $lineItem,
        ?Advertiser $advertiser = null,
    ): void {
        if (! $campaign) {
            throw ValidationException::withMessages([
                'ad_campaign_id' => 'Existing campaign could not be found for this booking line item.',
            ]);
        }

        $usedByAnotherLine = $campaign->bookingLineItems()
            ->whereKeyNot($lineItem->id)
            ->exists();

        if (
            ($advertiser && (int) $campaign->advertiser_id !== (int) $advertiser->id)
            || $usedByAnotherLine
            || ! $this->hasOwnershipMarkers($campaign->notes, $lineItem)
        ) {
            throw ValidationException::withMessages([
                'ad_campaign_id' => 'Existing campaign does not belong to this managed booking line item.',
            ]);
        }
    }

    private function assertCreativeCanBeUpdated(
        ?AdCreative $creative,
        AdBookingLineItem $lineItem,
        AdCampaign $campaign,
    ): void {
        $this->assertCreativeBelongsToManagedLineItem($creative, $lineItem, $campaign);
    }

    private function assertCreativeBelongsToManagedLineItem(
        ?AdCreative $creative,
        AdBookingLineItem $lineItem,
        ?AdCampaign $campaign,
    ): void {
        if (! $creative) {
            throw ValidationException::withMessages([
                'ad_creative_id' => 'Existing creative could not be found for this booking line item.',
            ]);
        }

        $usedByAnotherLine = $creative->bookingLineItems()
            ->whereKeyNot($lineItem->id)
            ->exists();

        if (
            ! $campaign
            || (int) $creative->campaign_id !== (int) $campaign->id
            || (int) $creative->zone_id !== (int) $lineItem->ad_zone_id
            || $usedByAnotherLine
            || ! $this->hasOwnershipMarkers($creative->name, $lineItem)
        ) {
            throw ValidationException::withMessages([
                'ad_creative_id' => 'Existing creative does not belong to this managed booking line item.',
            ]);
        }
    }

    private function campaignNotes(AdBookingLineItem $lineItem, User $admin): string
    {
        return implode("\n", [
            'Published from managed booking by admin #'.$admin->id,
            ...$this->ownershipMarkers($lineItem),
        ]);
    }

    private function creativeName(AdCampaign $campaign, AdBookingLineItem $lineItem): string
    {
        return $campaign->name.' creative '.$this->ownershipMarkerText($lineItem);
    }

    private function hasOwnershipMarkers(?string $value, AdBookingLineItem $lineItem): bool
    {
        if (! $value) {
            return false;
        }

        foreach ($this->ownershipMarkers($lineItem) as $marker) {
            if (! preg_match($this->ownershipMarkerPattern($marker), $value)) {
                return false;
            }
        }

        return true;
    }

    private function ownershipMarkerPattern(string $marker): string
    {
        return '/(^|[^A-Za-z0-9_:-])'.preg_quote($marker, '/').'($|[^A-Za-z0-9_:-])/';
    }

    private function ownershipMarkerText(AdBookingLineItem $lineItem): string
    {
        return implode(' ', $this->ownershipMarkers($lineItem));
    }

    private function ownershipMarkers(AdBookingLineItem $lineItem): array
    {
        return [
            'managed_booking_request:'.$lineItem->ad_booking_request_id,
            'managed_booking_line_item:'.$lineItem->id,
        ];
    }

    private function uniqueAdvertiserSlug(AdBookingRequest $bookingRequest): string
    {
        $base = Str::slug($bookingRequest->billing_company_name) ?: Str::slug($bookingRequest->reference);
        $slug = $base;
        $counter = 2;

        while (Advertiser::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
