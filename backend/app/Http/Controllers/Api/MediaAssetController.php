<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaAssetResource;
use App\Models\MediaAsset;
use App\Services\Ads\AdCreativeMediaScope;
use App\Services\Ads\PublicAdCreativeClaimService;
use App\Services\Media\MediaAssetUploader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MediaAssetController extends Controller
{
    public function index(Request $request, AdCreativeMediaScope $mediaScope): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', MediaAsset::class);

        $filters = $request->validate([
            'query' => ['nullable', 'string', 'max:120'],
            'mime_type' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:96'],
        ]);
        $query = trim((string) ($filters['query'] ?? ''));
        $mimeType = trim((string) ($filters['mime_type'] ?? ''));

        $assets = MediaAsset::query()
            ->notArchived()
            ->tap(fn ($builder) => $mediaScope->visibleTo($builder, $request->user()))
            ->when($mimeType !== '', fn ($builder) => $builder->where('mime_type', $mimeType))
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($inner) use ($query): void {
                    $inner
                        ->where('path', 'like', "%{$query}%")
                        ->orWhere('url', 'like', "%{$query}%")
                        ->orWhere('alt_text', 'like', "%{$query}%")
                        ->orWhere('caption', 'like', "%{$query}%");
                });
            })
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 24));

        return MediaAssetResource::collection($assets);
    }

    public function store(Request $request, MediaAssetUploader $uploader): JsonResponse
    {
        Gate::authorize('create', MediaAsset::class);

        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'max:10240'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string'],
            'credit' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'url', 'max:255'],
            'focal_point_x' => ['nullable', 'integer', 'min:0', 'max:100', 'required_with:focal_point_y'],
            'focal_point_y' => ['nullable', 'integer', 'min:0', 'max:100', 'required_with:focal_point_x'],
            'purpose' => ['prohibited'],
        ]);

        $asset = $uploader->upload($data['file'], [
            'alt_text' => $data['alt_text'] ?? null,
            'caption' => $data['caption'] ?? null,
            'credit' => $data['credit'] ?? null,
            'source_url' => $data['source_url'] ?? null,
            'focal_point_x' => $data['focal_point_x'] ?? null,
            'focal_point_y' => $data['focal_point_y'] ?? null,
            'uploaded_by' => $request->user()?->id,
        ]);

        return MediaAssetResource::make($asset)
            ->response()
            ->setStatusCode(201);
    }

    public function storeCustomerAdCreative(Request $request, MediaAssetUploader $uploader): JsonResponse
    {
        $this->assertActiveCustomer($request);

        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'max:10240'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'caption' => ['nullable', 'string'],
        ]);

        $asset = $uploader->upload($data['file'], [
            'alt_text' => $data['alt_text'] ?? null,
            'caption' => $data['caption'] ?? null,
            'purpose' => 'ad_creative',
            'uploaded_by' => $request->user()->id,
        ]);

        return MediaAssetResource::make($asset)
            ->response()
            ->setStatusCode(201);
    }

    public function storePublicAdCreative(Request $request, PublicAdCreativeClaimService $claims): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ], [
            'file.max' => 'Сликата не смее да биде поголема од 5 MB.',
            'file.mimes' => 'Дозволени формати се PNG, JPG, JPEG, WEBP и GIF.',
        ]);

        $grant = $claims->createForUpload($data['file'], $data['alt_text'] ?? null, $request->user());

        return response()->json(['data' => $this->publicCreativePayload(
            $grant['media'],
            $grant['token'],
            $grant['claim']->original_name,
            includeMediaId: false,
        )], 201);
    }

    public function destroyPublicAdCreative(
        Request $request,
        PublicAdCreativeClaimService $claims,
    ): JsonResponse {
        $data = $request->validate([
            'upload_token' => ['required', 'string', 'size:48'],
        ]);
        $result = $claims->revoke($data['upload_token']);

        if ($result === 'missing') {
            abort(404);
        }

        if ($result === 'attached') {
            return response()->json(['message' => 'Креативот веќе е поврзан со резервација.'], 409);
        }

        return response()->json(['data' => null]);
    }

    public function customerAdCreatives(Request $request): JsonResponse
    {
        $this->assertActiveCustomer($request);

        $assets = MediaAsset::query()
            ->notArchived()
            ->where('uploaded_by', $request->user()->id)
            ->where(function ($builder): void {
                $builder
                    ->where('metadata->purpose', 'ad_creative')
                    ->orWhere('metadata->ad_creative_upload', true);
            })
            ->latest()
            ->limit(48)
            ->get()
            ->map(fn (MediaAsset $asset): array => $this->publicCreativePayload($asset));

        return response()->json(['data' => $assets]);
    }

    public function claimCustomerAdCreative(
        Request $request,
        MediaAsset $mediaAsset,
        PublicAdCreativeClaimService $claims,
    ): JsonResponse {
        $this->assertActiveCustomer($request);
        $grant = $claims->createForExisting($mediaAsset, $request->user());

        return response()->json(['data' => $this->publicCreativePayload(
            $grant['media'],
            $grant['token'],
            $grant['claim']->original_name,
        )], 201);
    }

    private function publicCreativePayload(
        MediaAsset $asset,
        ?string $uploadToken = null,
        ?string $originalName = null,
        bool $includeMediaId = true,
    ): array {
        $payload = [
            'upload_token' => $uploadToken,
            'url' => $asset->url,
            'thumbnail_url' => $asset->metadata['responsive_variants']['thumbnail']['url'] ?? $asset->url,
            'original_name' => $originalName ?? $asset->metadata['original_name'] ?? 'Рекламна слика',
            'mime_type' => $asset->mime_type,
            'width' => $asset->width,
            'height' => $asset->height,
            'size_bytes' => $asset->size_bytes,
        ];

        if ($includeMediaId) {
            $payload = ['media_id' => $asset->id, ...$payload];
        }

        return $payload;
    }

    private function assertActiveCustomer(Request $request): void
    {
        $customer = $request->user()
            ?->adCustomer()
            ->first();

        abort_unless(
            $customer
            && $customer->status === 'active'
            && ! $customer->dashboard_deactivated_at
            && ! $customer->former_customer_at,
            403,
        );
    }
}
