<?php

namespace App\Services\Ads;

use App\Models\AdBookingRequest;
use App\Models\AdCreativeMediaBlob;
use App\Models\AdCreativeUploadClaim;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\MediaAssetLifecycleService;
use App\Services\Media\MediaAssetStorageService;
use App\Services\Media\MediaAssetUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PublicAdCreativeClaimService
{
    public function __construct(
        private readonly MediaAssetUploader $uploader,
        private readonly MediaAssetLifecycleService $lifecycle,
        private readonly MediaAssetStorageService $storage,
        private readonly AdCreativeMediaScope $mediaScope,
    ) {}

    /**
     * Keep the physical media row deduplicated while issuing a separate bearer
     * claim for every upload. The raw token is returned once and never stored.
     *
     * @return array{claim: AdCreativeUploadClaim, media: MediaAsset, token: string}
     */
    public function createForUpload(UploadedFile $file, ?string $altText = null, ?User $claimant = null): array
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $createdMedia = [];

        try {
            $grant = DB::transaction(function () use ($file, $altText, $claimant, $hash, &$createdMedia): array {
                AdCreativeMediaBlob::query()->insertOrIgnore([
                    'content_sha256' => $hash,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                /** @var AdCreativeMediaBlob $blob */
                $blob = AdCreativeMediaBlob::query()->lockForUpdate()->findOrFail($hash);
                $media = $blob->media_asset_id
                    ? MediaAsset::query()->notArchived()->find($blob->media_asset_id)
                    : null;

                if (! $media || ! $this->mediaScope->isPublicBookingAdCreativeMedia($media)) {
                    $media = MediaAsset::query()
                        ->notArchived()
                        ->whereNull('uploaded_by')
                        ->where('metadata->purpose', 'ad_creative')
                        ->where('metadata->content_sha256', $hash)
                        ->latest('id')
                        ->first();
                }

                if (! $media) {
                    $media = $this->uploader->upload($file, [
                        'alt_text' => $altText,
                        'content_sha256' => $hash,
                        'purpose' => 'ad_creative',
                        'uploaded_by' => null,
                    ]);
                    $createdMedia[] = $media;
                }

                $media = MediaAsset::query()->lockForUpdate()->findOrFail($media->getKey());

                if ($media->archived_at !== null || ! $this->mediaScope->isPublicBookingAdCreativeMedia($media)) {
                    throw ValidationException::withMessages([
                        'file' => 'Сликата не може безбедно да се регистрира. Обидете се повторно.',
                    ]);
                }

                $blob->forceFill(['media_asset_id' => $media->getKey()])->save();

                return $this->issueClaim(
                    media: $media,
                    originalName: $file->getClientOriginalName(),
                    claimant: $claimant,
                );
            }, 3);
        } catch (Throwable $exception) {
            $this->removeRolledBackFiles($createdMedia);

            throw $exception;
        }

        // A deadlock retry can roll back an earlier attempt after its file was
        // written. Remove only paths that have no surviving database owner.
        $this->removeRolledBackFiles($createdMedia);

        return $grant;
    }

    /**
     * @return array{claim: AdCreativeUploadClaim, media: MediaAsset, token: string}
     */
    public function createForExisting(MediaAsset $media, User $claimant): array
    {
        abort_unless(
            $media->archived_at === null
            && $this->mediaScope->isCustomerAdCreativeMedia($media, (int) $claimant->getKey()),
            404,
        );

        return DB::transaction(function () use ($media, $claimant): array {
            $locked = MediaAsset::query()->lockForUpdate()->findOrFail($media->getKey());

            abort_unless(
                $locked->archived_at === null
                && $this->mediaScope->isCustomerAdCreativeMedia($locked, (int) $claimant->getKey()),
                404,
            );

            return $this->issueClaim(
                media: $locked,
                originalName: (string) ($locked->metadata['original_name'] ?? 'Рекламна слика'),
                claimant: $claimant,
            );
        }, 3);
    }

    public function resolveUsable(string $token): ?AdCreativeUploadClaim
    {
        $claim = AdCreativeUploadClaim::query()
            ->with('mediaAsset')
            ->where('token_hash', AdCreativeUploadClaim::digest($token))
            ->first();

        return $claim && $this->isUsableWithMedia($claim) ? $claim : null;
    }

    /**
     * Lock and revalidate all supplied claims inside the caller's booking
     * transaction. Results are keyed by booking item index.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, AdCreativeUploadClaim>
     */
    public function lockForBooking(array $items): array
    {
        $tokensByIndex = collect($items)
            ->mapWithKeys(fn (array $item, int $index): array => filled($item['image_token'] ?? null)
                ? [$index => (string) $item['image_token']]
                : [])
            ->all();

        if ($tokensByIndex === []) {
            return [];
        }

        $hashes = collect($tokensByIndex)
            ->map(fn (string $token): string => AdCreativeUploadClaim::digest($token))
            ->unique()
            ->sort()
            ->values();
        $claims = AdCreativeUploadClaim::query()
            ->whereIn('token_hash', $hashes)
            ->orderBy('token_hash')
            ->lockForUpdate()
            ->get()
            ->keyBy('token_hash');
        $media = MediaAsset::query()
            ->whereIn('id', $claims->pluck('media_asset_id')->filter()->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $resolved = [];
        $errors = [];

        foreach ($tokensByIndex as $index => $token) {
            $claim = $claims->get(AdCreativeUploadClaim::digest($token));

            if ($claim && $claim->media_asset_id) {
                $claim->setRelation('mediaAsset', $media->get($claim->media_asset_id));
            }

            if (! $claim || ! $this->isUsableWithMedia($claim)) {
                $errors["items.{$index}.image_token"] = 'Прикачената слика истече, беше отстранета или веќе е искористена. Прикачете ја повторно.';

                continue;
            }

            $submittedMediaId = $items[$index]['image_id'] ?? null;

            if (filled($submittedMediaId) && (int) $submittedMediaId !== (int) $claim->media_asset_id) {
                $errors["items.{$index}.image_id"] = 'Прикачената слика не се совпаѓа со безбедносната потврда.';

                continue;
            }

            $resolved[$index] = $claim;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /** @param array<int, AdCreativeUploadClaim> $claims */
    public function attachToBooking(array $claims, AdBookingRequest $booking): void
    {
        collect($claims)->unique('id')->each(function (AdCreativeUploadClaim $claim) use ($booking): void {
            $claim->forceFill([
                'status' => AdCreativeUploadClaim::STATUS_ATTACHED,
                'attached_at' => now(),
                'attached_booking_request_id' => $booking->getKey(),
            ])->save();
        });
    }

    /**
     * @return 'missing'|'attached'|'revoked'
     */
    public function revoke(string $token): string
    {
        return DB::transaction(function () use ($token): string {
            $claim = AdCreativeUploadClaim::query()
                ->where('token_hash', AdCreativeUploadClaim::digest($token))
                ->lockForUpdate()
                ->first();

            if (! $claim) {
                return 'missing';
            }

            if ($claim->status === AdCreativeUploadClaim::STATUS_ATTACHED || $claim->attached_booking_request_id) {
                return 'attached';
            }

            if (in_array($claim->status, [AdCreativeUploadClaim::STATUS_REVOKED, AdCreativeUploadClaim::STATUS_EXPIRED], true)) {
                return 'revoked';
            }

            $media = $claim->media_asset_id
                ? MediaAsset::query()->lockForUpdate()->find($claim->media_asset_id)
                : null;
            $claim->forceFill([
                'media_asset_id' => null,
                'status' => AdCreativeUploadClaim::STATUS_REVOKED,
                'revoked_at' => now(),
            ])->save();

            $this->deleteUnclaimedPublicMedia($media);

            return 'revoked';
        }, 3);
    }

    public function pruneExpired(): int
    {
        $pruned = 0;
        $ids = AdCreativeUploadClaim::query()
            ->where('status', AdCreativeUploadClaim::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$pruned): void {
                $claim = AdCreativeUploadClaim::query()->lockForUpdate()->find($id);

                if (! $claim || $claim->status !== AdCreativeUploadClaim::STATUS_PENDING || $claim->expires_at->isFuture()) {
                    return;
                }

                $media = $claim->media_asset_id
                    ? MediaAsset::query()->lockForUpdate()->find($claim->media_asset_id)
                    : null;
                $claim->forceFill([
                    'media_asset_id' => null,
                    'status' => AdCreativeUploadClaim::STATUS_EXPIRED,
                    'revoked_at' => now(),
                ])->save();
                $this->deleteUnclaimedPublicMedia($media);
                $pruned++;
            }, 3);
        }

        return $pruned;
    }

    private function issueClaim(MediaAsset $media, string $originalName, ?User $claimant): array
    {
        do {
            $token = Str::random(AdCreativeUploadClaim::TOKEN_LENGTH);
            $digest = AdCreativeUploadClaim::digest($token);
        } while (AdCreativeUploadClaim::query()->where('token_hash', $digest)->exists());

        $claim = AdCreativeUploadClaim::query()->create([
            'media_asset_id' => $media->getKey(),
            'claimant_user_id' => $claimant?->getKey(),
            'token_hash' => $digest,
            'original_name' => Str::limit(basename($originalName), 255, ''),
            'status' => AdCreativeUploadClaim::STATUS_PENDING,
            'expires_at' => now()->addHours((int) config('ads.public_creative_claim_ttl_hours', 24)),
        ]);

        return compact('claim', 'media', 'token');
    }

    private function isUsableWithMedia(AdCreativeUploadClaim $claim): bool
    {
        $media = $claim->mediaAsset;

        return $claim->isUsable()
            && $media instanceof MediaAsset
            && $media->archived_at === null
            && $this->mediaScope->isAdCreativeMedia($media);
    }

    private function deleteUnclaimedPublicMedia(?MediaAsset $media): void
    {
        if (! $media || ! $this->mediaScope->isPublicBookingAdCreativeMedia($media)) {
            return;
        }

        if ($media->adCreativeUploadClaims()->exists() || $media->adBookingLineItems()->exists()) {
            return;
        }

        try {
            $this->lifecycle->delete($media);
        } catch (ValidationException) {
            // Any newly discovered reference wins over automatic cleanup.
        }
    }

    /** @param array<int, MediaAsset> $createdMedia */
    private function removeRolledBackFiles(array $createdMedia): void
    {
        foreach ($createdMedia as $media) {
            $survives = MediaAsset::query()
                ->where('disk', $media->disk)
                ->where('path', $media->path)
                ->exists();

            if (! $survives) {
                $this->storage->deleteFiles($media);
            }
        }
    }
}
