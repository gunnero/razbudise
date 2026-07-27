<?php

namespace App\Http\Resources\Ads;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdBookingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'rejection_reason' => $this->rejection_reason,
            'subtotal_denars' => (int) $this->subtotal_denars,
            'discount_denars' => (int) $this->discount_denars,
            'tax_rate' => (float) $this->tax_rate,
            'tax_denars' => (int) $this->tax_denars,
            'total_denars' => (int) $this->total_denars,
            'hold_expires_at' => $this->hold_expires_at?->toISOString(),
            'billing' => [
                'company_name' => $this->billing_company_name,
                'tax_number' => $this->billing_tax_number,
                'registration_number' => $this->billing_registration_number,
                'address' => $this->billing_address,
                'city' => $this->billing_city,
                'country' => $this->billing_country,
            ],
            'contact' => [
                'name' => $this->contact_name,
                'email' => $this->contact_email,
                'phone' => $this->contact_phone,
            ],
            'items' => $this->lineItems->map(fn ($lineItem): array => [
                'id' => $lineItem->id,
                'zone_key' => $lineItem->zone?->key,
                'duration_weeks' => (int) $lineItem->duration_weeks,
                'start_date' => $lineItem->start_at?->toDateString(),
                'end_date' => $lineItem->end_at?->toDateString(),
                'creative_type' => $lineItem->creative_type,
                'click_url' => $lineItem->click_url,
                'width' => (int) $lineItem->width,
                'height' => (int) $lineItem->height,
                'status' => $lineItem->status,
                'rejection_reason' => $lineItem->rejection_reason,
                'price_denars' => (int) $lineItem->price_denars,
                'is_political_or_sensitive' => (bool) $lineItem->is_political_or_sensitive,
                'technical_review_required' => (bool) $lineItem->technical_review_required,
            ])->values(),
        ];
    }
}
