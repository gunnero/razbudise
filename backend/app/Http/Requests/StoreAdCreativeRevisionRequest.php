<?php

namespace App\Http\Requests;

use App\Models\MediaAsset;
use App\Services\Ads\AdCreativeMediaScope;
use App\Services\Ads\AdCreativeValidationService;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdCreativeRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lineItem = $this->route('lineItem');
        $lineItem?->loadMissing('bookingRequest.customer');
        $customer = $lineItem?->bookingRequest?->customer;

        return (bool) (
            $this->user()
            && $customer
            && (int) $customer->user_id === (int) $this->user()->id
            && $customer->status === 'active'
            && ! $customer->dashboard_deactivated_at
            && ! $customer->former_customer_at
        );
    }

    public function rules(): array
    {
        return [
            'creative_type' => ['required', 'in:image,html,script'],
            'click_url' => ['nullable', 'url:http,https', 'max:255'],
            'width' => ['required', 'integer', 'min:1', 'max:5000'],
            'height' => ['required', 'integer', 'min:1', 'max:5000'],
            'html' => ['nullable', 'string', 'max:50000'],
            'image_id' => ['nullable', 'integer', 'exists:media_assets,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $creativeType = $this->input('creative_type');

            if ($creativeType === 'image' && ! $this->filled('image_id')) {
                $validator->errors()->add('image_id', 'Image creatives require an uploaded image.');
            }

            if ($creativeType !== 'image' && $this->filled('image_id')) {
                $validator->errors()->add('image_id', 'Only image creatives may include an uploaded image.');
            }

            if (in_array($creativeType, ['html', 'script'], true) && trim((string) $this->input('html', '')) === '') {
                $validator->errors()->add('html', 'HTML or script creatives require creative markup.');
            }

            if (
                $creativeType !== 'image'
                || ! $this->filled('image_id')
                || $validator->errors()->has('image_id')
                || ! $this->isIntLike($this->input('image_id'))
            ) {
                return;
            }

            $lineItem = $this->route('lineItem');
            $lineItem?->loadMissing('zone');
            $image = MediaAsset::query()->find($this->input('image_id'));

            if (! $lineItem?->zone || ! $image) {
                return;
            }

            if (! app(AdCreativeMediaScope::class)->isCustomerAdCreativeMedia($image, (int) $this->user()->id)) {
                $validator->errors()->add('image_id', 'Image must be your uploaded ad creative asset.');

                return;
            }

            $actual = [(int) $image->width, (int) $image->height];

            foreach (app(AdCreativeValidationService::class)->allowedSizes($lineItem->zone) as $allowedSize) {
                if ($allowedSize === $actual) {
                    return;
                }
            }

            $validator->errors()->add('image_id', 'Image dimensions must match one of the selected zone sizes.');
        });
    }

    private function isIntLike(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }
}
