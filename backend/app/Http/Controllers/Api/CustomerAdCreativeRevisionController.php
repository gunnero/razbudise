<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdCreativeRevisionRequest;
use App\Models\AdBookingLineItem;
use App\Models\MediaAsset;
use App\Services\Ads\AdCreativeValidationService;
use Illuminate\Http\JsonResponse;

class CustomerAdCreativeRevisionController extends Controller
{
    public function store(
        StoreAdCreativeRevisionRequest $request,
        AdBookingLineItem $lineItem,
        AdCreativeValidationService $creativeValidation,
    ): JsonResponse {
        $this->authorizeLineItem($request, $lineItem);

        $data = $request->validated();
        $image = $this->imageFor($data);
        $revision = $lineItem->revisions()->create([
            'submitted_by' => $request->user()->id,
            'image_id' => $data['creative_type'] === 'image' ? ($data['image_id'] ?? null) : null,
            'creative_type' => $data['creative_type'],
            'html' => $data['html'] ?? null,
            'click_url' => $data['click_url'] ?? null,
            'width' => $image ? (int) $image->width : (int) $data['width'],
            'height' => $image ? (int) $image->height : (int) $data['height'],
            'status' => 'pending_review',
            'technical_review_required' => $creativeValidation->requiresTechnicalReview($data['creative_type']),
        ]);

        return response()->json([
            'data' => [
                'id' => $revision->id,
                'line_item_id' => $lineItem->id,
                'status' => $revision->status,
                'technical_review_required' => (bool) $revision->technical_review_required,
            ],
        ], 201);
    }

    private function authorizeLineItem(StoreAdCreativeRevisionRequest $request, AdBookingLineItem $lineItem): void
    {
        $lineItem->loadMissing('bookingRequest.customer');
        $customer = $lineItem->bookingRequest?->customer;

        abort_unless(
            $customer
            && (int) $customer->user_id === (int) $request->user()->id
            && $customer->status === 'active'
            && ! $customer->dashboard_deactivated_at
            && ! $customer->former_customer_at,
            403,
        );
    }

    private function imageFor(array $data): ?MediaAsset
    {
        if (($data['creative_type'] ?? null) !== 'image' || empty($data['image_id'])) {
            return null;
        }

        return MediaAsset::query()->find($data['image_id']);
    }
}
