<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;

class MediaAssetReferenceAliasService
{
    public function __construct(private readonly MediaAssetUsageService $usage) {}

    public function sync(MediaAsset $asset): void
    {
        $references = collect($this->usage->searchableReferences($asset))
            ->flatMap(fn (string $reference): array => [
                $reference,
                str_contains($reference, '/') ? str_replace('/', '\\/', $reference) : null,
            ])
            ->filter()
            ->unique()
            ->values();
        $hashes = $references->map(fn (string $reference): string => hash('sha256', $reference));

        if ($references->isNotEmpty()) {
            DB::table('media_asset_reference_aliases')->upsert(
                $references->map(fn (string $reference): array => [
                    'media_asset_id' => $asset->getKey(),
                    'reference_hash' => hash('sha256', $reference),
                    'reference' => $reference,
                ])->all(),
                ['media_asset_id', 'reference_hash'],
                ['reference'],
            );
        }

        DB::table('media_asset_reference_aliases')
            ->where('media_asset_id', $asset->getKey())
            ->when(
                $hashes->isNotEmpty(),
                fn ($query) => $query->whereNotIn('reference_hash', $hashes->all()),
            )
            ->delete();
    }
}
