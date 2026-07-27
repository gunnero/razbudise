<?php

namespace App\Services\Wordpress;

use App\Models\Article;
use App\Models\WordpressImportRecord;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class WordpressUnavailableMediaManifestService
{
    private const int SCHEMA_VERSION = 1;

    private const int MAX_MANIFEST_BYTES = 8_388_608;

    private const int MAX_INVENTORY_BYTES = 134_217_728;

    private const int MAX_INVENTORY_RECORD_BYTES = 65_536;

    private const string FEATURED_TREATMENT = 'frontend_placeholder';

    private const string INLINE_TREATMENT = 'remove_element';

    public function __construct(
        private readonly WordpressContentSanitizer $contentSanitizer,
    ) {}

    /**
     * Validate a private, reconciliation-bound unavailable-media manifest.
     *
     * @return array{
     *     sha256: string,
     *     reconciliation_sha256: string,
     *     inventory: array{path: string, sha256: string, records: int},
     *     approval: array{owner: string, approved_at_utc: string, featured_treatment: string, inline_treatment: string},
     *     totals: array{posts: int, featured: int, inline: int, inline_occurrences: int},
     *     posts: array<int, array<string, mixed>>
     * }
     */
    public function load(
        string $manifestPath,
        ?string $expectedSha256,
        string $reconciliationSha256,
        string $sourceBaseUrl,
        bool $apply,
    ): array {
        $this->assertSha256($reconciliationSha256, 'reconciliation_sha256_invalid');
        $directoryEvidence = $this->privateDirectory(dirname($manifestPath), $apply);
        $manifestPath = $this->privateRegularFile(
            $manifestPath,
            'unavailable_media_manifest',
            $directoryEvidence['owner'],
            $apply,
        );
        $size = filesize($manifestPath);

        if (! is_int($size) || $size < 1 || $size > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('unavailable_media_manifest_size_invalid');
        }

        $json = file_get_contents($manifestPath);

        if (! is_string($json)) {
            throw new RuntimeException('unavailable_media_manifest_read_failed');
        }

        try {
            $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('unavailable_media_manifest_json_invalid');
        }

        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('unavailable_media_manifest_shape_invalid');
        }

        $sha256 = hash('sha256', $json);
        $expectedSha256 = is_string($expectedSha256) && trim($expectedSha256) !== ''
            ? strtolower(trim($expectedSha256))
            : null;

        if ($apply && $expectedSha256 === null) {
            throw new RuntimeException('unavailable_media_expected_sha256_required_for_apply');
        }

        if ($expectedSha256 !== null) {
            $this->assertSha256($expectedSha256, 'unavailable_media_expected_sha256_invalid');

            if (! hash_equals($expectedSha256, $sha256)) {
                throw new RuntimeException('unavailable_media_manifest_sha256_mismatch');
            }
        }

        $this->assertExactKeys($manifest, [
            'schema_version',
            'reconciliation_sha256',
            'source_base_url',
            'inventory',
            'approval',
            'totals',
            'posts',
        ], 'unavailable_media_manifest_keys_invalid');

        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new RuntimeException('unavailable_media_schema_version_invalid');
        }

        if (($manifest['reconciliation_sha256'] ?? null) !== $reconciliationSha256) {
            throw new RuntimeException('unavailable_media_reconciliation_sha256_mismatch');
        }

        if (! is_string($sourceBaseUrl)
            || trim($sourceBaseUrl) === ''
            || ($manifest['source_base_url'] ?? null) !== $sourceBaseUrl) {
            throw new RuntimeException('unavailable_media_source_base_url_mismatch');
        }

        $this->assertSourceBaseUrl($sourceBaseUrl);
        $approval = $this->validateApproval($manifest['approval'] ?? null);
        $posts = $this->validatePosts($manifest['posts'] ?? null, $sourceBaseUrl);
        $totals = $this->totalsFor($posts);

        if (! is_array($manifest['totals'] ?? null) || array_is_list($manifest['totals'])) {
            throw new RuntimeException('unavailable_media_totals_mismatch');
        }

        $this->assertExactKeys(
            $manifest['totals'],
            ['posts', 'featured', 'inline', 'inline_occurrences'],
            'unavailable_media_totals_keys_invalid',
        );

        foreach ($totals as $key => $value) {
            if (($manifest['totals'][$key] ?? null) !== $value) {
                throw new RuntimeException('unavailable_media_totals_mismatch');
            }
        }

        $inventory = $manifest['inventory'] ?? null;

        if (! is_array($inventory) || array_is_list($inventory)) {
            throw new RuntimeException('unavailable_media_inventory_invalid');
        }

        $this->assertExactKeys(
            $inventory,
            ['filename', 'sha256', 'records'],
            'unavailable_media_inventory_keys_invalid',
        );
        $filename = $inventory['filename'] ?? null;

        if (! is_string($filename)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/D', $filename) !== 1
            || basename($filename) !== $filename) {
            throw new RuntimeException('unavailable_media_inventory_filename_invalid');
        }

        $inventorySha256 = $inventory['sha256'] ?? null;
        $inventoryRecords = $inventory['records'] ?? null;
        $this->assertSha256($inventorySha256, 'unavailable_media_inventory_sha256_invalid');

        if (! is_int($inventoryRecords) || $inventoryRecords < 0) {
            throw new RuntimeException('unavailable_media_inventory_records_invalid');
        }

        $inventoryPath = $this->privateRegularFile(
            $directoryEvidence['path'].DIRECTORY_SEPARATOR.$filename,
            'unavailable_media_inventory',
            $directoryEvidence['owner'],
            $apply,
        );
        $inventorySize = filesize($inventoryPath);

        if (! is_int($inventorySize) || $inventorySize < 0 || $inventorySize > self::MAX_INVENTORY_BYTES) {
            throw new RuntimeException('unavailable_media_inventory_size_invalid');
        }

        $unavailablePaths = [];

        foreach ($posts as $post) {
            if (isset($post['featured'])) {
                $unavailablePaths[$this->inventoryPathForUrl($post['featured']['source_url'], $sourceBaseUrl)] = true;
            }

            foreach ($post['inline'] ?? [] as $inline) {
                $unavailablePaths[$this->inventoryPathForUrl($inline['source_url'], $sourceBaseUrl)] = true;
            }
        }

        $scan = $this->scanInventory(
            $inventoryPath,
            $unavailablePaths,
            $directoryEvidence['owner'],
            $apply,
        );

        if (! hash_equals($inventorySha256, $scan['sha256'])) {
            throw new RuntimeException('unavailable_media_inventory_sha256_mismatch');
        }

        if ($scan['records'] !== $inventoryRecords) {
            throw new RuntimeException('unavailable_media_inventory_record_count_mismatch');
        }

        if ($scan['present_paths'] !== []) {
            throw new RuntimeException('unavailable_media_path_present_in_inventory');
        }

        $postManifestSha256 = hash_file('sha256', $manifestPath);

        if (! is_string($postManifestSha256) || ! hash_equals($sha256, $postManifestSha256)) {
            throw new RuntimeException('unavailable_media_manifest_changed_during_validation');
        }

        return [
            'sha256' => $sha256,
            'reconciliation_sha256' => $reconciliationSha256,
            'inventory' => [
                'path' => $inventoryPath,
                'sha256' => $inventorySha256,
                'records' => $inventoryRecords,
            ],
            'approval' => $approval,
            'totals' => $totals,
            'posts' => $posts,
        ];
    }

    /**
     * Return server-generated evidence only when the manifest post and raw
     * WordPress payload match exactly.
     *
     * @param  array<string, mixed>  $validatedManifest
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function evidenceFor(array $validatedManifest, array $payload): ?array
    {
        if ($this->hasUnavailableMediaMarker($payload)) {
            return null;
        }

        $wordpressId = $payload['id'] ?? null;

        if (! is_int($wordpressId) || $wordpressId < 1) {
            return null;
        }

        $post = $this->manifestPost($validatedManifest['posts'] ?? null, $wordpressId);

        if ($post === null
            || ! is_string($validatedManifest['sha256'] ?? null)
            || ! hash_equals(
                (string) ($post['source_payload_sha256'] ?? ''),
                $this->sourcePayloadSha256($payload),
            )) {
            return null;
        }

        $evidence = ['manifest_sha256' => $validatedManifest['sha256']];

        if (isset($post['featured'])) {
            $effectiveFeatured = $this->effectiveFeaturedMedia($payload);
            $featured = $effectiveFeatured['media'] ?? null;

            if (! is_array($featured)
                || ($effectiveFeatured['role'] ?? null) !== $post['featured']['role']
                || ($featured['id'] ?? null) !== $post['featured']['attachment_id']
                || ($featured['source_url'] ?? null) !== $post['featured']['source_url']) {
                return null;
            }

            $evidence['featured'] = $post['featured'];
        }

        if (isset($post['inline'])) {
            $preview = $this->contentSanitizer->preview(
                (string) data_get($payload, 'content.rendered', ''),
                is_array(data_get($payload, '_razbudise.blob_image_removal')),
            );
            $occurrences = $this->inlineImageOccurrences((string) ($preview['html'] ?? ''));

            foreach ($post['inline'] as $inline) {
                if (($occurrences[$inline['source_url']] ?? 0) !== $inline['occurrences']) {
                    return null;
                }
            }

            $evidence['inline'] = $post['inline'];
        }

        return $evidence;
    }

    /**
     * Build and atomically write a deterministic proposal. A frozen
     * reconciliation bundle is authoritative for media candidates; the legacy
     * path without a bundle remains limited to current retryable warnings.
     *
     * @return array<string, mixed>
     */
    public function propose(
        string $outputPath,
        string $reconciliationSha256,
        string $sourceBaseUrl,
        string $inventoryPath,
        string $owner,
        string $approvedAtUtc,
        ?string $reconciliationBundlePath = null,
    ): array {
        $this->assertSha256($reconciliationSha256, 'reconciliation_sha256_invalid');
        $this->assertSourceBaseUrl($sourceBaseUrl);
        $approval = $this->validateApproval([
            'owner' => $owner,
            'approved_at_utc' => $approvedAtUtc,
            'featured_treatment' => self::FEATURED_TREATMENT,
            'inline_treatment' => self::INLINE_TREATMENT,
        ]);
        $directoryEvidence = $this->privateDirectory(dirname($outputPath), false);
        $outputFilename = basename($outputPath);

        if ($outputFilename !== basename(trim($outputPath))
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,254}\.json\z/D', $outputFilename) !== 1) {
            throw new RuntimeException('unavailable_media_output_path_invalid');
        }

        if (file_exists($outputPath) || is_link($outputPath)) {
            throw new RuntimeException('unavailable_media_output_exists');
        }

        $inventoryPath = $this->privateRegularFile(
            $inventoryPath,
            'unavailable_media_inventory',
            $directoryEvidence['owner'],
            false,
        );

        if (dirname($inventoryPath) !== $directoryEvidence['path']) {
            throw new RuntimeException('unavailable_media_inventory_not_adjacent');
        }

        $inventorySize = filesize($inventoryPath);

        if (! is_int($inventorySize) || $inventorySize < 0 || $inventorySize > self::MAX_INVENTORY_BYTES) {
            throw new RuntimeException('unavailable_media_inventory_size_invalid');
        }

        $posts = $this->proposalPosts(
            $sourceBaseUrl,
            $reconciliationBundlePath,
            $reconciliationSha256,
            $inventoryPath,
            $directoryEvidence['owner'],
        );
        $unavailablePaths = [];

        foreach ($posts as $post) {
            if (isset($post['featured'])) {
                $unavailablePaths[$this->inventoryPathForUrl($post['featured']['source_url'], $sourceBaseUrl)] = true;
            }

            foreach ($post['inline'] ?? [] as $inline) {
                $unavailablePaths[$this->inventoryPathForUrl($inline['source_url'], $sourceBaseUrl)] = true;
            }
        }

        $scan = $this->scanInventory(
            $inventoryPath,
            $unavailablePaths,
            $directoryEvidence['owner'],
            false,
        );
        $inventorySha256 = $scan['sha256'];

        if ($scan['present_paths'] !== []) {
            throw new RuntimeException('unavailable_media_path_present_in_inventory');
        }

        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'reconciliation_sha256' => $reconciliationSha256,
            'source_base_url' => $sourceBaseUrl,
            'inventory' => [
                'filename' => basename($inventoryPath),
                'sha256' => $inventorySha256,
                'records' => $scan['records'],
            ],
            'approval' => $approval,
            'totals' => $this->totalsFor($posts),
            'posts' => $posts,
        ];
        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";

        if (strlen($json) > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('unavailable_media_manifest_size_invalid');
        }

        $temporaryPath = $directoryEvidence['path'].'/.'.$outputFilename.'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = @fopen($temporaryPath, 'xb');

        if ($handle === false) {
            throw new RuntimeException('unavailable_media_output_create_failed');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('unavailable_media_output_write_failed');
            }

            $written = 0;

            while ($written < strlen($json)) {
                $bytes = fwrite($handle, substr($json, $written));

                if (! is_int($bytes) || $bytes < 1) {
                    throw new RuntimeException('unavailable_media_output_write_failed');
                }

                $written += $bytes;
            }

            if (! fflush($handle)) {
                throw new RuntimeException('unavailable_media_output_write_failed');
            }
        } finally {
            fclose($handle);
        }

        @chmod($temporaryPath, 0440);

        $publishedPath = $directoryEvidence['path'].DIRECTORY_SEPARATOR.$outputFilename;

        if (! @link($temporaryPath, $publishedPath)) {
            @unlink($temporaryPath);

            throw new RuntimeException('unavailable_media_output_publish_failed');
        }

        @unlink($temporaryPath);

        try {
            return $this->load(
                $publishedPath,
                hash('sha256', $json),
                $reconciliationSha256,
                $sourceBaseUrl,
                false,
            );
        } catch (Throwable $exception) {
            @unlink($publishedPath);

            throw $exception;
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function proposalPosts(
        string $sourceBaseUrl,
        ?string $reconciliationBundlePath,
        string $reconciliationSha256,
        string $inventoryPath,
        int $inventoryOwner,
    ): array {
        $query = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->whereNotNull('source_payload')
            ->orderBy('wordpress_id');
        $records = ($reconciliationBundlePath === null
            ? $query->get()
            : $query->get([
                'wordpress_id',
                'wordpress_type',
                'importable_type',
                'importable_id',
                'warnings',
            ]))
            ->filter(function (WordpressImportRecord $record) use ($reconciliationBundlePath): bool {
                if ($reconciliationBundlePath !== null) {
                    return true;
                }

                $warnings = is_array($record->warnings) ? $record->warnings : [];

                return in_array('featured_image_download_failed', $warnings, true)
                    || in_array('inline_image_download_failed', $warnings, true);
            })
            ->keyBy(fn (WordpressImportRecord $record): int => (int) $record->wordpress_id);

        $inventoryMissingFeatured = [];
        $inventoryMissingInlinePaths = [];

        if ($reconciliationBundlePath !== null) {
            $candidatePaths = [];
            $featuredPathByPost = [];
            $inlinePathsByPost = [];

            foreach ($this->frozenReconciliationPayloads(
                $reconciliationBundlePath,
                $reconciliationSha256,
            ) as $payload) {
                $wordpressId = $payload['id'] ?? null;

                if (! is_int($wordpressId) || ! $records->has($wordpressId)) {
                    continue;
                }

                $effectiveFeatured = $this->effectiveFeaturedMedia($payload);
                $featured = $effectiveFeatured['media'] ?? null;

                if (is_array($featured) && is_string($featured['source_url'] ?? null)) {
                    $path = $this->inventoryPathForUrl($featured['source_url'], $sourceBaseUrl);
                    $candidatePaths[$path] = true;
                    $featuredPathByPost[$wordpressId] = $path;
                }

                $preview = $this->contentSanitizer->preview(
                    (string) data_get($payload, 'content.rendered', ''),
                    is_array(data_get($payload, '_razbudise.blob_image_removal')),
                );
                $paths = $this->inlineOccurrencesByInventoryPath(
                    $this->inlineImageOccurrences((string) ($preview['html'] ?? '')),
                    $sourceBaseUrl,
                );

                if ($paths === []) {
                    continue;
                }

                $inlinePathsByPost[$wordpressId] = array_fill_keys(array_keys($paths), true);

                foreach ($paths as $path => $_present) {
                    $candidatePaths[$path] = true;
                }
            }

            $presentPaths = array_fill_keys($this->scanInventory(
                $inventoryPath,
                $candidatePaths,
                $inventoryOwner,
                false,
            )['present_paths'], true);

            foreach ($featuredPathByPost as $wordpressId => $path) {
                if (! isset($presentPaths[$path])) {
                    $inventoryMissingFeatured[$wordpressId] = true;
                }
            }

            foreach ($inlinePathsByPost as $wordpressId => $paths) {
                foreach ($paths as $path => $_candidate) {
                    if (! isset($presentPaths[$path])) {
                        $inventoryMissingInlinePaths[$wordpressId][$path] = true;
                    }
                }
            }

            $records = $records->filter(
                function (WordpressImportRecord $record) use (
                    $inventoryMissingFeatured,
                    $inventoryMissingInlinePaths,
                ): bool {
                    return isset($inventoryMissingFeatured[(int) $record->wordpress_id])
                        || isset($inventoryMissingInlinePaths[(int) $record->wordpress_id]);
                });
        }
        $payloads = $reconciliationBundlePath === null
            ? $records->map(fn (WordpressImportRecord $record): array => is_array($record->source_payload)
                ? $record->source_payload
                : [])->all()
            : $this->frozenReconciliationPayloads(
                $reconciliationBundlePath,
                $reconciliationSha256,
            );
        $posts = [];
        $processed = [];

        foreach ($payloads as $payload) {
            $wordpressId = $payload['id'] ?? null;
            $record = is_int($wordpressId) ? $records->get($wordpressId) : null;

            if (! $record instanceof WordpressImportRecord) {
                continue;
            }

            if ($wordpressId !== (int) $record->wordpress_id || isset($processed[$wordpressId])) {
                throw new RuntimeException('unavailable_media_record_payload_id_ambiguous');
            }

            $processed[$wordpressId] = true;

            if ($this->hasUnavailableMediaMarker($payload)) {
                throw new RuntimeException('unavailable_media_record_already_marked');
            }

            $warnings = is_array($record->warnings) ? $record->warnings : [];

            $featuredMissingFromInventory = isset($inventoryMissingFeatured[$wordpressId]);
            $includeInline = $reconciliationBundlePath !== null
                ? isset($inventoryMissingInlinePaths[$wordpressId])
                : in_array('inline_image_download_failed', $warnings, true);

            if ($reconciliationBundlePath === null
                && $includeInline
                && ! in_array('manual_review', $warnings, true)) {
                throw new RuntimeException("unavailable_media_warning_state_ambiguous:{$wordpressId}");
            }

            $article = Article::query()->where('old_wordpress_id', $wordpressId)->first();

            if (! $article instanceof Article
                || $record->importable_type !== Article::class
                || (int) $record->importable_id !== (int) $article->id) {
                throw new RuntimeException("unavailable_media_article_mapping_ambiguous:{$wordpressId}");
            }

            $post = [
                'wordpress_id' => $wordpressId,
                'source_payload_sha256' => $this->sourcePayloadSha256($payload),
            ];

            $includeFeatured = $reconciliationBundlePath !== null
                ? $featuredMissingFromInventory
                : in_array('featured_image_download_failed', $warnings, true);

            if ($includeFeatured) {
                $effectiveFeatured = $this->effectiveFeaturedMedia($payload);
                $featured = $effectiveFeatured['media'] ?? null;
                $attachmentId = is_array($featured) ? ($featured['id'] ?? null) : null;

                if (! is_array($featured)
                    || ! is_int($attachmentId)
                    || $attachmentId < 1
                    || ! is_string($featured['source_url'] ?? null)) {
                    throw new RuntimeException("unavailable_media_featured_evidence_ambiguous:{$wordpressId}");
                }

                $this->inventoryPathForUrl($featured['source_url'], $sourceBaseUrl);
                $post['featured'] = [
                    'attachment_id' => $attachmentId,
                    'source_url' => $featured['source_url'],
                    'role' => $effectiveFeatured['role'],
                    'treatment' => self::FEATURED_TREATMENT,
                ];
            }

            if ($includeInline) {
                // Inline evidence is consumed after the trusted legacy markup
                // normalizer and before media rewriting. Build it from that
                // same deterministic, network-free preview stage so literal
                // Unicode URLs, their encoded form, and trusted Divi images
                // are compared exactly where removal will occur.
                $preview = $this->contentSanitizer->preview(
                    (string) data_get($payload, 'content.rendered', ''),
                    is_array(data_get($payload, '_razbudise.blob_image_removal')),
                );
                $sourceOccurrences = $this->inlineOccurrencesByInventoryPath(
                    $this->inlineImageOccurrences((string) ($preview['html'] ?? '')),
                    $sourceBaseUrl,
                );
                $candidateOccurrences = $reconciliationBundlePath === null
                    ? $this->inlineOccurrencesByInventoryPath(
                        $this->inlineImageOccurrences((string) $article->body_html),
                        $sourceBaseUrl,
                    )
                    : array_intersect_key(
                        $sourceOccurrences,
                        $inventoryMissingInlinePaths[$wordpressId],
                    );

                if ($candidateOccurrences === []) {
                    throw new RuntimeException("unavailable_media_inline_evidence_ambiguous:{$wordpressId}");
                }

                $post['inline'] = [];

                foreach ($candidateOccurrences as $inventoryPath => $candidateUrls) {
                    if (count($candidateUrls) !== 1) {
                        if ($reconciliationBundlePath !== null) {
                            throw new RuntimeException("unavailable_media_inline_source_path_ambiguous:{$wordpressId}");
                        }

                        throw new RuntimeException("unavailable_media_inline_current_path_ambiguous:{$wordpressId}");
                    }

                    $sourceUrls = $sourceOccurrences[$inventoryPath] ?? [];

                    if ($sourceUrls === []) {
                        throw new RuntimeException("unavailable_media_inline_source_path_mismatch:{$wordpressId}");
                    }

                    if (count($sourceUrls) !== 1) {
                        throw new RuntimeException("unavailable_media_inline_source_path_ambiguous:{$wordpressId}");
                    }

                    $candidateUrl = array_key_first($candidateUrls);
                    $sourceUrl = array_key_first($sourceUrls);
                    $occurrences = $candidateUrls[$candidateUrl];

                    if ($sourceUrls[$sourceUrl] !== $occurrences) {
                        throw new RuntimeException("unavailable_media_inline_occurrences_ambiguous:{$wordpressId}");
                    }

                    $post['inline'][] = [
                        'source_url' => $sourceUrl,
                        'occurrences' => $occurrences,
                        'role' => 'inline',
                        'treatment' => self::INLINE_TREATMENT,
                    ];
                }

                usort(
                    $post['inline'],
                    fn (array $left, array $right): int => strcmp($left['source_url'], $right['source_url']),
                );
            }

            $posts[] = $post;
        }

        $missing = $records->keys()
            ->reject(fn (int $wordpressId): bool => isset($processed[$wordpressId]))
            ->values();

        if ($missing->isNotEmpty()) {
            throw new RuntimeException('unavailable_media_reconciliation_payload_missing:'.$missing->first());
        }

        if ($posts === []) {
            throw new RuntimeException('unavailable_media_retryable_records_empty');
        }

        usort(
            $posts,
            fn (array $left, array $right): int => $left['wordpress_id'] <=> $right['wordpress_id'],
        );

        return $posts;
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function frozenReconciliationPayloads(
        string $bundlePath,
        string $reconciliationSha256,
    ): \Generator {
        $storageRoot = realpath(storage_path('app'));
        $realBundlePath = realpath($bundlePath);

        if (! is_string($storageRoot)
            || ! is_string($realBundlePath)
            || ! str_starts_with($realBundlePath, $storageRoot.DIRECTORY_SEPARATOR)
            || is_link($bundlePath)
            || ! is_dir($realBundlePath)) {
            throw new RuntimeException('unavailable_media_reconciliation_bundle_invalid');
        }

        $metadataPath = $realBundlePath.'/metadata.json';

        if (is_link($metadataPath) || ! is_file($metadataPath)) {
            throw new RuntimeException('unavailable_media_reconciliation_metadata_invalid');
        }

        $metadataJson = file_get_contents($metadataPath);

        if (! is_string($metadataJson)
            || ! hash_equals($reconciliationSha256, hash('sha256', $metadataJson))) {
            throw new RuntimeException('unavailable_media_reconciliation_metadata_mismatch');
        }

        try {
            $metadata = json_decode($metadataJson, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('unavailable_media_reconciliation_metadata_invalid');
        }

        foreach ([
            'published_posts' => 'published-posts.ndjson',
            'nonpublic_posts' => 'nonpublic-posts.ndjson',
        ] as $section => $expectedFilename) {
            $descriptor = data_get($metadata, "files.{$section}");

            if (! is_array($descriptor)
                || ($descriptor['filename'] ?? null) !== $expectedFilename
                || ! is_int($descriptor['records'] ?? null)
                || $descriptor['records'] < 0) {
                throw new RuntimeException("unavailable_media_reconciliation_{$section}_invalid");
            }

            $this->assertSha256(
                $descriptor['sha256'] ?? null,
                "unavailable_media_reconciliation_{$section}_invalid",
            );
            $path = $realBundlePath.'/'.$expectedFilename;

            if (is_link($path) || ! is_file($path) || realpath(dirname($path)) !== $realBundlePath) {
                throw new RuntimeException("unavailable_media_reconciliation_{$section}_invalid");
            }

            $handle = fopen($path, 'rb');

            if (! is_resource($handle) || ! flock($handle, LOCK_SH)) {
                if (is_resource($handle)) {
                    fclose($handle);
                }

                throw new RuntimeException("unavailable_media_reconciliation_{$section}_read_failed");
            }

            $hash = hash_init('sha256');
            $records = 0;

            try {
                while (($line = fgets($handle)) !== false) {
                    if (strlen($line) > self::MAX_INVENTORY_RECORD_BYTES * 256
                        || ! str_ends_with($line, "\n")) {
                        throw new RuntimeException("unavailable_media_reconciliation_{$section}_record_invalid");
                    }

                    hash_update($hash, $line);

                    try {
                        $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    } catch (Throwable) {
                        throw new RuntimeException("unavailable_media_reconciliation_{$section}_record_invalid");
                    }

                    $payload = $section === 'nonpublic_posts'
                        ? ($row['payload'] ?? null)
                        : $row;

                    if (! is_array($payload) || ! is_int($payload['id'] ?? null)) {
                        throw new RuntimeException("unavailable_media_reconciliation_{$section}_record_invalid");
                    }

                    $records++;
                    yield $payload;
                }

                if (! feof($handle)
                    || $records !== $descriptor['records']
                    || ! hash_equals($descriptor['sha256'], hash_final($hash))) {
                    throw new RuntimeException("unavailable_media_reconciliation_{$section}_mismatch");
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function validatePosts(mixed $posts, string $sourceBaseUrl): array
    {
        if (! is_array($posts) || ! array_is_list($posts) || $posts === []) {
            throw new RuntimeException('unavailable_media_posts_invalid');
        }

        $validated = [];
        $previousWordpressId = 0;

        foreach ($posts as $index => $post) {
            if (! is_array($post) || array_is_list($post)) {
                throw new RuntimeException("unavailable_media_post_invalid:{$index}");
            }

            $keys = ['wordpress_id', 'source_payload_sha256'];

            if (array_key_exists('featured', $post)) {
                $keys[] = 'featured';
            }

            if (array_key_exists('inline', $post)) {
                $keys[] = 'inline';
            }

            $this->assertExactKeys($post, $keys, "unavailable_media_post_keys_invalid:{$index}");
            $wordpressId = $post['wordpress_id'] ?? null;

            if (! is_int($wordpressId) || $wordpressId <= $previousWordpressId) {
                throw new RuntimeException("unavailable_media_post_order_invalid:{$index}");
            }

            $this->assertSha256(
                $post['source_payload_sha256'] ?? null,
                "unavailable_media_source_payload_sha256_invalid:{$wordpressId}",
            );

            if (! array_key_exists('featured', $post) && ! array_key_exists('inline', $post)) {
                throw new RuntimeException("unavailable_media_post_evidence_empty:{$wordpressId}");
            }

            $validatedPost = [
                'wordpress_id' => $wordpressId,
                'source_payload_sha256' => $post['source_payload_sha256'],
            ];

            if (array_key_exists('featured', $post)) {
                $validatedPost['featured'] = $this->validateFeatured($post['featured'], $sourceBaseUrl, $wordpressId);
            }

            if (array_key_exists('inline', $post)) {
                $validatedPost['inline'] = $this->validateInline($post['inline'], $sourceBaseUrl, $wordpressId);
            }

            $validated[] = $validatedPost;
            $previousWordpressId = $wordpressId;
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private function validateFeatured(mixed $featured, string $sourceBaseUrl, int $wordpressId): array
    {
        if (! is_array($featured) || array_is_list($featured)) {
            throw new RuntimeException("unavailable_media_featured_invalid:{$wordpressId}");
        }

        $this->assertExactKeys(
            $featured,
            ['attachment_id', 'source_url', 'role', 'treatment'],
            "unavailable_media_featured_keys_invalid:{$wordpressId}",
        );

        if (! is_int($featured['attachment_id'] ?? null) || $featured['attachment_id'] < 1
            || ! in_array($featured['role'] ?? null, ['featured', 'featured_fallback'], true)
            || ($featured['treatment'] ?? null) !== self::FEATURED_TREATMENT
            || ! is_string($featured['source_url'] ?? null)) {
            throw new RuntimeException("unavailable_media_featured_evidence_invalid:{$wordpressId}");
        }

        $this->inventoryPathForUrl($featured['source_url'], $sourceBaseUrl);

        return $featured;
    }

    /** @return array<int, array<string, mixed>> */
    private function validateInline(mixed $inline, string $sourceBaseUrl, int $wordpressId): array
    {
        if (! is_array($inline) || ! array_is_list($inline) || $inline === []) {
            throw new RuntimeException("unavailable_media_inline_invalid:{$wordpressId}");
        }

        $validated = [];
        $previousUrl = null;

        foreach ($inline as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new RuntimeException("unavailable_media_inline_item_invalid:{$wordpressId}:{$index}");
            }

            $this->assertExactKeys(
                $item,
                ['source_url', 'occurrences', 'role', 'treatment'],
                "unavailable_media_inline_item_keys_invalid:{$wordpressId}:{$index}",
            );
            $sourceUrl = $item['source_url'] ?? null;

            if (! is_string($sourceUrl)
                || ($item['role'] ?? null) !== 'inline'
                || ($item['treatment'] ?? null) !== self::INLINE_TREATMENT
                || ! is_int($item['occurrences'] ?? null)
                || $item['occurrences'] < 1
                || ($previousUrl !== null && strcmp($previousUrl, $sourceUrl) >= 0)) {
                throw new RuntimeException("unavailable_media_inline_evidence_invalid:{$wordpressId}:{$index}");
            }

            $this->inventoryPathForUrl($sourceUrl, $sourceBaseUrl);
            $validated[] = $item;
            $previousUrl = $sourceUrl;
        }

        return $validated;
    }

    /** @return array{owner: string, approved_at_utc: string, featured_treatment: string, inline_treatment: string} */
    private function validateApproval(mixed $approval): array
    {
        if (! is_array($approval) || array_is_list($approval)) {
            throw new RuntimeException('unavailable_media_approval_invalid');
        }

        $this->assertExactKeys($approval, [
            'owner',
            'approved_at_utc',
            'featured_treatment',
            'inline_treatment',
        ], 'unavailable_media_approval_keys_invalid');
        $owner = $approval['owner'] ?? null;

        if (! is_string($owner)
            || $owner !== trim($owner)
            || $owner === ''
            || strlen($owner) > 200
            || preg_match('/[\x00-\x1F\x7F]/', $owner) === 1) {
            throw new RuntimeException('unavailable_media_approval_owner_invalid');
        }

        if (! $this->isUtcTimestamp($approval['approved_at_utc'] ?? null)) {
            throw new RuntimeException('unavailable_media_approval_timestamp_invalid');
        }

        if (($approval['featured_treatment'] ?? null) !== self::FEATURED_TREATMENT
            || ($approval['inline_treatment'] ?? null) !== self::INLINE_TREATMENT) {
            throw new RuntimeException('unavailable_media_approval_treatment_invalid');
        }

        return $approval;
    }

    /**
     * @param  array<int, array<string, mixed>>  $posts
     * @return array{posts: int, featured: int, inline: int, inline_occurrences: int}
     */
    private function totalsFor(array $posts): array
    {
        $featured = 0;
        $inline = 0;
        $inlineOccurrences = 0;

        foreach ($posts as $post) {
            $featured += isset($post['featured']) ? 1 : 0;
            $inline += count($post['inline'] ?? []);

            foreach ($post['inline'] ?? [] as $item) {
                $inlineOccurrences += (int) $item['occurrences'];
            }
        }

        return [
            'posts' => count($posts),
            'featured' => $featured,
            'inline' => $inline,
            'inline_occurrences' => $inlineOccurrences,
        ];
    }

    /** @return array<string, mixed>|null */
    private function manifestPost(mixed $posts, int $wordpressId): ?array
    {
        if (! is_array($posts) || ! array_is_list($posts)) {
            return null;
        }

        $low = 0;
        $high = count($posts) - 1;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $post = $posts[$middle] ?? null;

            if (! is_array($post) || ! is_int($post['wordpress_id'] ?? null)) {
                return null;
            }

            if ($post['wordpress_id'] === $wordpressId) {
                return $post;
            }

            if ($post['wordpress_id'] < $wordpressId) {
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return null;
    }

    private function sourcePayloadSha256(array $payload): string
    {
        unset($payload['_razbudise']);

        return hash('sha256', json_encode(
            $this->sortRecursively($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * Use the raw WordPress featured attachment when present. A separately
     * validated frozen fallback decision may supply the effective attachment
     * only when WordPress declared no featured_media ID.
     *
     * @param  array<string, mixed>  $payload
     * @return array{role: 'featured'|'featured_fallback', media: array<string, mixed>}|null
     */
    private function effectiveFeaturedMedia(array $payload): ?array
    {
        $featuredMediaId = $payload['featured_media'] ?? null;

        if (is_int($featuredMediaId) && $featuredMediaId > 0) {
            $featured = data_get($payload, '_embedded.wp:featuredmedia.0');

            return is_array($featured) && ($featured['id'] ?? null) === $featuredMediaId
                ? ['role' => 'featured', 'media' => $featured]
                : null;
        }

        if ($featuredMediaId === 0) {
            $fallback = data_get($payload, '_razbudise.featured_image_fallback.media');

            return is_array($fallback)
                ? ['role' => 'featured_fallback', 'media' => $fallback]
                : null;
        }

        return null;
    }

    /** @return array<string, int> */
    private function inlineImageOccurrences(string $html): array
    {
        $occurrences = [];
        preg_match_all('/<img\b[^>]*\bsrc=(["\'])([^"\']+)\1[^>]*>/i', $html, $matches);

        foreach ($matches[2] ?? [] as $source) {
            $source = html_entity_decode((string) $source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $occurrences[$source] = ($occurrences[$source] ?? 0) + 1;
        }

        return $occurrences;
    }

    /**
     * Group exact image URL representations by their decoded upload inventory
     * path. Keeping the exact URLs in each group lets proposal generation reject
     * canonical collisions instead of choosing an arbitrary representation.
     *
     * @param  array<string, int>  $occurrences
     * @return array<string, array<string, int>>
     */
    private function inlineOccurrencesByInventoryPath(array $occurrences, string $sourceBaseUrl): array
    {
        $grouped = [];

        foreach ($occurrences as $sourceUrl => $count) {
            if (! str_contains($sourceUrl, '/wp-content/uploads/') || $count < 1) {
                continue;
            }

            $inventoryPath = $this->inventoryPathForUrl($sourceUrl, $sourceBaseUrl);
            $grouped[$inventoryPath][$sourceUrl] = $count;
        }

        ksort($grouped, SORT_STRING);

        foreach ($grouped as &$urls) {
            ksort($urls, SORT_STRING);
        }
        unset($urls);

        return $grouped;
    }

    private function inventoryPathForUrl(string $sourceUrl, string $sourceBaseUrl): string
    {
        $prefix = rtrim($sourceBaseUrl, '/').'/wp-content/uploads/';

        if ($sourceUrl === trim($sourceUrl)
            && $sourceUrl !== ''
            && preg_match('/[\x00-\x1F\x7F]/', $sourceUrl) !== 1
            && str_starts_with($sourceUrl, $prefix)) {
            // parse_url() replaces some UTF-8 continuation bytes with
            // underscores. Slice the already origin-bound URL directly so a
            // literal Cyrillic path canonicalizes to the same bytes as its
            // percent-encoded representation.
            $relative = substr($sourceUrl, strlen($prefix));

            if ($relative !== ''
                && ! str_contains($relative, '?')
                && ! str_contains($relative, '#')
                && preg_match('/%(?![A-Fa-f0-9]{2})/', $relative) !== 1) {
                $segments = explode('/', $relative);
                $decoded = [];

                foreach ($segments as $segment) {
                    $segment = rawurldecode($segment);

                    if ($segment === ''
                        || $segment === '.'
                        || $segment === '..'
                        || str_contains($segment, '/')
                        || str_contains($segment, '\\')
                        || preg_match('/[\x00\t\r\n]/', $segment) === 1) {
                        throw new RuntimeException('unavailable_media_source_url_path_invalid');
                    }

                    $decoded[] = $segment;
                }

                return implode('/', $decoded);
            }
        }

        throw new RuntimeException('unavailable_media_source_url_invalid');
    }

    private function assertSourceBaseUrl(string $sourceBaseUrl): void
    {
        $parts = parse_url($sourceBaseUrl);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/')
            || $sourceBaseUrl !== rtrim($sourceBaseUrl, '/')) {
            throw new RuntimeException('unavailable_media_source_base_url_invalid');
        }
    }

    /**
     * @param  array<string, bool>  $unavailablePaths
     * @return array{sha256: string, records: int, present_paths: array<int, string>}
     */
    private function scanInventory(
        string $path,
        array $unavailablePaths,
        int $expectedOwner,
        bool $immutable,
    ): array {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('unavailable_media_inventory_read_failed');
        }

        try {
            $this->assertOpenedInventoryPrivate($handle, $path, $expectedOwner, $immutable);

            if (! flock($handle, LOCK_SH)) {
                throw new RuntimeException('unavailable_media_inventory_lock_failed');
            }

            return $this->scanInventoryStream($handle, $unavailablePaths);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Hash and parse the same opened byte stream. The result cannot combine a
     * checksum from one pathname target with absence evidence from another.
     *
     * @param  resource  $handle
     * @param  array<string, bool>  $unavailablePaths
     * @return array{sha256: string, records: int, present_paths: array<int, string>}
     */
    private function scanInventoryStream($handle, array $unavailablePaths): array
    {
        $context = hash_init('sha256');
        $buffer = '';
        $delimiter = null;
        $records = 0;
        $lastPath = null;
        $present = [];
        $bytes = 0;

        while (true) {
            $chunk = fread($handle, 65_536);

            if (! is_string($chunk)) {
                throw new RuntimeException('unavailable_media_inventory_read_failed');
            }

            if ($chunk !== '') {
                $bytes += strlen($chunk);

                if ($bytes > self::MAX_INVENTORY_BYTES) {
                    throw new RuntimeException('unavailable_media_inventory_size_invalid');
                }

                hash_update($context, $chunk);
                $buffer .= $chunk;
            }

            if ($delimiter === null) {
                if (str_contains($buffer, "\0")) {
                    $delimiter = "\0";
                } elseif (str_contains($buffer, "\n")) {
                    $delimiter = "\n";
                }
            }

            if ($delimiter !== null) {
                while (($position = strpos($buffer, $delimiter)) !== false) {
                    $row = substr($buffer, 0, $position);
                    $buffer = substr($buffer, $position + 1);
                    $pathValue = $this->validateInventoryRow($row, $records + 1);

                    if ($lastPath !== null && strcmp($lastPath, $pathValue) >= 0) {
                        throw new RuntimeException('unavailable_media_inventory_order_invalid');
                    }

                    if (isset($unavailablePaths[$pathValue])) {
                        $present[$pathValue] = true;
                    }

                    $lastPath = $pathValue;
                    $records++;
                }
            }

            if (strlen($buffer) > self::MAX_INVENTORY_RECORD_BYTES) {
                throw new RuntimeException('unavailable_media_inventory_record_too_large');
            }

            if (feof($handle)) {
                break;
            }

            if ($chunk === '') {
                throw new RuntimeException('unavailable_media_inventory_read_failed');
            }
        }

        if ($buffer !== '') {
            throw new RuntimeException('unavailable_media_inventory_unterminated_record');
        }

        return [
            'sha256' => hash_final($context),
            'records' => $records,
            'present_paths' => array_keys($present),
        ];
    }

    /** @param resource $handle */
    private function assertOpenedInventoryPrivate(
        $handle,
        string $path,
        int $expectedOwner,
        bool $immutable,
    ): void {
        $opened = fstat($handle);
        clearstatcache(true, $path);
        $pathStat = lstat($path);

        if (! is_array($opened)
            || ! is_array($pathStat)
            || is_link($path)
            || ! isset(
                $opened['dev'],
                $opened['ino'],
                $opened['mode'],
                $opened['uid'],
                $opened['size'],
                $pathStat['dev'],
                $pathStat['ino'],
            )
            || $opened['dev'] !== $pathStat['dev']
            || $opened['ino'] !== $pathStat['ino']
            || ($opened['mode'] & 0170000) !== 0100000
            || $opened['uid'] !== $expectedOwner
            || $opened['size'] < 0
            || $opened['size'] > self::MAX_INVENTORY_BYTES
            || (($opened['mode'] & 0777) & 0137) !== 0) {
            throw new RuntimeException('unavailable_media_inventory_opened_file_invalid');
        }

        if ($immutable && (($opened['mode'] & 0777) & 0222) !== 0) {
            throw new RuntimeException('unavailable_media_inventory_not_immutable');
        }
    }

    private function validateInventoryRow(string $row, int $record): string
    {
        $fields = explode("\t", $row);

        if (count($fields) !== 3
            || $fields[0] === ''
            || str_starts_with($fields[0], '/')
            || str_contains($fields[0], '\\')
            || str_contains($fields[0], "\0")
            || str_contains($fields[0], "\r")
            || str_contains($fields[0], "\n")
            || in_array('.', explode('/', $fields[0]), true)
            || in_array('..', explode('/', $fields[0]), true)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $fields[1]) !== 1
            || preg_match('/\A(?:0|[1-9][0-9]*)\.[0-9]+\z/D', $fields[2]) !== 1) {
            throw new RuntimeException("unavailable_media_inventory_record_invalid:{$record}");
        }

        return $fields[0];
    }

    /** @return array{path: string, owner: int} */
    private function privateDirectory(string $path, bool $immutable): array
    {
        $realPath = realpath($path);
        $storageRoot = realpath(storage_path('app'));

        if (! is_string($realPath)
            || ! is_string($storageRoot)
            || ! str_starts_with($realPath, $storageRoot.DIRECTORY_SEPARATOR)
            || is_link($path)
            || ! is_dir($realPath)
            || ! is_readable($realPath)) {
            throw new RuntimeException('unavailable_media_private_directory_invalid');
        }

        $permissions = fileperms($realPath);
        $owner = fileowner($realPath);

        if (! is_int($permissions) || (($permissions & 0777) & 0027) !== 0) {
            throw new RuntimeException('unavailable_media_directory_permissions_too_broad');
        }

        if ($immutable && (($permissions & 0777) & 0222) !== 0) {
            throw new RuntimeException('unavailable_media_directory_not_immutable');
        }

        if (! is_int($owner)) {
            throw new RuntimeException('unavailable_media_directory_owner_invalid');
        }

        return ['path' => $realPath, 'owner' => $owner];
    }

    private function privateRegularFile(
        string $path,
        string $label,
        int $expectedOwner,
        bool $immutable,
    ): string {
        $realPath = realpath($path);
        $storageRoot = realpath(storage_path('app'));

        if (! is_string($realPath)
            || ! is_string($storageRoot)
            || ! str_starts_with($realPath, $storageRoot.DIRECTORY_SEPARATOR)
            || is_link($path)
            || ! is_file($realPath)
            || ! is_readable($realPath)) {
            throw new RuntimeException("{$label}_private_file_invalid");
        }

        $permissions = fileperms($realPath);
        $owner = fileowner($realPath);

        if (! is_int($permissions) || (($permissions & 0777) & 0137) !== 0) {
            throw new RuntimeException("{$label}_permissions_too_broad");
        }

        if ($immutable && (($permissions & 0777) & 0222) !== 0) {
            throw new RuntimeException("{$label}_not_immutable");
        }

        if (! is_int($owner) || $owner !== $expectedOwner) {
            throw new RuntimeException("{$label}_owner_invalid");
        }

        return $realPath;
    }

    /** @param array<string, mixed> $value */
    private function assertExactKeys(array $value, array $expected, string $error): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new RuntimeException($error);
        }
    }

    private function assertSha256(mixed $value, string $error): void
    {
        if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new RuntimeException($error);
        }
    }

    /** @param array<string, mixed> $payload */
    private function hasUnavailableMediaMarker(array $payload): bool
    {
        return is_array($payload['_razbudise'] ?? null)
            && array_key_exists('unavailable_media', $payload['_razbudise']);
    }

    private function isUtcTimestamp(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value) !== 1) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value);

        return $parsed instanceof DateTimeImmutable
            && $parsed->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }
}
