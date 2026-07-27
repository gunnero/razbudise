<?php

namespace App\Http\Requests;

use App\Models\AdPrice;
use App\Models\AdZone;
use App\Models\MediaAsset;
use App\Services\Ads\AdCreativeValidationService;
use App\Services\Ads\PublicAdCreativeClaimService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class StoreAdBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing.company_name' => ['required', 'string', 'max:255'],
            'billing.tax_number' => ['required', 'string', 'max:64'],
            'billing.registration_number' => ['nullable', 'string', 'max:64'],
            'billing.address' => ['required', 'string', 'max:255'],
            'billing.city' => ['required', 'string', 'max:255'],
            'billing.country' => ['required', 'string', 'size:2'],
            'contact.name' => ['required', 'string', 'max:255'],
            'contact.email' => ['required', 'email', 'max:255'],
            'contact.phone' => ['nullable', 'string', 'max:64'],
            'discount_code' => ['nullable', 'string', 'max:64'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*.zone_key' => ['required', 'string', 'exists:ad_zones,key'],
            'items.*.duration_weeks' => ['required', 'integer', 'in:1,2,4'],
            'items.*.start_date' => ['required', 'date', 'after_or_equal:today'],
            'items.*.creative_type' => ['required', 'in:image,html,script'],
            'items.*.click_url' => ['nullable', 'url:http,https', 'max:255'],
            'items.*.width' => ['required', 'integer', 'min:1', 'max:5000'],
            'items.*.height' => ['required', 'integer', 'min:1', 'max:5000'],
            'items.*.html' => ['nullable', 'string', 'max:50000'],
            // Kept for client compatibility only. The media row is derived from
            // the opaque upload claim and never trusted from this value.
            'items.*.image_id' => ['nullable', 'integer'],
            'items.*.image_token' => ['nullable', 'string', 'size:48'],
            'items.*.is_political_or_sensitive' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! is_array($this->input('items'))) {
                return;
            }

            $items = $this->input('items', []);
            $zones = AdZone::query()
                ->whereIn('key', collect($items)
                    ->pluck('zone_key')
                    ->filter(fn (mixed $zoneKey): bool => is_scalar($zoneKey))
                    ->unique())
                ->get()
                ->keyBy('key');
            $creativeValidation = app(AdCreativeValidationService::class);
            $creativeClaims = app(PublicAdCreativeClaimService::class);
            $windows = [];

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $creativeType = $item['creative_type'] ?? null;

                if ($creativeType !== 'image' && ! empty($item['image_id'])) {
                    $validator->errors()->add("items.{$index}.image_id", 'Only image creatives may include an uploaded image.');
                }

                if ($creativeType !== 'image' && ! empty($item['image_token'])) {
                    $validator->errors()->add("items.{$index}.image_token", 'Only image creatives may include an uploaded image.');
                }

                if ($creativeType === 'image' && ! empty($item['image_id']) && empty($item['image_token'])) {
                    $validator->errors()->add("items.{$index}.image_token", 'Потребна е безбедносна потврда за прикачената слика.');
                    $validator->errors()->add("items.{$index}.image_id", 'Image is not available for this booking.');
                }

                if (in_array($creativeType, ['html', 'script'], true) && trim((string) ($item['html'] ?? '')) === '') {
                    $validator->errors()->add("items.{$index}.html", 'HTML or script creatives require creative markup.');
                }

                $zoneKey = $item['zone_key'] ?? null;
                $zone = is_scalar($zoneKey) ? $zones->get($zoneKey) : null;

                if (! $zone || ! $zone->is_active) {
                    if (! $validator->errors()->has("items.{$index}.zone_key")) {
                        $validator->errors()->add("items.{$index}.zone_key", 'Selected ad zone is not active.');
                    }

                    continue;
                }

                if (
                    ! $validator->errors()->has("items.{$index}.duration_weeks")
                    && ! $validator->errors()->has("items.{$index}.creative_type")
                    && ! $this->hasActivePrice($zone, $item)
                ) {
                    $validator->errors()->add("items.{$index}.duration_weeks", 'No active ad price exists for the selected zone, duration, and creative type.');
                }

                if (
                    $creativeType === 'image'
                    && ! empty($item['image_token'])
                    && ! $validator->errors()->has("items.{$index}.image_token")
                    && is_string($item['image_token'])
                    && strlen($item['image_token']) === 48
                ) {
                    $claim = $creativeClaims->resolveUsable($item['image_token']);
                    $image = $claim?->mediaAsset;

                    if (! $claim || ! $image) {
                        $validator->errors()->add("items.{$index}.image_token", 'Прикачената слика истече, беше отстранета или веќе е искористена. Прикачете ја повторно.');
                    } elseif (! empty($item['image_id']) && (int) $item['image_id'] !== (int) $image->getKey()) {
                        $validator->errors()->add("items.{$index}.image_id", 'Прикачената слика не се совпаѓа со безбедносната потврда.');
                    } elseif (! $this->imageFitsZone($creativeValidation, $zone, $image)) {
                        $validator->errors()->add("items.{$index}.image_id", 'Image dimensions must match one of the selected zone sizes.');
                    }
                }

                if (
                    ! isset($item['start_date'], $item['duration_weeks'])
                    || $validator->errors()->has("items.{$index}.start_date")
                    || $validator->errors()->has("items.{$index}.duration_weeks")
                ) {
                    continue;
                }

                try {
                    $start = CarbonImmutable::parse($item['start_date'])->startOfDay();
                    $end = $start->addWeeks((int) $item['duration_weeks']);
                } catch (\Throwable) {
                    continue;
                }

                foreach ($windows as $window) {
                    if ($window['zone_key'] !== $zone->key) {
                        continue;
                    }

                    if ($start->lt($window['end']) && $end->gt($window['start'])) {
                        $validator->errors()->add("items.{$index}.start_date", 'Booking items for the same zone cannot overlap.');

                        break;
                    }
                }

                $windows[] = [
                    'zone_key' => $zone->key,
                    'start' => $start,
                    'end' => $end,
                ];
            }
        });
    }

    private function hasActivePrice(AdZone $zone, array $item): bool
    {
        if (! isset($item['duration_weeks'], $item['creative_type'])) {
            return false;
        }

        return AdPrice::query()
            ->where('ad_zone_id', $zone->id)
            ->where('duration_weeks', (int) $item['duration_weeks'])
            ->where('creative_type', $item['creative_type'])
            ->where('is_active', true)
            ->exists();
    }

    private function imageFitsZone(AdCreativeValidationService $creativeValidation, AdZone $zone, MediaAsset $image): bool
    {
        $actual = [(int) $image->width, (int) $image->height];

        foreach ($creativeValidation->allowedSizes($zone) as $allowedSize) {
            if ($allowedSize === $actual) {
                return true;
            }
        }

        return false;
    }
}
