<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_asset_reference_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->char('reference_hash', 64);
            $table->longText('reference');
            $table->unique(['media_asset_id', 'reference_hash'], 'media_asset_reference_alias_unique');
            $table->index('media_asset_id');
        });

        DB::table('media_assets')
            ->select(['id', 'path', 'url', 'metadata'])
            ->orderBy('id')
            ->chunkById(250, function ($assets): void {
                $aliases = [];

                foreach ($assets as $asset) {
                    $metadata = json_decode((string) ($asset->metadata ?? ''), true);
                    $metadata = is_array($metadata) ? $metadata : [];
                    $references = collect([$asset->url, $asset->path])
                        ->merge(collect($metadata['responsive_variants'] ?? [])->flatMap(
                            fn ($variant): array => is_array($variant)
                                ? [$variant['url'] ?? null, $variant['path'] ?? null]
                                : [],
                        ))
                        ->merge(collect($metadata['legacy_paths'] ?? [])->flatMap(
                            fn ($reference): array => is_array($reference)
                                ? [$reference['url'] ?? null, $reference['path'] ?? null]
                                : [$reference],
                        ))
                        ->filter(fn ($reference): bool => is_string($reference) && $reference !== '')
                        ->flatMap(fn (string $reference): array => [
                            $reference,
                            ! filter_var($reference, FILTER_VALIDATE_URL) && ! str_starts_with($reference, '/storage/')
                                ? '/storage/'.ltrim($reference, '/')
                                : null,
                        ])
                        ->filter()
                        ->flatMap(fn (string $reference): array => [
                            $reference,
                            str_contains($reference, '/') ? str_replace('/', '\\/', $reference) : null,
                        ])
                        ->filter()
                        ->unique();

                    foreach ($references as $reference) {
                        $aliases[] = [
                            'media_asset_id' => $asset->id,
                            'reference_hash' => hash('sha256', $reference),
                            'reference' => $reference,
                        ];
                    }
                }

                if ($aliases !== []) {
                    DB::table('media_asset_reference_aliases')->insert($aliases);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_asset_reference_aliases');
    }
};
