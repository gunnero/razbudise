<?php

namespace App\Services\Wordpress;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Redirect;
use App\Models\Tag;
use App\Models\WordpressImportMapping;
use App\Models\WordpressImportRecord;
use App\Support\UrlPath;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class WordpressContentReconciliationService
{
    private int $maxChunkRecordsObserved = 0;

    private int $maxChunkBytesObserved = 0;

    private const int SCHEMA_VERSION = 1;

    private const int MAX_METADATA_BYTES = 1_000_000;

    private const int MAX_RECORD_BYTES = 8_388_608;

    private const int CHUNK_BYTES = 8_388_608;

    private const int CHUNK_RECORDS = 50;

    private const string FROZEN_TAXONOMY_EVIDENCE_VERSION = '2026-07-15.1';

    /** @var array<string, string> */
    private const array SECTION_FILES = [
        'published_posts' => 'published-posts.ndjson',
        'nonpublic_posts' => 'nonpublic-posts.ndjson',
        'auto_drafts' => 'auto-drafts.ndjson',
        'categories' => 'categories.ndjson',
        'tags' => 'tags.ndjson',
        'authors' => 'authors.ndjson',
    ];

    /** @var array<string, string> */
    private const array NONPUBLIC_STATUS_MAP = [
        'draft' => 'draft',
        'pending' => 'review',
        'future' => 'scheduled',
    ];

    /** @var array<int, string> */
    private const array MANUAL_REVIEW_WARNINGS = [
        'broken_embed',
        'featured_image_download_failed',
        'inline_image_download_failed',
        'missing_featured_image',
        'unsafe_html_removed',
        'unsupported_shortcode',
        'untrusted_iframe_removed',
    ];

    /** @var array<int, string> */
    private const array RETRYABLE_MEDIA_WARNINGS = [
        'featured_image_download_failed',
        'inline_image_download_failed',
    ];

    /** @var array<int, string> */
    private const array INFORMATIONAL_NORMALIZATION_WARNINGS = [
        'trusted_blob_image_removed',
        'trusted_divi_shortcode_normalized',
        'trusted_embed_script_removed',
        'trusted_empty_icon_removed',
        'trusted_featured_image_fallback',
        'trusted_iframe_hardened',
        'trusted_instagram_fallback_normalized',
        'trusted_malformed_link_normalized',
        'trusted_nested_link_normalized',
        'trusted_tiktok_fallback_normalized',
        'trusted_twitter_fallback_normalized',
        'trusted_wordpress_media_wrapper_normalized',
        'trusted_wordpress_interactive_normalized',
        'trusted_wordpress_oembed_normalized',
        'trusted_unavailable_featured_media',
        'trusted_unavailable_inline_media',
        WordpressRestImporter::UNTITLED_NONPUBLIC_WARNING,
    ];

    public function __construct(
        private readonly WordpressRestImporter $importer,
        private readonly WordpressContentSanitizer $contentSanitizer,
        private readonly WordpressFrozenContentDecisionProvenance $contentDecisionProvenance,
        private readonly WordpressUnavailableMediaManifestService $unavailableMediaManifestService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcile(
        string $metadataPath,
        ?string $expectedSha256 = null,
        bool $apply = false,
        ?string $unavailableMediaManifestPath = null,
        ?string $expectedUnavailableMediaSha256 = null,
    ): array {
        $this->maxChunkRecordsObserved = 0;
        $this->maxChunkBytesObserved = 0;
        $result = [
            'mode' => $apply ? 'apply' : 'dry_run',
            'valid' => false,
            'safe_to_apply' => false,
            'reconciliation_complete' => false,
            'launch_ready' => false,
            'manifest' => null,
            'unavailable_media_manifest' => null,
            'validation_errors' => [],
            'content_review' => null,
            'parity_before' => null,
            'plan' => null,
            'apply' => null,
            'parity_after' => null,
            'streaming' => [
                'enabled' => true,
                'max_record_bytes' => self::MAX_RECORD_BYTES,
                'chunk_byte_limit' => self::CHUNK_BYTES,
                'chunk_record_limit' => self::CHUNK_RECORDS,
                'max_chunk_records_observed' => 0,
                'max_chunk_bytes_observed' => 0,
            ],
        ];

        try {
            $bundle = $this->loadBundle($metadataPath, $expectedSha256, $apply);
            $unavailableMediaManifest = $this->loadUnavailableMediaManifest(
                $unavailableMediaManifestPath,
                $expectedUnavailableMediaSha256,
                $bundle,
                $apply,
            );

            if ($unavailableMediaManifest !== null) {
                $bundle['unavailable_media_manifest'] = $unavailableMediaManifest;
                $bundle['unavailable_media_manifest_path'] = $unavailableMediaManifestPath;
                $bundle['expected_unavailable_media_sha256'] = $expectedUnavailableMediaSha256;
            }

            $inventory = $this->validateBundle($bundle);
            $result['manifest'] = [
                'schema_version' => self::SCHEMA_VERSION,
                'sha256' => $bundle['sha256'],
                'generated_at_utc' => $bundle['metadata']['generated_at_utc'],
                'source_base_url' => $bundle['metadata']['source_base_url'],
                'records' => collect($bundle['metadata']['files'])
                    ->map(fn (array $file): int => (int) $file['records'])
                    ->all(),
                'tag_slug_collisions' => [
                    'groups' => $inventory['tag_slug_collision_groups'],
                    'terms' => $inventory['tag_slug_collision_terms'],
                    'strategy' => 'preserve_source_identity_with_wordpress_id_suffix',
                ],
            ];
            $result['unavailable_media_manifest'] = $unavailableMediaManifest === null
                ? null
                : collect($unavailableMediaManifest)
                    ->only([
                        'sha256',
                        'reconciliation_sha256',
                        'inventory',
                        'approval',
                        'totals',
                    ])
                    ->all();
            $result['content_review'] = $inventory['content_review'];
            $result['streaming']['max_chunk_records_observed'] = $inventory['max_chunk_records'];
            $result['streaming']['max_chunk_bytes_observed'] = $inventory['max_chunk_bytes'];
            $result['valid'] = true;
        } catch (Throwable $exception) {
            $result['validation_errors'][] = $this->safeValidationError($exception);

            return $result;
        }

        try {
            $before = $this->parityAndPlan($bundle, $inventory);
        } catch (Throwable $exception) {
            $result['validation_errors'][] = 'local_parity_check_failed';

            return $result;
        }

        $result['parity_before'] = $before['parity'];
        $result['plan'] = $before['plan'];
        $publishedManualReviewCount = (int) $inventory['content_review']['published']['manual_review_articles'];
        $persistedPublishedManualReviewCount = (int) data_get(
            $before,
            'parity.issues.published_import_manual_review_ids.count',
            0,
        );
        $persistedPublishedBlockingManualReviewCount = (int) data_get(
            $before,
            'parity.issues.published_import_blocking_manual_review_ids.count',
            0,
        );
        $result['safe_to_apply'] = $before['safety_blockers'] === []
            && $publishedManualReviewCount === 0
            && $persistedPublishedBlockingManualReviewCount === 0;
        $result['streaming']['max_chunk_records_observed'] = $this->maxChunkRecordsObserved;
        $result['streaming']['max_chunk_bytes_observed'] = $this->maxChunkBytesObserved;

        if (! $apply || ! $result['safe_to_apply']) {
            $result['reconciliation_complete'] = $before['complete'];
            $result['launch_ready'] = $before['complete']
                && $publishedManualReviewCount === 0
                && $persistedPublishedManualReviewCount === 0;

            return $result;
        }

        try {
            $this->assertBundleUnchanged($bundle, 'before_apply');
            $this->assertUnavailableMediaManifestUnchanged($bundle);
        } catch (Throwable $exception) {
            $result['valid'] = false;
            $result['safe_to_apply'] = false;
            $result['validation_errors'][] = $this->safeValidationError($exception);

            return $result;
        }

        $this->importer->setFrozenLinkMap($this->frozenPublishedLinkMap($bundle));

        try {
            $applySummary = $this->applyPlan($bundle, $inventory, $before['plan']);
        } finally {
            $this->importer->clearFrozenLinkMap();
        }

        try {
            $this->assertBundleUnchanged($bundle, 'during_apply');
            $this->assertUnavailableMediaManifestUnchanged($bundle);
        } catch (Throwable $exception) {
            $result['valid'] = false;
            $result['safe_to_apply'] = false;
            $result['apply'] = $applySummary;
            $result['validation_errors'][] = $this->safeValidationError($exception);

            return $result;
        }

        $after = $this->parityAndPlan($bundle, $inventory);
        $result['apply'] = $applySummary;
        $result['parity_after'] = $after['parity'];
        $result['streaming']['max_chunk_records_observed'] = $this->maxChunkRecordsObserved;
        $result['streaming']['max_chunk_bytes_observed'] = $this->maxChunkBytesObserved;
        $result['reconciliation_complete'] = $applySummary['failed'] === 0 && $after['complete'];
        $result['launch_ready'] = $result['reconciliation_complete']
            && (int) $inventory['content_review']['published']['manual_review_articles'] === 0
            && (int) data_get($after, 'parity.issues.published_import_manual_review_ids.count', 0) === 0;

        return $result;
    }

    /**
     * @return array{metadata_path: string, directory: string, sha256: string, metadata: array<string, mixed>, paths: array<string, string>}
     */
    private function loadBundle(string $metadataPath, ?string $expectedSha256, bool $apply): array
    {
        $directoryEvidence = $this->privateDirectory(dirname($metadataPath), $apply);
        $directory = $directoryEvidence['path'];
        $owner = $directoryEvidence['owner'];
        $metadataPath = $this->privateRegularFile($metadataPath, 'metadata', $owner, $apply);
        $size = filesize($metadataPath);

        if (! is_int($size) || $size < 1 || $size > self::MAX_METADATA_BYTES) {
            throw new RuntimeException('metadata_size_invalid');
        }

        $metadataJson = file_get_contents($metadataPath);

        if (! is_string($metadataJson)) {
            throw new RuntimeException('metadata_read_failed');
        }

        try {
            $metadata = json_decode($metadataJson, true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('metadata_json_invalid');
        }

        if (! is_array($metadata) || array_is_list($metadata)) {
            throw new RuntimeException('metadata_shape_invalid');
        }

        $sha256 = hash('sha256', $metadataJson);
        $expectedSha256 = is_string($expectedSha256) ? strtolower(trim($expectedSha256)) : null;

        if ($apply && $expectedSha256 === null) {
            throw new RuntimeException('expected_sha256_required_for_apply');
        }

        if ($expectedSha256 !== null
            && (preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1
                || ! hash_equals($expectedSha256, $sha256))) {
            throw new RuntimeException('metadata_sha256_mismatch');
        }

        if ((int) ($metadata['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            throw new RuntimeException('schema_version_invalid');
        }

        $sourceBaseUrl = $metadata['source_base_url'] ?? null;

        if (! $this->isPrivateSourceBaseUrl($sourceBaseUrl)) {
            throw new RuntimeException('source_base_url_invalid');
        }

        if (! $this->isUtcTimestamp($metadata['generated_at_utc'] ?? null)) {
            throw new RuntimeException('generated_at_utc_invalid');
        }

        if (preg_match('/^[a-f0-9]{64}$/', (string) ($metadata['source_fingerprint_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('source_fingerprint_invalid');
        }

        if (! is_array($metadata['files'] ?? null) || array_is_list($metadata['files'])) {
            throw new RuntimeException('file_manifest_invalid');
        }

        $paths = [];

        foreach (self::SECTION_FILES as $section => $filename) {
            $file = $metadata['files'][$section] ?? null;

            if (! is_array($file)
                || ($file['filename'] ?? null) !== $filename
                || ! is_int($file['records'] ?? null)
                || $file['records'] < 0
                || preg_match('/^[a-f0-9]{64}$/', (string) ($file['sha256'] ?? '')) !== 1) {
                throw new RuntimeException("{$section}_file_manifest_invalid");
            }

            $path = $this->privateRegularFile(
                $directory.DIRECTORY_SEPARATOR.$filename,
                $section,
                $owner,
                $apply,
            );
            $actualHash = hash_file('sha256', $path);

            if (! is_string($actualHash) || ! hash_equals($file['sha256'], $actualHash)) {
                throw new RuntimeException("{$section}_sha256_mismatch");
            }

            $paths[$section] = $path;
        }

        return compact('metadataPath', 'directory', 'owner', 'sha256', 'metadata', 'paths');
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>|null
     */
    private function loadUnavailableMediaManifest(
        ?string $manifestPath,
        ?string $expectedSha256,
        array $bundle,
        bool $apply,
    ): ?array {
        $manifestPath = is_string($manifestPath) && trim($manifestPath) !== ''
            ? trim($manifestPath)
            : null;
        $expectedSha256 = is_string($expectedSha256) && trim($expectedSha256) !== ''
            ? strtolower(trim($expectedSha256))
            : null;

        if (($manifestPath === null) !== ($expectedSha256 === null)) {
            throw new RuntimeException('unavailable_media_manifest_options_incomplete');
        }

        if ($manifestPath === null) {
            return null;
        }

        return $this->unavailableMediaManifestService->load(
            $manifestPath,
            $expectedSha256,
            (string) $bundle['sha256'],
            (string) $bundle['metadata']['source_base_url'],
            $apply,
        );
    }

    /** @param array<string, mixed> $bundle */
    private function assertUnavailableMediaManifestUnchanged(array $bundle): void
    {
        $manifest = $bundle['unavailable_media_manifest'] ?? null;

        if (! is_array($manifest)) {
            return;
        }

        $reloaded = $this->unavailableMediaManifestService->load(
            (string) ($bundle['unavailable_media_manifest_path'] ?? ''),
            (string) ($bundle['expected_unavailable_media_sha256'] ?? ''),
            (string) $bundle['sha256'],
            (string) $bundle['metadata']['source_base_url'],
            true,
        );

        if (! hash_equals((string) $manifest['sha256'], (string) ($reloaded['sha256'] ?? ''))) {
            throw new RuntimeException('unavailable_media_manifest_changed');
        }
    }

    /** @param array<string, mixed> $bundle */
    private function assertBundleUnchanged(array $bundle, string $phase): void
    {
        $directoryEvidence = $this->privateDirectory($bundle['directory'], immutable: true);

        if ($directoryEvidence['owner'] !== $bundle['owner']) {
            throw new RuntimeException("bundle_owner_changed_{$phase}");
        }

        $metadataPath = $this->privateRegularFile(
            $bundle['metadataPath'],
            'metadata',
            $bundle['owner'],
            immutable: true,
        );
        $metadataHash = hash_file('sha256', $metadataPath);

        if (! is_string($metadataHash) || ! hash_equals($bundle['sha256'], $metadataHash)) {
            throw new RuntimeException("metadata_changed_{$phase}");
        }

        foreach (self::SECTION_FILES as $section => $filename) {
            $path = $this->privateRegularFile(
                $bundle['directory'].DIRECTORY_SEPARATOR.$filename,
                $section,
                $bundle['owner'],
                immutable: true,
            );
            $hash = hash_file('sha256', $path);

            if (! is_string($hash)
                || ! hash_equals((string) $bundle['metadata']['files'][$section]['sha256'], $hash)) {
                throw new RuntimeException("{$section}_changed_{$phase}");
            }
        }
    }

    /** @return array{path: string, owner: int} */
    private function privateDirectory(string $path, bool $immutable = false): array
    {
        $realPath = realpath($path);
        $storageRoot = realpath(storage_path('app'));

        if (! is_string($realPath)
            || ! is_string($storageRoot)
            || ! str_starts_with($realPath, $storageRoot.DIRECTORY_SEPARATOR)
            || is_link($path)
            || ! is_dir($realPath)
            || ! is_readable($realPath)) {
            throw new RuntimeException('bundle_private_directory_invalid');
        }

        $permissions = fileperms($realPath);
        $owner = fileowner($realPath);

        if (! is_int($permissions) || (($permissions & 0777) & 0027) !== 0) {
            throw new RuntimeException('bundle_directory_permissions_too_broad');
        }

        if ($immutable && (($permissions & 0777) & 0222) !== 0) {
            throw new RuntimeException('bundle_directory_not_immutable');
        }

        if (! is_int($owner)) {
            throw new RuntimeException('bundle_directory_owner_invalid');
        }

        return ['path' => $realPath, 'owner' => $owner];
    }

    private function privateRegularFile(
        string $path,
        string $label,
        int $expectedOwner,
        bool $immutable = false,
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

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function validateBundle(array $bundle): array
    {
        $metadata = $bundle['metadata'];
        $statusCounts = $metadata['post_status_counts'] ?? null;

        if (! is_array($statusCounts) || array_is_list($statusCounts)) {
            throw new RuntimeException('post_status_counts_invalid');
        }

        foreach (['publish', 'draft', 'pending', 'future', 'private', 'auto-draft'] as $status) {
            if (! is_int($statusCounts[$status] ?? null) || $statusCounts[$status] < 0) {
                throw new RuntimeException("post_status_count_{$status}_invalid");
            }
        }

        foreach ($statusCounts as $status => $count) {
            if (! in_array($status, ['publish', 'draft', 'pending', 'future', 'private', 'auto-draft'], true)
                && (int) $count > 0) {
                throw new RuntimeException('unsupported_source_post_status');
            }
        }

        $privateDispositions = $metadata['private_post_dispositions'] ?? null;

        if (! is_array($privateDispositions)
            || array_is_list($privateDispositions)
            || ! is_int($privateDispositions['records'] ?? null)
            || $privateDispositions['records'] !== (int) $statusCounts['private']
            || ((int) $statusCounts['private'] > 0
                && preg_match('/^[a-f0-9]{64}$/', (string) ($privateDispositions['sha256'] ?? '')) !== 1)
            || ((int) $statusCounts['private'] === 0 && ($privateDispositions['sha256'] ?? null) !== null)) {
            throw new RuntimeException('private_post_dispositions_invalid');
        }

        $passwordProtectedPosts = $metadata['password_protected_posts'] ?? null;

        if (! is_array($passwordProtectedPosts)
            || array_is_list($passwordProtectedPosts)
            || ($passwordProtectedPosts['records'] ?? null) !== 0
            || ($passwordProtectedPosts['ids_sha256'] ?? null) !== hash('sha256', '')) {
            throw new RuntimeException('password_protected_posts_invalid');
        }

        $contentMigrationDecisions = $metadata['content_migration_decisions'] ?? [
            'records' => 0,
            'featured_image_fallbacks' => 0,
            'blob_image_removals' => 0,
            'sha256' => null,
        ];

        if (! is_array($contentMigrationDecisions)
            || array_is_list($contentMigrationDecisions)
            || ! is_int($contentMigrationDecisions['records'] ?? null)
            || $contentMigrationDecisions['records'] < 0
            || ! is_int($contentMigrationDecisions['featured_image_fallbacks'] ?? null)
            || $contentMigrationDecisions['featured_image_fallbacks'] < 0
            || ! is_int($contentMigrationDecisions['blob_image_removals'] ?? null)
            || $contentMigrationDecisions['blob_image_removals'] < 0
            || $contentMigrationDecisions['records'] !== $contentMigrationDecisions['featured_image_fallbacks']
                + $contentMigrationDecisions['blob_image_removals']
            || ($contentMigrationDecisions['records'] > 0
                && preg_match('/^[a-f0-9]{64}$/', (string) ($contentMigrationDecisions['sha256'] ?? '')) !== 1)
            || ($contentMigrationDecisions['records'] === 0
                && ($contentMigrationDecisions['sha256'] ?? null) !== null)) {
            throw new RuntimeException('content_migration_decisions_invalid');
        }

        $contentDecisionSha256 = $contentMigrationDecisions['sha256'];
        $featuredImageFallbacks = 0;
        $blobImageRemovals = 0;
        $versionedFeaturedImageFallbacks = [];
        $versionedBlobImageRemovals = [];

        if ($contentMigrationDecisions['records'] > 0) {
            $versionedDecisions = $this->versionedContentMigrationDecisions();

            if (! hash_equals(
                $versionedDecisions['sha256'],
                (string) $contentDecisionSha256,
            )
                || count($versionedDecisions['featured_image_fallbacks'])
                    < $contentMigrationDecisions['featured_image_fallbacks']
                || count($versionedDecisions['blob_image_removals'])
                    < $contentMigrationDecisions['blob_image_removals']) {
                throw new RuntimeException('content_migration_decisions_version_mismatch');
            }

            $versionedFeaturedImageFallbacks = $versionedDecisions['featured_image_fallbacks'];
            $versionedBlobImageRemovals = $versionedDecisions['blob_image_removals'];
        }

        $contentReview = [
            'articles_scanned' => 0,
            'published' => $this->emptyContentReviewGroup(),
            'nonpublic' => $this->emptyContentReviewGroup(),
        ];
        $postIds = [];
        $approvedUnavailableMediaIds = [];
        $expectedApprovedUnavailableMediaIds = collect(data_get(
            $bundle,
            'unavailable_media_manifest.posts',
            [],
        ))
            ->pluck('wordpress_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $categoryIds = [];
        $tagIds = [];
        $authorIds = [];
        $categoriesById = [];
        $tagsById = [];
        $authorsById = [];
        $categorySlugs = [];
        $tagSourcePaths = [];
        $authorSlugs = [];
        $countsByStatus = array_fill_keys(['publish', 'draft', 'pending', 'future', 'private', 'auto-draft'], 0);
        $actualCounts = array_fill_keys(array_keys(self::SECTION_FILES), 0);
        $lastCategoryId = 0;
        $lastTagId = 0;
        $lastAuthorId = 0;
        $sourceBaseUrl = (string) $metadata['source_base_url'];

        foreach ($this->records($bundle, 'categories') as $entry) {
            $term = $entry['record'];
            $id = $this->validateTerm($term, 'category', $entry['line'], $sourceBaseUrl);
            $this->assertNextId($lastCategoryId, $id, 'categories', $entry['line']);
            if (isset($categoryIds[$id])) {
                throw new RuntimeException("categories:{$entry['line']}:duplicate_wordpress_id");
            }
            $slug = Str::slug(rawurldecode((string) $term['slug']))
                ?: Str::slug($this->cleanText($term['name'] ?? null));
            if (isset($categorySlugs[$slug])) {
                throw new RuntimeException("categories:{$entry['line']}:duplicate_source_slug");
            }
            $categoryIds[$id] = true;
            $categoriesById[$id] = $term;
            $categorySlugs[$slug] = true;
            $actualCounts['categories']++;
        }

        foreach ($categoriesById as $id => $category) {
            $parentId = (int) ($category['parent'] ?? 0);

            if ($parentId === $id || ($parentId > 0 && ! isset($categoriesById[$parentId]))) {
                throw new RuntimeException('category_parent_evidence_invalid');
            }
        }
        $this->assertNoCategoryCycles($categoriesById);

        foreach ($this->records($bundle, 'tags') as $entry) {
            $term = $entry['record'];
            $id = $this->validateTerm($term, 'post_tag', $entry['line'], $sourceBaseUrl);
            $this->assertNextId($lastTagId, $id, 'tags', $entry['line']);
            if (isset($tagIds[$id])) {
                throw new RuntimeException("tags:{$entry['line']}:duplicate_wordpress_id");
            }
            $sourcePath = UrlPath::normalize((string) $term['link']);
            if (isset($tagSourcePaths[$sourcePath])) {
                throw new RuntimeException("tags:{$entry['line']}:duplicate_source_slug");
            }
            $tagIds[$id] = true;
            $tagsById[$id] = $term;
            $tagSourcePaths[$sourcePath] = true;
            $actualCounts['tags']++;
        }

        $tagSlugPlan = $this->uniqueTagTargetSlugs($tagsById);

        foreach ($this->records($bundle, 'authors') as $entry) {
            $author = $entry['record'];
            $id = $this->validateAuthor($author, $entry['line'], $sourceBaseUrl);
            $this->assertNextId($lastAuthorId, $id, 'authors', $entry['line']);
            if (isset($authorIds[$id])) {
                throw new RuntimeException("authors:{$entry['line']}:duplicate_wordpress_id");
            }
            $slug = Str::slug(rawurldecode((string) $author['source_slug']));
            if (isset($authorSlugs[$slug])) {
                throw new RuntimeException("authors:{$entry['line']}:duplicate_source_slug");
            }
            $authorIds[$id] = true;
            $authorsById[$id] = $author;
            $authorSlugs[$slug] = true;
            $actualCounts['authors']++;
        }

        foreach (['published_posts', 'nonpublic_posts'] as $section) {
            $lastPostId = 0;

            foreach ($this->records($bundle, $section) as $entry) {
                $record = $entry['record'];
                $payload = $section === 'nonpublic_posts' ? ($record['payload'] ?? null) : $record;
                $targetStatus = $section === 'nonpublic_posts' ? ($record['target_status'] ?? null) : 'published';

                if (! is_array($payload) || array_is_list($payload)) {
                    throw new RuntimeException("{$section}:{$entry['line']}:payload_invalid");
                }

                $id = $this->validatePost(
                    $payload,
                    $section,
                    $entry['line'],
                    $targetStatus,
                    $categoriesById,
                    $tagsById,
                    $authorsById,
                    $sourceBaseUrl,
                    is_string($contentDecisionSha256) ? $contentDecisionSha256 : null,
                    $versionedFeaturedImageFallbacks,
                    $versionedBlobImageRemovals,
                );
                $this->assertNextId($lastPostId, $id, $section, $entry['line']);
                if (isset($postIds[$id])) {
                    throw new RuntimeException("{$section}:{$entry['line']}:duplicate_wordpress_id");
                }
                $postIds[$id] = $section;
                $status = (string) $payload['status'];
                $countsByStatus[$status]++;
                $actualCounts[$section]++;
                $contentReview['articles_scanned']++;
                $usesBlobImageRemoval = is_array(data_get(
                    $payload,
                    '_razbudise.blob_image_removal',
                ));
                $unavailableMedia = data_get($payload, '_razbudise.unavailable_media');
                $unavailableMedia = is_array($unavailableMedia) ? $unavailableMedia : [];
                $trustedUnavailableInlineMedia = is_array($unavailableMedia['inline'] ?? null)
                    ? $unavailableMedia['inline']
                    : [];
                $preview = $this->contentSanitizer->preview(
                    data_get($payload, 'content.rendered'),
                    $usesBlobImageRemoval,
                    $trustedUnavailableInlineMedia,
                );
                $usesFeaturedImageFallback = is_array(data_get(
                    $payload,
                    '_razbudise.featured_image_fallback',
                ));
                $usesUnavailableFeaturedMedia = is_array($unavailableMedia['featured'] ?? null);
                $effectiveFeaturedMedia = $this->effectiveFeaturedMedia($payload);
                $expectedUnavailableFeaturedRole = $usesFeaturedImageFallback
                    ? 'featured_fallback'
                    : 'featured';

                if ($usesUnavailableFeaturedMedia
                    && (! is_array($effectiveFeaturedMedia)
                        || ($unavailableMedia['featured']['role'] ?? null)
                            !== $expectedUnavailableFeaturedRole
                        || ($unavailableMedia['featured']['treatment'] ?? null) !== 'frontend_placeholder'
                        || (int) ($unavailableMedia['featured']['attachment_id'] ?? 0)
                            !== (int) ($effectiveFeaturedMedia['id'] ?? 0)
                        || ! hash_equals(
                            (string) ($unavailableMedia['featured']['source_url'] ?? ''),
                            (string) ($effectiveFeaturedMedia['source_url'] ?? ''),
                        ))) {
                    throw new RuntimeException(
                        "{$section}:{$entry['line']}:unavailable_featured_media_evidence_invalid",
                    );
                }

                if ($unavailableMedia !== []) {
                    $approvedUnavailableMediaIds[] = $id;
                }

                if ($usesFeaturedImageFallback) {
                    $featuredImageFallbacks++;
                    $preview['warnings'][] = 'trusted_featured_image_fallback';
                    $preview['normalization_counts']['trusted_featured_image_fallback'] = 1;
                    $preview['warnings'] = array_values(array_unique($preview['warnings']));
                }

                if ($usesUnavailableFeaturedMedia) {
                    $preview['warnings'][] = 'trusted_unavailable_featured_media';
                    $preview['normalization_counts']['trusted_unavailable_featured_media'] = 1;
                    $preview['warnings'] = array_values(array_unique($preview['warnings']));
                } elseif (! $usesFeaturedImageFallback && $effectiveFeaturedMedia === null) {
                    $preview['warnings'][] = 'missing_featured_image';
                    $preview['warnings'] = array_values(array_unique($preview['warnings']));
                }

                if ($section === 'nonpublic_posts'
                    && $this->cleanText(data_get($payload, 'title.rendered')) === '') {
                    $preview['warnings'][] = WordpressRestImporter::UNTITLED_NONPUBLIC_WARNING;
                    $preview['warnings'] = array_values(array_unique($preview['warnings']));
                }

                if ($usesBlobImageRemoval) {
                    $blobImageRemovals++;
                }

                $this->addContentReview(
                    $contentReview[$section === 'published_posts' ? 'published' : 'nonpublic'],
                    $id,
                    $preview,
                );
            }
        }

        $approvedUnavailableMediaIds = $this->sortedUniqueIds($approvedUnavailableMediaIds);

        if ($approvedUnavailableMediaIds !== $expectedApprovedUnavailableMediaIds) {
            throw new RuntimeException('unavailable_media_manifest_post_mismatch');
        }

        $lastAutoDraftId = 0;

        foreach ($this->records($bundle, 'auto_drafts') as $entry) {
            $autoDraft = $entry['record'];
            $id = $this->validateAutoDraft($autoDraft, $entry['line']);
            $this->assertNextId($lastAutoDraftId, $id, 'auto_drafts', $entry['line']);
            if (isset($postIds[$id])) {
                throw new RuntimeException("auto_drafts:{$entry['line']}:duplicate_wordpress_id");
            }
            $postIds[$id] = 'auto_drafts';
            $countsByStatus['auto-draft']++;
            $actualCounts['auto_drafts']++;
        }

        foreach ($actualCounts as $section => $actual) {
            if ($actual !== (int) $metadata['files'][$section]['records']) {
                throw new RuntimeException("{$section}_cardinality_mismatch");
            }
        }

        if ($featuredImageFallbacks !== $contentMigrationDecisions['featured_image_fallbacks']) {
            throw new RuntimeException('content_migration_decisions_cardinality_mismatch');
        }

        if ($blobImageRemovals !== $contentMigrationDecisions['blob_image_removals']) {
            throw new RuntimeException('content_migration_decisions_cardinality_mismatch');
        }

        $expectedNonpublic = (int) $statusCounts['draft']
            + (int) $statusCounts['pending']
            + (int) $statusCounts['future']
            + (int) $statusCounts['private'];

        if ($actualCounts['published_posts'] !== (int) $statusCounts['publish']
            || $actualCounts['nonpublic_posts'] !== $expectedNonpublic
            || $actualCounts['auto_drafts'] !== (int) $statusCounts['auto-draft']) {
            throw new RuntimeException('post_status_cardinality_mismatch');
        }

        foreach ($countsByStatus as $status => $count) {
            if ($count !== (int) $statusCounts[$status]) {
                throw new RuntimeException("post_status_{$status}_mismatch");
            }
        }

        return [
            'post_ids' => $postIds,
            'category_ids' => $categoryIds,
            'tag_ids' => $tagIds,
            'tag_target_slugs' => $tagSlugPlan['slugs'],
            'tag_slug_collision_groups' => $tagSlugPlan['collision_groups'],
            'tag_slug_collision_terms' => $tagSlugPlan['collision_terms'],
            'author_ids' => $authorIds,
            'approved_unavailable_media_ids' => $approvedUnavailableMediaIds,
            'content_review' => $contentReview,
            'max_chunk_records' => $this->maxChunkRecordsObserved,
            'max_chunk_bytes' => $this->maxChunkBytesObserved,
        ];
    }

    /**
     * Preserve every WordPress tag identity while assigning unique public CMS
     * slugs. A source tag whose legacy path already equals the clean target
     * path keeps the unsuffixed slug; the remaining colliding identities get a
     * deterministic WordPress-ID suffix.
     *
     * @param  array<int, array<string, mixed>>  $tagsById
     * @return array{slugs: array<int, string>, collision_groups: int, collision_terms: int}
     */
    private function uniqueTagTargetSlugs(array $tagsById): array
    {
        $groups = [];

        foreach ($tagsById as $id => $tag) {
            $baseSlug = $this->termTargetSlug($tag);
            $groups[$baseSlug][(int) $id] = $tag;
        }

        ksort($groups);

        $reserved = array_fill_keys(array_keys($groups), true);
        $slugs = [];
        $collisionGroups = 0;
        $collisionTerms = 0;

        foreach ($groups as $baseSlug => $terms) {
            $ids = array_keys($terms);
            sort($ids, SORT_NUMERIC);

            if (count($ids) === 1) {
                $slugs[$ids[0]] = $baseSlug;

                continue;
            }

            $collisionGroups++;
            $collisionTerms += count($ids);
            $targetPath = "/tag/{$baseSlug}";
            $selfPathIds = array_values(array_filter(
                $ids,
                fn (int $id): bool => UrlPath::normalize((string) ($terms[$id]['link'] ?? '')) === $targetPath,
            ));
            $primaryId = $selfPathIds[0] ?? $ids[0];
            $slugs[$primaryId] = $baseSlug;

            foreach ($ids as $id) {
                if ($id === $primaryId) {
                    continue;
                }

                $counter = 1;

                do {
                    $suffix = "-wp-{$id}".($counter > 1 ? "-{$counter}" : '');
                    $prefix = rtrim(substr($baseSlug, 0, 255 - strlen($suffix)), '-');
                    $candidate = ($prefix !== '' ? $prefix : 'tag').$suffix;
                    $counter++;
                } while (isset($reserved[$candidate]));

                $slugs[$id] = $candidate;
                $reserved[$candidate] = true;
            }
        }

        ksort($slugs, SORT_NUMERIC);

        return [
            'slugs' => $slugs,
            'collision_groups' => $collisionGroups,
            'collision_terms' => $collisionTerms,
        ];
    }

    /** @param array<string, mixed> $term */
    private function termTargetSlug(array $term): string
    {
        $slug = Str::slug(rawurldecode((string) ($term['slug'] ?? '')))
            ?: Str::slug($this->cleanText($term['name'] ?? null));

        return rtrim(substr($slug, 0, 255), '-');
    }

    /**
     * @return array{manual_review_articles: int, manual_review_ids: array<int, int>, warnings: array<string, array{articles: int, ids: array<int, int>}>, normalizations: array<string, int>}
     */
    private function emptyContentReviewGroup(): array
    {
        return [
            'manual_review_articles' => 0,
            'manual_review_ids' => [],
            'warnings' => [],
            'normalizations' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $preview
     */
    private function addContentReview(array &$group, int $wordpressId, array $preview): void
    {
        $warnings = collect($preview['warnings'] ?? [])
            ->filter(fn (mixed $warning): bool => is_string($warning))
            ->unique()
            ->values()
            ->all();
        $manualWarnings = array_values(array_intersect($warnings, self::MANUAL_REVIEW_WARNINGS));

        if ($manualWarnings !== []) {
            $group['manual_review_articles']++;
            $group['manual_review_ids'][] = $wordpressId;
        }

        foreach ($warnings as $warning) {
            $group['warnings'][$warning] ??= ['articles' => 0, 'ids' => []];
            $group['warnings'][$warning]['articles']++;
            $group['warnings'][$warning]['ids'][] = $wordpressId;
        }

        foreach (($preview['normalization_counts'] ?? []) as $normalization => $count) {
            $group['normalizations'][$normalization] = (int) ($group['normalizations'][$normalization] ?? 0)
                + max(0, (int) $count);
        }
    }

    private function assertNextId(int &$lastId, int $id, string $section, int $line): void
    {
        if ($id <= $lastId) {
            throw new RuntimeException("{$section}:{$line}:records_not_sorted");
        }

        $lastId = $id;
    }

    /**
     * @param  array<int, array<string, mixed>>  $categoriesById
     * @param  array<int, array<string, mixed>>  $tagsById
     * @param  array<int, array<string, mixed>>  $authorsById
     */
    private function validatePost(
        array $payload,
        string $section,
        int $line,
        mixed $targetStatus,
        array $categoriesById,
        array $tagsById,
        array $authorsById,
        string $sourceBaseUrl,
        ?string $contentDecisionSha256,
        array $versionedFeaturedImageFallbacks,
        array $versionedBlobImageRemovals,
    ): int {
        $id = (int) ($payload['id'] ?? 0);
        $sourceStatus = (string) ($payload['status'] ?? '');

        if ($id < 1) {
            throw new RuntimeException("{$section}:{$line}:wordpress_id_invalid");
        }

        if (($section === 'published_posts' && ($sourceStatus !== 'publish' || $targetStatus !== 'published'))
            || ($section === 'nonpublic_posts' && ! $this->validNonpublicMapping($sourceStatus, $targetStatus))) {
            throw new RuntimeException("{$section}:{$line}:status_mapping_invalid");
        }

        $title = data_get($payload, 'title.rendered');

        if (! is_string($title)
            || ($this->cleanText($title) === ''
                && ! ($section === 'nonpublic_posts'
                    && $sourceStatus === 'draft'
                    && $targetStatus === 'draft'))
            || ! is_string($payload['slug'] ?? null)
            || ! is_string(data_get($payload, 'content.rendered'))
            || ! is_string(data_get($payload, 'excerpt.rendered'))
            || ! $this->isSourceDate($payload['date_gmt'] ?? null)
            || ! $this->isSourceDate($payload['modified_gmt'] ?? null)) {
            throw new RuntimeException("{$section}:{$line}:editorial_payload_incomplete");
        }

        if (trim((string) ($payload['password'] ?? '')) !== '') {
            throw new RuntimeException("{$section}:{$line}:password_protected_post_unsupported");
        }

        $link = $payload['link'] ?? null;

        if (($section === 'published_posts' && ! $this->isConfiguredSourceUrl($link, $sourceBaseUrl))
            || ($section === 'nonpublic_posts'
                && $link !== null
                && $link !== ''
                && ! $this->isConfiguredSourceUrl($link, $sourceBaseUrl))) {
            throw new RuntimeException("{$section}:{$line}:source_url_invalid");
        }

        $authorId = (int) ($payload['author'] ?? data_get($payload, '_embedded.author.0.id'));
        $embeddedAuthor = data_get($payload, '_embedded.author.0');
        $frozenAuthor = $authorsById[$authorId] ?? null;

        if ($authorId < 1
            || ! is_array($embeddedAuthor)
            || ! is_array($frozenAuthor)
            || (int) ($embeddedAuthor['id'] ?? 0) !== $authorId
            || (string) ($embeddedAuthor['slug'] ?? '') !== (string) $frozenAuthor['source_slug']
            || $this->cleanText($embeddedAuthor['name'] ?? null) !== $this->cleanText($frozenAuthor['display_name'] ?? null)
            || trim((string) ($embeddedAuthor['link'] ?? '')) !== trim((string) $frozenAuthor['old_url'])) {
            throw new RuntimeException("{$section}:{$line}:author_evidence_invalid");
        }

        $embeddedTerms = data_get($payload, '_embedded.wp:term');

        if (! is_array($embeddedTerms)) {
            throw new RuntimeException("{$section}:{$line}:taxonomy_evidence_missing");
        }

        $embeddedCategoryIds = [];
        $embeddedTagIds = [];

        foreach (collect($embeddedTerms)->flatten(1) as $term) {
            if (! is_array($term)) {
                throw new RuntimeException("{$section}:{$line}:taxonomy_evidence_invalid");
            }

            $termId = (int) ($term['id'] ?? 0);
            $taxonomy = $term['taxonomy'] ?? null;
            $frozenTerm = $taxonomy === 'category'
                ? ($categoriesById[$termId] ?? null)
                : ($taxonomy === 'post_tag' ? ($tagsById[$termId] ?? null) : null);

            if (! is_array($frozenTerm)
                || ! in_array($taxonomy, ['category', 'post_tag'], true)
                || (string) ($term['slug'] ?? '') !== (string) ($frozenTerm['slug'] ?? '')
                || $this->cleanText($term['name'] ?? null) !== $this->cleanText($frozenTerm['name'] ?? null)
                || trim((string) ($term['link'] ?? '')) !== trim((string) ($frozenTerm['link'] ?? ''))) {
                throw new RuntimeException("{$section}:{$line}:taxonomy_reference_missing");
            }

            if ($taxonomy === 'category') {
                $embeddedCategoryIds[] = $termId;
            } else {
                $embeddedTagIds[] = $termId;
            }
        }

        $declaredCategoryIds = $this->strictWordpressIdList($payload['categories'] ?? null);
        $declaredTagIds = $this->strictWordpressIdList($payload['tags'] ?? null);

        if ($declaredCategoryIds === null
            || $declaredCategoryIds === []
            || $declaredTagIds === null
            || $this->sortedUniqueIds($declaredCategoryIds) !== $this->sortedUniqueIds($embeddedCategoryIds)
            || $this->sortedUniqueIds($declaredTagIds) !== $this->sortedUniqueIds($embeddedTagIds)) {
            throw new RuntimeException("{$section}:{$line}:taxonomy_id_evidence_mismatch");
        }

        $featuredMediaId = (int) ($payload['featured_media'] ?? 0);
        $featuredImageFallback = data_get($payload, '_razbudise.featured_image_fallback');

        if (($featuredMediaId > 0
                && ((int) data_get($payload, '_embedded.wp:featuredmedia.0.id') !== $featuredMediaId
                    || ! $this->isAllowedConfiguredMediaUrl(data_get($payload, '_embedded.wp:featuredmedia.0.source_url'))
                    || $featuredImageFallback !== null))
            || ($featuredMediaId === 0
                && ((int) data_get($payload, '_embedded.wp:featuredmedia.0.id') > 0
                    || trim((string) data_get($payload, '_embedded.wp:featuredmedia.0.source_url')) !== ''))) {
            throw new RuntimeException("{$section}:{$line}:featured_media_evidence_invalid");
        }

        if ($featuredMediaId === 0 && $featuredImageFallback !== null) {
            $this->validateFeaturedImageFallback(
                $payload,
                $featuredImageFallback,
                $contentDecisionSha256,
                $versionedFeaturedImageFallbacks,
                $section,
                $line,
            );
        }

        if (isset($versionedFeaturedImageFallbacks[$id]) && $featuredImageFallback === null) {
            throw new RuntimeException("{$section}:{$line}:featured_image_fallback_evidence_missing");
        }

        $blobImageRemoval = data_get($payload, '_razbudise.blob_image_removal');

        if ($blobImageRemoval !== null) {
            $this->validateBlobImageRemoval(
                $payload,
                $blobImageRemoval,
                $contentDecisionSha256,
                $versionedBlobImageRemovals,
                $section,
                $line,
            );
        }

        if (isset($versionedBlobImageRemovals[$id]) && $blobImageRemoval === null) {
            throw new RuntimeException("{$section}:{$line}:blob_image_removal_evidence_missing");
        }

        return $id;
    }

    private function validateFeaturedImageFallback(
        array $payload,
        mixed $fallback,
        ?string $contentDecisionSha256,
        array $versionedFeaturedImageFallbacks,
        string $section,
        int $line,
    ): void {
        $postId = (int) ($payload['id'] ?? 0);
        $attachmentId = is_array($fallback) ? (int) ($fallback['attachment_id'] ?? 0) : 0;
        $media = is_array($fallback) ? ($fallback['media'] ?? null) : null;
        $sourceBody = (string) data_get($payload, 'content.rendered', '');

        if (! is_array($fallback)
            || array_is_list($fallback)
            || ! is_array($media)
            || array_is_list($media)
            || $contentDecisionSha256 === null
            || ($fallback['decision_sha256'] ?? null) !== $contentDecisionSha256
            || ($fallback['strategy'] ?? null) !== 'explicit_inline_attachment'
            || (int) ($fallback['post_id'] ?? 0) !== $postId
            || $attachmentId < 1
            || ($versionedFeaturedImageFallbacks[$postId] ?? null) !== $attachmentId
            || (int) ($media['id'] ?? 0) !== $attachmentId
            || (int) ($media['post'] ?? 0) !== $postId
            || ($media['media_type'] ?? null) !== 'image'
            || ! str_starts_with(strtolower((string) ($media['mime_type'] ?? '')), 'image/')
            || ! $this->isAllowedConfiguredMediaUrl($media['source_url'] ?? null)
            || ! $this->bodyReferencesAttachmentImage(
                $sourceBody,
                $attachmentId,
                (string) $media['source_url'],
            )) {
            throw new RuntimeException("{$section}:{$line}:featured_image_fallback_evidence_invalid");
        }
    }

    /**
     * @param  array<int, array{raw_post_content_sha256: string, expected_blob_image_count: int}>  $versionedBlobImageRemovals
     */
    private function validateBlobImageRemoval(
        array $payload,
        mixed $evidence,
        ?string $contentDecisionSha256,
        array $versionedBlobImageRemovals,
        string $section,
        int $line,
    ): void {
        $postId = (int) ($payload['id'] ?? 0);
        $sourceBody = (string) data_get($payload, 'content.rendered', '');
        $blobImageCount = $this->persistedBlobImageCount($sourceBody);
        $decision = $versionedBlobImageRemovals[$postId] ?? null;

        if (! is_array($evidence)
            || array_is_list($evidence)
            || ! is_array($decision)
            || $contentDecisionSha256 === null
            || ($evidence['decision_sha256'] ?? null) !== $contentDecisionSha256
            || ($evidence['strategy'] ?? null) !== 'remove_nonpersistent_blob_images'
            || (int) ($evidence['post_id'] ?? 0) !== $postId
            || ($evidence['raw_post_content_sha256'] ?? null) !== ($decision['raw_post_content_sha256'] ?? null)
            || (int) ($evidence['expected_blob_image_count'] ?? 0) !== $blobImageCount
            || (int) ($decision['expected_blob_image_count'] ?? 0) !== $blobImageCount
            || $blobImageCount < 1) {
            throw new RuntimeException("{$section}:{$line}:blob_image_removal_evidence_invalid");
        }
    }

    /**
     * @return array{
     *     sha256: string,
     *     featured_image_fallbacks: array<int, int>,
     *     blob_image_removals: array<int, array{raw_post_content_sha256: string, expected_blob_image_count: int}>
     * }
     */
    private function versionedContentMigrationDecisions(): array
    {
        $configuredPath = config('services.wordpress.content_migration_decisions_path');
        $path = is_string($configuredPath) && trim($configuredPath) !== ''
            ? trim($configuredPath)
            : dirname(base_path()).'/deployment/wordpress/content-migration-decisions.json';
        $json = file_get_contents($path);

        if (! is_string($json) || strlen($json) > 100_000) {
            throw new RuntimeException('content_migration_decisions_read_failed');
        }

        try {
            $decisions = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('content_migration_decisions_json_invalid');
        }

        if (! is_array($decisions)
            || array_is_list($decisions)
            || ($decisions['schema_version'] ?? null) !== 1
            || ! is_array($decisions['featured_image_fallbacks'] ?? null)
            || array_is_list($decisions['featured_image_fallbacks'])
            || ! is_array($decisions['blob_image_removals'] ?? null)
            || array_is_list($decisions['blob_image_removals'])) {
            throw new RuntimeException('content_migration_decisions_schema_invalid');
        }

        $featuredImageFallbacks = [];

        foreach ($decisions['featured_image_fallbacks'] as $postId => $attachmentId) {
            if (! ctype_digit((string) $postId)
                || (int) $postId < 1
                || ! is_int($attachmentId)
                || $attachmentId < 1) {
                throw new RuntimeException('content_migration_decisions_schema_invalid');
            }

            $featuredImageFallbacks[(int) $postId] = $attachmentId;
        }

        ksort($featuredImageFallbacks, SORT_NUMERIC);

        $blobImageRemovals = [];

        foreach ($decisions['blob_image_removals'] as $postId => $decision) {
            if (! ctype_digit((string) $postId)
                || (int) $postId < 1
                || ! is_array($decision)
                || array_is_list($decision)
                || preg_match('/^[a-f0-9]{64}$/', (string) ($decision['raw_post_content_sha256'] ?? '')) !== 1
                || ! is_int($decision['expected_blob_image_count'] ?? null)
                || $decision['expected_blob_image_count'] < 1) {
                throw new RuntimeException('content_migration_decisions_schema_invalid');
            }

            $blobImageRemovals[(int) $postId] = [
                'raw_post_content_sha256' => $decision['raw_post_content_sha256'],
                'expected_blob_image_count' => $decision['expected_blob_image_count'],
            ];
        }

        ksort($blobImageRemovals, SORT_NUMERIC);

        return [
            'sha256' => hash('sha256', $json),
            'featured_image_fallbacks' => $featuredImageFallbacks,
            'blob_image_removals' => $blobImageRemovals,
        ];
    }

    private function persistedBlobImageCount(string $html): int
    {
        if (stripos($html, 'blob:') === false || stripos($html, '<img') === false) {
            return 0;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            if (! $document->loadHTML(
                '<?xml encoding="UTF-8"><div>'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            )) {
                return 0;
            }

            $count = 0;
            foreach ($document->getElementsByTagName('img') as $image) {
                if ($image instanceof DOMElement
                    && str_starts_with(strtolower(trim($image->getAttribute('src'))), 'blob:')) {
                    $count++;
                }
            }

            return $count;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    private function bodyReferencesAttachmentImage(
        string $html,
        int $attachmentId,
        string $sourceUrl,
    ): bool {
        if ($attachmentId < 1 || trim($sourceUrl) === '' || stripos($html, '<img') === false) {
            return false;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            if (! $document->loadHTML(
                '<?xml encoding="UTF-8"><div>'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            )) {
                return false;
            }

            $expectedClass = 'wp-image-'.$attachmentId;
            $sourceUrl = html_entity_decode(trim($sourceUrl), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            foreach ($document->getElementsByTagName('img') as $image) {
                if (! $image instanceof DOMElement) {
                    continue;
                }

                $classes = preg_split('/\\s+/u', trim($image->getAttribute('class'))) ?: [];
                if (! in_array($expectedClass, $classes, true)) {
                    continue;
                }

                $candidates = [trim($image->getAttribute('src'))];
                foreach (explode(',', $image->getAttribute('srcset')) as $candidate) {
                    $parts = preg_split('/\\s+/u', trim($candidate), 2) ?: [];
                    $candidates[] = (string) ($parts[0] ?? '');
                }

                foreach ($candidates as $candidate) {
                    if (html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8') === $sourceUrl) {
                        return true;
                    }
                }
            }

            return false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    private function validNonpublicMapping(string $sourceStatus, mixed $targetStatus): bool
    {
        if ($sourceStatus === 'private') {
            return in_array($targetStatus, ['draft', 'archived'], true);
        }

        return isset(self::NONPUBLIC_STATUS_MAP[$sourceStatus])
            && self::NONPUBLIC_STATUS_MAP[$sourceStatus] === $targetStatus;
    }

    private function validateTerm(array $term, string $taxonomy, int $line, string $sourceBaseUrl): int
    {
        $id = (int) ($term['id'] ?? 0);
        $section = $taxonomy === 'category' ? 'categories' : 'tags';

        if ($id < 1
            || ($term['taxonomy'] ?? null) !== $taxonomy
            || ! is_string($term['slug'] ?? null)
            || trim((string) $term['slug']) === ''
            || (Str::slug(rawurldecode((string) $term['slug']))
                ?: Str::slug($this->cleanText($term['name'] ?? null))) === ''
            || ! is_string($term['name'] ?? null)
            || trim(strip_tags((string) $term['name'])) === ''
            || ! $this->isConfiguredSourceUrl($term['link'] ?? null, $sourceBaseUrl)
            || ($taxonomy === 'category' && (int) ($term['parent'] ?? 0) < 0)) {
            throw new RuntimeException("{$section}:{$line}:term_invalid");
        }

        return $id;
    }

    private function validateAuthor(array $author, int $line, string $sourceBaseUrl): int
    {
        $id = (int) ($author['wordpress_id'] ?? 0);

        if ($id < 1
            || ! is_string($author['source_slug'] ?? null)
            || trim((string) $author['source_slug']) === ''
            || str_contains((string) $author['source_slug'], '/')
            || Str::slug(rawurldecode((string) $author['source_slug'])) === ''
            || ! is_string($author['display_name'] ?? null)
            || trim((string) $author['display_name']) === ''
            || ! $this->isConfiguredSourceUrl($author['old_url'] ?? null, $sourceBaseUrl)) {
            throw new RuntimeException("authors:{$line}:author_invalid");
        }

        return $id;
    }

    private function validateAutoDraft(array $autoDraft, int $line): int
    {
        $id = (int) ($autoDraft['wordpress_id'] ?? 0);

        if ($id < 1
            || ($autoDraft['source_status'] ?? null) !== 'auto-draft'
            || ($autoDraft['disposition'] ?? null) !== 'retain_in_verified_wordpress_backup'
            || ! is_string($autoDraft['source_slug'] ?? null)
            || ! $this->isSourceDate($autoDraft['source_date_gmt'] ?? null)
            || ! $this->isSourceDate($autoDraft['source_modified_gmt'] ?? null)) {
            throw new RuntimeException("auto_drafts:{$line}:auto_draft_invalid");
        }

        return $id;
    }

    /** @param array<int, array<string, mixed>> $categoriesById */
    private function assertNoCategoryCycles(array $categoriesById): void
    {
        foreach (array_keys($categoriesById) as $startId) {
            $seen = [];
            $currentId = $startId;

            while ($currentId > 0) {
                if (isset($seen[$currentId])) {
                    throw new RuntimeException('category_parent_cycle_detected');
                }

                $seen[$currentId] = true;
                $currentId = (int) ($categoriesById[$currentId]['parent'] ?? 0);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return \Generator<int, array{record: array<string, mixed>, line: int, bytes: int}>
     */
    private function records(array $bundle, string $section): \Generator
    {
        $path = $bundle['paths'][$section];
        $handle = fopen($path, 'rb');

        if (! is_resource($handle)) {
            throw new RuntimeException("{$section}_read_failed");
        }

        $lineNumber = 0;

        try {
            while (($line = fgets($handle, self::MAX_RECORD_BYTES + 1)) !== false) {
                $lineNumber++;
                $bytes = strlen($line);

                if ($bytes > self::MAX_RECORD_BYTES || ($bytes === self::MAX_RECORD_BYTES && ! str_ends_with($line, "\n"))) {
                    throw new RuntimeException("{$section}:{$lineNumber}:record_too_large");
                }

                if (trim($line) === '') {
                    throw new RuntimeException("{$section}:{$lineNumber}:blank_record");
                }

                try {
                    $record = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    throw new RuntimeException("{$section}:{$lineNumber}:json_invalid");
                }

                if (! is_array($record) || array_is_list($record)) {
                    throw new RuntimeException("{$section}:{$lineNumber}:record_shape_invalid");
                }

                if (in_array($section, ['published_posts', 'nonpublic_posts'], true)) {
                    $payload = $section === 'nonpublic_posts'
                        ? ($record['payload'] ?? null)
                        : $record;

                    if (is_array($payload) && ! array_is_list($payload)) {
                        $namespace = $payload['_razbudise'] ?? null;

                        if (is_array($namespace) && array_key_exists('unavailable_media', $namespace)) {
                            throw new RuntimeException(
                                "{$section}:{$lineNumber}:unavailable_media_evidence_forbidden_in_bundle",
                            );
                        }

                        $manifest = $bundle['unavailable_media_manifest'] ?? null;

                        if (is_array($manifest)) {
                            $evidence = $this->unavailableMediaManifestService->evidenceFor(
                                $manifest,
                                $payload,
                            );

                            if ($evidence !== null) {
                                $payload['_razbudise'] = is_array($namespace) ? $namespace : [];
                                $payload['_razbudise']['unavailable_media'] = $evidence;

                                if ($section === 'nonpublic_posts') {
                                    $record['payload'] = $payload;
                                } else {
                                    $record = $payload;
                                }
                            }
                        }
                    }
                }

                yield ['record' => $record, 'line' => $lineNumber, 'bytes' => $bytes];
            }

            if (! feof($handle)) {
                throw new RuntimeException("{$section}_read_failed");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @param  callable(array<int, array<string, mixed>>, int): void  $callback
     */
    private function eachChunk(array $bundle, string $section, callable $callback): void
    {
        $chunk = [];
        $bytes = 0;

        foreach ($this->records($bundle, $section) as $entry) {
            if ($chunk !== []
                && (count($chunk) >= self::CHUNK_RECORDS || $bytes + $entry['bytes'] > self::CHUNK_BYTES)) {
                $this->maxChunkRecordsObserved = max($this->maxChunkRecordsObserved, count($chunk));
                $this->maxChunkBytesObserved = max($this->maxChunkBytesObserved, $bytes);
                $callback($chunk, $bytes);
                $chunk = [];
                $bytes = 0;
            }

            $chunk[] = $entry['record'];
            $bytes += $entry['bytes'];
        }

        if ($chunk !== []) {
            $this->maxChunkRecordsObserved = max($this->maxChunkRecordsObserved, count($chunk));
            $this->maxChunkBytesObserved = max($this->maxChunkBytesObserved, $bytes);
            $callback($chunk, $bytes);
        }
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>  $inventory
     * @return array{parity: array<string, mixed>, plan: array<string, mixed>, safety_blockers: array<int, string>, complete: bool}
     */
    private function parityAndPlan(array $bundle, array $inventory): array
    {
        $plan = [
            'published_posts' => ['upsert_count' => 0, 'ids' => [], 'redirect_count' => 0, 'redirect_ids' => []],
            'nonpublic_posts' => ['upsert_count' => 0, 'ids' => [], 'deactivate_redirect_count' => 0, 'redirect_ids' => []],
            'categories' => ['upsert_count' => 0, 'ids' => [], 'redirect_count' => 0, 'redirect_ids' => []],
            'tags' => ['upsert_count' => 0, 'ids' => [], 'redirect_count' => 0, 'redirect_ids' => []],
            'authors' => ['legacy_url_update_count' => 0, 'ids' => [], 'redirect_count' => 0, 'redirect_ids' => []],
            'auto_drafts' => [
                'disposition_count' => count(array_filter(
                    $inventory['post_ids'],
                    fn (string $section): bool => $section === 'auto_drafts',
                )),
                'ids' => [],
            ],
            'total_writes' => 0,
        ];
        $issues = [
            'duplicate_local_post_ids' => $this->duplicateLocalIds(Article::class, 'old_wordpress_id'),
            'local_post_ids_absent_from_source' => Article::query()
                ->whereNotNull('old_wordpress_id')
                ->pluck('old_wordpress_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => ! isset($inventory['post_ids'][$id]))
                ->unique()->sort()->values()->all(),
            'post_record_ids_absent_from_source' => $this->extraRecordIds('post', $inventory['post_ids']),
            'auto_draft_cms_collision_ids' => Article::query()
                ->whereIn('old_wordpress_id', collect($inventory['post_ids'])
                    ->filter(fn (string $section): bool => $section === 'auto_drafts')->keys()->all())
                ->pluck('old_wordpress_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
            'auto_draft_import_record_ids' => WordpressImportRecord::query()
                ->where('wordpress_type', 'post')
                ->whereIn('wordpress_id', collect($inventory['post_ids'])
                    ->filter(fn (string $section): bool => $section === 'auto_drafts')->keys()->all())
                ->pluck('wordpress_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all(),
            'post_mismatch_ids' => [],
            'category_mismatch_ids' => [],
            'tag_mismatch_ids' => [],
            'author_mismatch_ids' => [],
            'published_import_manual_review_ids' => [],
            'published_import_blocking_manual_review_ids' => [],
            'published_import_superseded_frozen_decision_ids' => [],
            'published_import_retryable_media_ids' => [],
            'published_import_retryable_featured_media_ids' => [],
            'published_import_retryable_inline_media_ids' => [],
            'approved_unavailable_media_ids' => $inventory['approved_unavailable_media_ids'],
            'published_redirect_mismatch_ids' => [],
            'category_redirect_mismatch_ids' => [],
            'tag_redirect_mismatch_ids' => [],
            'author_redirect_mismatch_ids' => [],
            'nonpublic_active_redirect_ids' => [],
            'taxonomy_dependency_mismatch_ids' => [],
            'extra_category_record_ids' => $this->extraRecordIds('category', $inventory['category_ids']),
            'extra_tag_record_ids' => $this->extraRecordIds('tag', $inventory['tag_ids']),
            'extra_author_record_ids' => $this->extraRecordIds('author', $inventory['author_ids']),
            'duplicate_category_mapping_ids' => $this->duplicateMappingWordpressIds('category', Category::class, $inventory['category_ids']),
            'duplicate_tag_mapping_ids' => $this->duplicateMappingWordpressIds('tag', Tag::class, $inventory['tag_ids']),
            'duplicate_author_mapping_ids' => $this->duplicateMappingWordpressIds('author', Author::class, $inventory['author_ids']),
        ];
        $statusEvidence = [
            'published_posts' => [],
            'nonpublic_posts' => [],
            'auto_drafts' => [],
        ];

        foreach (['published_posts', 'nonpublic_posts'] as $section) {
            $this->eachChunk($bundle, $section, function (array $records) use ($section, &$plan, &$issues, &$statusEvidence): void {
                $payloads = $section === 'nonpublic_posts'
                    ? collect($records)->mapWithKeys(fn (array $row): array => [(int) data_get($row, 'payload.id') => $row])->all()
                    : collect($records)->mapWithKeys(fn (array $row): array => [(int) ($row['id'] ?? 0) => $row])->all();
                $ids = array_keys($payloads);
                $referenceIds = collect($payloads)->flatMap(function (array $record) use ($section): array {
                    $payload = $section === 'nonpublic_posts' ? $record['payload'] : $record;

                    return [
                        (int) ($payload['author'] ?? 0),
                        ...array_map('intval', (array) ($payload['categories'] ?? [])),
                        ...array_map('intval', (array) ($payload['tags'] ?? [])),
                    ];
                })->filter()->unique()->values()->all();
                $articles = Article::query()
                    ->whereIn('old_wordpress_id', $ids)
                    ->with([
                        'categories:id',
                        'tags:id',
                        'featuredImage:id,url,disk,path,metadata',
                        'seoMetadata',
                    ])
                    ->get([
                        'id',
                        'old_wordpress_id',
                        'title',
                        'slug',
                        'excerpt',
                        'body_html',
                        'status',
                        'type',
                        'author_id',
                        'category_id',
                        'featured_image_id',
                        'published_at',
                        'scheduled_at',
                        'is_sponsored',
                        'is_ai_generated',
                        'is_ai_assisted',
                        'created_at',
                        'updated_at',
                        'old_url',
                    ])
                    ->keyBy('old_wordpress_id');
                $importRecords = WordpressImportRecord::query()
                    ->where('wordpress_type', 'post')
                    ->whereIn('wordpress_id', $ids)
                    ->get(['id', 'wordpress_id', 'importable_type', 'importable_id', 'old_url', 'new_url', 'status', 'source_payload', 'warnings'])
                    ->keyBy('wordpress_id');
                $mappingRecords = WordpressImportRecord::query()
                    ->whereIn('wordpress_type', ['author', 'category', 'tag'])
                    ->whereIn('wordpress_id', $referenceIds)
                    ->get(['wordpress_type', 'wordpress_id', 'importable_type', 'importable_id', 'status'])
                    ->keyBy(fn (WordpressImportRecord $mapping): string => $mapping->wordpress_type.':'.$mapping->wordpress_id);

                foreach ($payloads as $wordpressId => $record) {
                    $payload = $section === 'nonpublic_posts' ? $record['payload'] : $record;
                    $targetStatus = $section === 'nonpublic_posts' ? $record['target_status'] : 'published';
                    $article = $articles->get($wordpressId);
                    $importRecord = $importRecords->get($wordpressId);
                    $reasons = $this->postMismatchReasons(
                        $payload,
                        $targetStatus,
                        $article,
                        $importRecord,
                        $section,
                        $mappingRecords->all(),
                    );
                    $nonpublicSourceUrls = $section === 'nonpublic_posts'
                        ? $this->nonpublicSourceUrls($payload, $importRecord)
                        : [];
                    $activeRedirect = collect($nonpublicSourceUrls)
                        ->contains(fn (string $sourceUrl): bool => $this->hasActiveRedirect($sourceUrl));
                    $publishedRedirectMismatch = $section === 'published_posts'
                        && $this->redirectNeedsRepair(
                            $payload['link'] ?? null,
                            '/'.($article instanceof Article
                                ? $article->slug
                                : $this->expectedArticleSlug($payload, $wordpressId)),
                        );

                    if ($reasons !== []) {
                        $plan[$section]['ids'][] = $wordpressId;
                        $issues['post_mismatch_ids'][] = $wordpressId;

                        foreach ($reasons as $reason) {
                            $issues[$reason] ??= [];
                            $issues[$reason][] = $wordpressId;
                        }
                    }

                    if ($activeRedirect) {
                        $plan['nonpublic_posts']['redirect_ids'][] = $wordpressId;
                        $issues['nonpublic_active_redirect_ids'][] = $wordpressId;
                    }

                    if ($publishedRedirectMismatch) {
                        $plan['published_posts']['redirect_ids'][] = $wordpressId;
                        $issues['published_redirect_mismatch_ids'][] = $wordpressId;
                    }

                    if ($section === 'published_posts'
                        && $importRecord instanceof WordpressImportRecord
                        && in_array('manual_review', (array) $importRecord->warnings, true)) {
                        $issues['published_import_manual_review_ids'][] = $wordpressId;
                        $supersededFrozenDecision = $this->isSupersededByValidatedBlobDecision(
                            $importRecord->warnings,
                            $payload,
                        );

                        if ($supersededFrozenDecision) {
                            $issues['published_import_superseded_frozen_decision_ids'][] = $wordpressId;
                        }

                        if ($this->isRetryableMediaWarning($importRecord->warnings, $payload)) {
                            $issues['published_import_retryable_media_ids'][] = $wordpressId;

                            if (in_array('featured_image_download_failed', $importRecord->warnings, true)) {
                                $issues['published_import_retryable_featured_media_ids'][] = $wordpressId;
                            }

                            if (in_array('inline_image_download_failed', $importRecord->warnings, true)) {
                                $issues['published_import_retryable_inline_media_ids'][] = $wordpressId;
                            }
                        } elseif (! $supersededFrozenDecision) {
                            $issues['published_import_blocking_manual_review_ids'][] = $wordpressId;
                        }
                    }

                    $statusEvidence[$section][] = [
                        'wordpress_id' => $wordpressId,
                        'cms_article_id' => $article instanceof Article ? (int) $article->id : null,
                        'source_status' => (string) $payload['status'],
                        'target_status' => $targetStatus,
                        'exact' => $reasons === [] && ! $activeRedirect && ! $publishedRedirectMismatch,
                    ];
                }
            });
        }

        foreach (['categories' => 'category', 'tags' => 'tag'] as $section => $type) {
            $this->eachChunk($bundle, $section, function (array $terms) use ($section, $type, $inventory, &$plan, &$issues): void {
                $ids = collect($terms)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
                $parentIds = $type === 'category'
                    ? collect($terms)->pluck('parent')->map(fn (mixed $id): int => (int) $id)->filter()->all()
                    : [];
                $records = WordpressImportRecord::query()
                    ->where('wordpress_type', $type)
                    ->whereIn('wordpress_id', array_values(array_unique([...$ids, ...$parentIds])))
                    ->get(['wordpress_id', 'importable_type', 'importable_id', 'old_url', 'new_url', 'status', 'source_payload'])
                    ->keyBy('wordpress_id');
                $modelClass = $type === 'category' ? Category::class : Tag::class;
                $targets = $modelClass::query()
                    ->whereIn('id', $records->pluck('importable_id')->filter()->all())
                    ->get($type === 'category'
                        ? ['id', 'name', 'slug', 'description', 'is_active', 'parent_id']
                        : ['id', 'name', 'slug'])
                    ->keyBy('id');

                foreach ($terms as $term) {
                    $id = (int) $term['id'];
                    $record = $records->get($id);
                    $target = $record ? $targets->get((int) $record->importable_id) : null;
                    $mappedCategory = $type === 'category'
                        ? $this->mappedCategoryTarget($id)
                        : null;
                    $parentWordpressId = (int) ($term['parent'] ?? 0);
                    $expectedParentId = $parentWordpressId > 0
                        ? (int) ($records->get($parentWordpressId)?->importable_id ?? 0)
                        : null;
                    $parentMismatch = $type === 'category'
                        && (($parentWordpressId > 0 && $expectedParentId < 1)
                            || (int) ($target?->parent_id ?? 0) !== (int) ($expectedParentId ?? 0));
                    $expectedSlug = $type === 'tag'
                        ? (string) ($inventory['tag_target_slugs'][$id] ?? '')
                        : ($mappedCategory?->slug ?? (Str::slug(rawurldecode((string) $term['slug']))
                            ?: Str::slug($this->cleanText($term['name'] ?? null))));
                    $expectedNewUrl = $type === 'category'
                        ? "/category/{$expectedSlug}"
                        : "/tag/{$expectedSlug}";
                    $categoryFieldsMismatch = $type === 'category'
                        && (($mappedCategory === null
                                && $target?->description !== $this->cleanNullableText($term['description'] ?? null))
                            || ! (bool) $target?->is_active);
                    $expectedSourcePayload = $term;
                    $expectedSourcePayload['_razbudise']['frozen_taxonomy_evidence_version'] = self::FROZEN_TAXONOMY_EVIDENCE_VERSION;

                    if (! $record
                        || $record->status !== 'imported'
                        || $record->importable_type !== $modelClass
                        || ! $target
                        || ($mappedCategory instanceof Category
                            && (int) $target->id !== (int) $mappedCategory->id)
                        || $target->slug !== $expectedSlug
                        || ($mappedCategory === null
                            && $target->name !== $this->cleanText($term['name'] ?? null))
                        || $categoryFieldsMismatch
                        || $parentMismatch
                        || trim((string) $record->old_url) !== trim((string) ($term['link'] ?? ''))
                        || $record->new_url !== $expectedNewUrl
                        || $this->sourcePayloadHash($record->source_payload)
                            !== $this->sourcePayloadHash($expectedSourcePayload)) {
                        $plan[$section]['ids'][] = $id;
                        $issues[$type.'_mismatch_ids'][] = $id;
                    }

                    if ($this->redirectNeedsRepair($term['link'] ?? null, $expectedNewUrl)) {
                        $plan[$section]['redirect_ids'][] = $id;
                        $issues[$type.'_redirect_mismatch_ids'][] = $id;
                    }
                }
            });
        }

        foreach ($this->records($bundle, 'authors') as $entry) {
            $author = $entry['record'];
            $id = (int) $author['wordpress_id'];
            $record = WordpressImportRecord::query()
                ->where('wordpress_type', 'author')
                ->where('wordpress_id', $id)
                ->first();
            $target = $record?->importable_type === Author::class ? Author::query()->find($record->importable_id) : null;
            $expectedSlug = Str::slug(rawurldecode((string) $author['source_slug']));

            if (! $record
                || $record->status !== 'imported'
                || ! $target
                || $target->slug !== $expectedSlug
                || $target->name !== $this->cleanText($author['display_name'])
                || trim((string) $record->old_url) !== trim((string) $author['old_url'])
                || $record->new_url !== "/profil/{$expectedSlug}"
                || (int) data_get($record->source_payload, 'id') !== $id
                || (string) data_get($record->source_payload, 'slug') !== (string) $author['source_slug']
                || $this->cleanText(data_get($record->source_payload, 'name')) !== $this->cleanText($author['display_name'])
                || trim((string) data_get($record->source_payload, 'link')) !== trim((string) $author['old_url'])) {
                $plan['authors']['ids'][] = $id;
                $issues['author_mismatch_ids'][] = $id;
            }

            if ($this->redirectNeedsRepair($author['old_url'] ?? null, "/profil/{$expectedSlug}")) {
                $plan['authors']['redirect_ids'][] = $id;
                $issues['author_redirect_mismatch_ids'][] = $id;
            }
        }

        $this->includeTaxonomyDependentPosts($bundle, $plan, $issues, $statusEvidence);

        foreach ($this->records($bundle, 'auto_drafts') as $entry) {
            $wordpressId = (int) $entry['record']['wordpress_id'];
            $plan['auto_drafts']['ids'][] = $wordpressId;
            $statusEvidence['auto_drafts'][] = [
                'wordpress_id' => $wordpressId,
                'disposition' => 'retain_in_verified_wordpress_backup',
            ];
        }

        foreach (['published_posts', 'nonpublic_posts', 'categories', 'tags'] as $section) {
            $plan[$section]['ids'] = array_values(array_unique($plan[$section]['ids']));
            sort($plan[$section]['ids']);
            $plan[$section]['upsert_count'] = count($plan[$section]['ids']);
        }

        foreach (['authors'] as $section) {
            $plan[$section]['ids'] = array_values(array_unique($plan[$section]['ids']));
            sort($plan[$section]['ids']);
            $plan[$section]['legacy_url_update_count'] = count($plan[$section]['ids']);
        }

        foreach (['published_posts', 'categories', 'tags', 'authors'] as $section) {
            $plan[$section]['redirect_ids'] = array_values(array_unique($plan[$section]['redirect_ids']));
            sort($plan[$section]['redirect_ids']);
            $plan[$section]['redirect_count'] = count($plan[$section]['redirect_ids']);
        }

        $plan['nonpublic_posts']['redirect_ids'] = array_values(array_unique($plan['nonpublic_posts']['redirect_ids']));
        sort($plan['nonpublic_posts']['redirect_ids']);
        $plan['nonpublic_posts']['deactivate_redirect_count'] = count($plan['nonpublic_posts']['redirect_ids']);
        $plan['total_writes'] = $plan['published_posts']['upsert_count']
            + $plan['nonpublic_posts']['upsert_count']
            + $plan['nonpublic_posts']['deactivate_redirect_count']
            + $plan['categories']['upsert_count']
            + $plan['tags']['upsert_count']
            + $plan['authors']['legacy_url_update_count']
            + $plan['published_posts']['redirect_count']
            + $plan['categories']['redirect_count']
            + $plan['tags']['redirect_count']
            + $plan['authors']['redirect_count'];

        foreach ($issues as &$ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids);
        }
        unset($ids);

        $safetyBlockers = [];

        foreach ([
            'duplicate_local_post_ids',
            'local_post_ids_absent_from_source',
            'post_record_ids_absent_from_source',
            'auto_draft_cms_collision_ids',
            'auto_draft_import_record_ids',
            'extra_category_record_ids',
            'extra_tag_record_ids',
            'extra_author_record_ids',
            'duplicate_category_mapping_ids',
            'duplicate_author_mapping_ids',
        ] as $key) {
            if ($issues[$key] !== []) {
                $safetyBlockers[] = $key;
            }
        }

        $complete = $plan['total_writes'] === 0 && $safetyBlockers === [];

        return [
            'parity' => [
                'exact' => $complete,
                'status_evidence' => $statusEvidence,
                'issues' => collect($issues)->map(fn (array $ids): array => [
                    'count' => count($ids),
                    'ids' => $ids,
                ])->all(),
            ],
            'plan' => $plan,
            'safety_blockers' => $safetyBlockers,
            'complete' => $complete,
        ];
    }

    /**
     * A taxonomy identity can be remapped before its posts are imported. Make
     * those dependent posts part of the same apply plan so their pivot rows are
     * synchronized against the repaired WordPress-ID mappings in one pass.
     *
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>  $plan
     * @param  array<string, array<int, int>>  $issues
     * @param  array<string, array<int, array<string, mixed>>>  $statusEvidence
     */
    private function includeTaxonomyDependentPosts(
        array $bundle,
        array &$plan,
        array &$issues,
        array &$statusEvidence,
    ): void {
        $categoryIds = array_fill_keys(array_map('intval', $plan['categories']['ids']), true);
        $tagIds = array_fill_keys(array_map('intval', $plan['tags']['ids']), true);

        if ($categoryIds === [] && $tagIds === []) {
            return;
        }

        foreach (['published_posts', 'nonpublic_posts'] as $section) {
            foreach ($this->records($bundle, $section) as $entry) {
                $record = $entry['record'];
                $payload = $section === 'nonpublic_posts' ? ($record['payload'] ?? []) : $record;
                $wordpressId = (int) ($payload['id'] ?? 0);
                $dependsOnChangedCategory = collect((array) ($payload['categories'] ?? []))
                    ->contains(fn (mixed $id): bool => isset($categoryIds[(int) $id]));
                $dependsOnChangedTag = collect((array) ($payload['tags'] ?? []))
                    ->contains(fn (mixed $id): bool => isset($tagIds[(int) $id]));

                if (! $dependsOnChangedCategory && ! $dependsOnChangedTag) {
                    continue;
                }

                $plan[$section]['ids'][] = $wordpressId;
                $issues['post_mismatch_ids'][] = $wordpressId;
                $issues['taxonomy_dependency_mismatch_ids'][] = $wordpressId;

                foreach ($statusEvidence[$section] as &$evidence) {
                    if ((int) $evidence['wordpress_id'] === $wordpressId) {
                        $evidence['exact'] = false;

                        break;
                    }
                }
                unset($evidence);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function postMismatchReasons(
        array $payload,
        string $targetStatus,
        mixed $article,
        mixed $record,
        string $section,
        array $mappingRecords,
    ): array {
        if (! $article instanceof Article) {
            return ['missing_article'];
        }

        $reasons = [];
        $sourceDate = $this->normalizedDate($payload['date_gmt'] ?? null);
        $sourceModified = $this->normalizedDate($payload['modified_gmt'] ?? null);
        $sourceBody = (string) data_get($payload, 'content.rendered');
        $pipeline = $record instanceof WordpressImportRecord
            ? data_get($record->source_payload, '_razbudise.content_pipeline')
            : null;
        $unavailableMedia = data_get($payload, '_razbudise.unavailable_media');
        $unavailableMedia = is_array($unavailableMedia) ? $unavailableMedia : [];
        $trustedUnavailableInlineMedia = is_array($unavailableMedia['inline'] ?? null)
            ? $unavailableMedia['inline']
            : [];
        $preview = $this->contentSanitizer->preview(
            $sourceBody,
            is_array(data_get($payload, '_razbudise.blob_image_removal')),
            $trustedUnavailableInlineMedia,
        );

        if ($article->status !== $targetStatus) {
            $reasons[] = 'status_mismatch';
        }

        if ($targetStatus === 'published'
            && $this->normalizedDate($article->published_at?->toIso8601String()) !== $sourceDate) {
            $reasons[] = 'published_at_mismatch';
        }

        if ($targetStatus === 'scheduled'
            && $this->normalizedDate($article->scheduled_at?->toIso8601String()) !== $sourceDate) {
            $reasons[] = 'scheduled_at_mismatch';
        }

        if ($targetStatus !== 'published' && $article->published_at !== null) {
            $reasons[] = 'nonpublic_published_at_present';
        }

        if ($targetStatus !== 'scheduled' && $article->scheduled_at !== null) {
            $reasons[] = 'unexpected_schedule';
        }

        if ($section === 'nonpublic_posts'
            && $this->normalizedDate($article->created_at?->toIso8601String()) !== $sourceDate) {
            $reasons[] = 'created_at_mismatch';
        }

        if ($this->normalizedDate($article->updated_at?->toIso8601String()) !== $sourceModified) {
            $reasons[] = 'modified_at_mismatch';
        }

        if ($article->title !== $this->importer->materializedPostTitle(
            $payload,
            $section === 'nonpublic_posts',
        )
            || $article->excerpt !== $this->cleanNullableText(data_get($payload, 'excerpt.rendered'))
            || trim((string) $article->old_url) !== trim((string) ($payload['link'] ?? ''))
            || $article->type !== 'editorial'
            || (bool) $article->is_sponsored
            || (bool) $article->is_ai_generated
            || (bool) $article->is_ai_assisted) {
            $reasons[] = 'materialized_fields_mismatch';
        }

        if ($article->slug !== $this->expectedArticleSlug($payload, (int) $article->old_wordpress_id)) {
            $reasons[] = 'slug_mismatch';
        }

        if (! is_array($pipeline)
            || ($pipeline['version'] ?? null) !== WordpressRestImporter::CONTENT_PIPELINE_VERSION
            || ($pipeline['source_body_sha256'] ?? null) !== hash('sha256', $sourceBody)
            || ($pipeline['preview_body_sha256'] ?? null) !== hash('sha256', (string) ($preview['html'] ?? ''))) {
            $reasons[] = 'content_pipeline_mismatch';
        }

        if (! is_array($pipeline)
            || ($pipeline['persisted_body_sha256'] ?? null) !== hash('sha256', (string) $article->body_html)) {
            $reasons[] = 'content_body_mismatch';
        }

        $persistedSourcePayload = $record instanceof WordpressImportRecord
            && is_array($record->source_payload)
                ? $record->source_payload
                : null;
        $persistedWarnings = $record instanceof WordpressImportRecord
            && is_array($record->warnings)
                ? $record->warnings
                : [];
        $persistedNormalizationCounts = is_array($persistedSourcePayload)
            && is_array(data_get($persistedSourcePayload, '_razbudise.normalization_counts'))
                ? data_get($persistedSourcePayload, '_razbudise.normalization_counts')
                : [];

        if (! is_array($persistedSourcePayload)
            || ! $this->contentDecisionProvenance->hasValidReconciliationPayload(
                $persistedSourcePayload,
            )) {
            $reasons[] = 'reconciliation_provenance_mismatch';
        }

        if (! is_array($persistedSourcePayload)
            || ! $this->contentDecisionProvenance->hasValidMaterialization(
                $persistedSourcePayload,
                (string) $article->body_html,
                $persistedWarnings,
                $persistedNormalizationCounts,
            )) {
            $reasons[] = 'materialization_provenance_mismatch';
        }

        $authorWordpressId = (int) ($payload['author'] ?? 0);
        $expectedAuthorId = $this->mappedTargetId($mappingRecords, 'author', $authorWordpressId, Author::class);
        $categoryWordpressIds = $this->embeddedTermIds($payload, 'category');
        $tagWordpressIds = $this->embeddedTermIds($payload, 'post_tag');
        $expectedCategoryIds = $this->mappedTargetIds($mappingRecords, 'category', $categoryWordpressIds, Category::class);
        $expectedTagIds = $this->mappedTargetIds($mappingRecords, 'tag', $tagWordpressIds, Tag::class);
        $expectedPrimaryCategoryId = $categoryWordpressIds === []
            ? null
            : $this->mappedTargetId($mappingRecords, 'category', $categoryWordpressIds[0], Category::class);
        $actualCategoryIds = $article->categories->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
        $actualTagIds = $article->tags->pluck('id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();

        if ($expectedAuthorId === null
            || (int) $article->author_id !== $expectedAuthorId
            || $expectedCategoryIds === null
            || $expectedTagIds === null
            || ($expectedPrimaryCategoryId !== null && (int) $article->category_id !== $expectedPrimaryCategoryId)
            || ($expectedPrimaryCategoryId === null && $article->category_id !== null)
            || $actualCategoryIds !== $expectedCategoryIds
            || $actualTagIds !== $expectedTagIds) {
            $reasons[] = 'association_mismatch';
        }

        $featuredMedia = $this->effectiveFeaturedMedia($payload);
        $featuredWordpressId = is_array($featuredMedia) ? (int) ($featuredMedia['id'] ?? 0) : 0;
        $featuredSourceUrl = is_array($featuredMedia) ? (string) ($featuredMedia['source_url'] ?? '') : '';
        $usesFeaturedFallback = is_array(data_get($payload, '_razbudise.featured_image_fallback'));
        $featuredSource = $usesFeaturedFallback
            ? 'wordpress_featured_fallback'
            : 'wordpress_rest';
        $featuredRole = $usesFeaturedFallback ? 'featured_fallback' : 'featured';
        $usesUnavailableFeaturedMedia = is_array($unavailableMedia['featured'] ?? null);
        $featuredImage = $article->featuredImage;
        $featuredSources = is_array(data_get($featuredImage?->metadata, 'sources'))
            ? data_get($featuredImage?->metadata, 'sources')
            : [];
        $featuredRoles = is_array(data_get($featuredImage?->metadata, 'roles'))
            ? data_get($featuredImage?->metadata, 'roles')
            : [];

        if (($usesUnavailableFeaturedMedia && $article->featured_image_id !== null)
            || (! $usesUnavailableFeaturedMedia
                && (($featuredWordpressId < 1 && $article->featured_image_id !== null)
                    || ($featuredWordpressId > 0
                        && (! $featuredImage
                            || (int) data_get($featuredImage->metadata, 'wordpress_id') !== $featuredWordpressId
                            || (string) data_get($featuredImage->metadata, 'source_identity') !== 'wordpress_attachment'
                            || ! in_array($featuredSource, $featuredSources, true)
                            || ! in_array('featured', $featuredRoles, true)
                            || ! in_array($featuredRole, $featuredRoles, true)
                            || (string) data_get($featuredImage->metadata, 'source_url') !== $featuredSourceUrl
                            || ! $this->storedMediaExists($featuredImage)))))) {
            $reasons[] = 'featured_media_mismatch';
        }

        if ($unavailableMedia !== []) {
            $reasons = [
                ...$reasons,
                ...$this->unavailableMediaMismatchReasons(
                    $unavailableMedia,
                    $payload,
                    $preview,
                    $record,
                    (string) $article->body_html,
                    $section,
                ),
            ];
        }

        $yoast = data_get($payload, 'yoast_head_json', []);
        $yoast = is_array($yoast) ? $yoast : [];
        $seo = $article->seoMetadata;

        if (! $seo
            || $seo->seo_title !== ($this->cleanText($yoast['title'] ?? null) ?: $article->title)
            || $seo->meta_description !== ($this->cleanText($yoast['description'] ?? null) ?: $article->excerpt)
            || $seo->canonical_url !== $article->publicUrl()
            || $seo->og_title !== ($this->cleanText($yoast['og_title'] ?? null) ?: null)
            || $seo->og_description !== ($this->cleanText($yoast['og_description'] ?? null) ?: null)) {
            $reasons[] = 'seo_mismatch';
        }

        if (! $record instanceof WordpressImportRecord
            || $record->status !== 'imported'
            || $record->importable_type !== Article::class
            || (int) $record->importable_id !== (int) $article->id
            || trim((string) $record->old_url) !== trim((string) ($payload['link'] ?? ''))
            || $this->sourcePayloadHash($record->source_payload) !== $this->sourcePayloadHash($payload)) {
            $reasons[] = 'source_evidence_mismatch';
        }

        if ($section === 'published_posts'
            && $record instanceof WordpressImportRecord
            && $this->isRetryableMediaWarning($record->warnings, $payload)) {
            $reasons[] = 'retryable_media_warning';
        } elseif ($section === 'published_posts'
            && $record instanceof WordpressImportRecord
            && $this->isSupersededByValidatedBlobDecision($record->warnings, $payload)) {
            $reasons[] = 'superseded_frozen_decision_warning';
        }

        if ($section === 'nonpublic_posts' && $record instanceof WordpressImportRecord && $record->new_url !== null) {
            $reasons[] = 'nonpublic_new_url_present';
        }

        if ($section === 'published_posts'
            && $record instanceof WordpressImportRecord
            && $record->new_url !== "/{$article->slug}") {
            $reasons[] = 'public_new_url_mismatch';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, string>
     */
    private function frozenPublishedLinkMap(array $bundle): array
    {
        $linkMap = [];

        foreach ($this->records($bundle, 'published_posts') as $entry) {
            $payload = $entry['record'];
            $oldUrl = trim((string) ($payload['link'] ?? ''));

            if ($oldUrl === '') {
                continue;
            }

            $linkMap[$oldUrl] = '/'.$this->expectedArticleSlug($payload, (int) $payload['id']);
        }

        return $linkMap;
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>  $inventory
     * @param  array<string, mixed>  $plan
     * @return array{attempted: int, succeeded: int, failed: int, failures: array<int, array{source_type: string, wordpress_id: int, reason: string, exception_class: string, exception_message: string}>}
     */
    private function applyPlan(array $bundle, array $inventory, array $plan): array
    {
        $summary = ['attempted' => 0, 'succeeded' => 0, 'failed' => 0, 'failures' => []];
        $categoryIds = array_fill_keys($plan['categories']['ids'], true);
        $tagIds = array_fill_keys($plan['tags']['ids'], true);
        $publishedIds = array_fill_keys($plan['published_posts']['ids'], true);
        $publishedRedirectIds = array_fill_keys($plan['published_posts']['redirect_ids'], true);
        $nonpublicIds = array_fill_keys($plan['nonpublic_posts']['ids'], true);
        $redirectIds = array_fill_keys($plan['nonpublic_posts']['redirect_ids'], true);
        $authorIds = array_fill_keys($plan['authors']['ids'], true);
        $categoryRedirectIds = array_fill_keys($plan['categories']['redirect_ids'], true);
        $tagRedirectIds = array_fill_keys($plan['tags']['redirect_ids'], true);
        $authorRedirectIds = array_fill_keys($plan['authors']['redirect_ids'], true);
        $categories = [];

        foreach ($this->records($bundle, 'categories') as $entry) {
            $term = $entry['record'];
            $id = (int) $term['id'];
            $categories[] = $term;

            if (isset($categoryIds[$id])) {
                $this->attempt($summary, 'category', $id, fn () => DB::transaction(
                    fn () => $this->importer->importStoredCategoryPayload($term),
                ), $term, 'category');
            }

            if (isset($categoryRedirectIds[$id])) {
                $slug = $this->mappedCategoryTarget($id)?->slug
                    ?? (Str::slug(rawurldecode((string) $term['slug']))
                        ?: Str::slug($this->cleanText($term['name'] ?? null)));
                $this->attempt(
                    $summary,
                    'category_redirect',
                    $id,
                    fn () => $this->repairMappedRedirect(
                        'category',
                        $id,
                        Category::class,
                        $slug,
                        $term['link'] ?? null,
                        "/category/{$slug}",
                    ),
                );
            }
        }

        if ($categoryIds !== []) {
            try {
                DB::transaction(fn () => $this->importer->syncStoredCategoryParents($categories));
            } catch (Throwable) {
                $summary['attempted']++;
                $summary['failed']++;
                $summary['failures'][] = ['source_type' => 'category_parent', 'wordpress_id' => 0, 'reason' => 'import_failed'];
            }
        }

        unset($categories);

        foreach ($this->records($bundle, 'tags') as $entry) {
            $term = $entry['record'];
            $id = (int) $term['id'];
            $slug = (string) ($inventory['tag_target_slugs'][$id] ?? '');

            if (isset($tagIds[$id])) {
                $this->attempt($summary, 'tag', $id, fn () => DB::transaction(
                    fn () => $this->importer->importStoredTagPayload($term, $slug),
                ), $term, 'tag');
            }

            if (isset($tagRedirectIds[$id])) {
                $this->attempt(
                    $summary,
                    'tag_redirect',
                    $id,
                    fn () => $this->repairMappedRedirect(
                        'tag',
                        $id,
                        Tag::class,
                        $slug,
                        $term['link'] ?? null,
                        "/tag/{$slug}",
                    ),
                );
            }
        }

        foreach ($this->records($bundle, 'published_posts') as $entry) {
            $payload = $entry['record'];
            $id = (int) $payload['id'];

            if (isset($publishedIds[$id])) {
                $this->attempt($summary, 'published_post', $id, function () use ($payload, $bundle): void {
                    DB::transaction(function () use ($payload, $bundle): void {
                        if (($payload['status'] ?? null) !== 'publish') {
                            throw new RuntimeException('published_source_status_invalid');
                        }

                        $article = $this->importer->importStoredPostPayload(
                            $this->withFrozenReconciliationProvenance($payload, $bundle),
                            $bundle['metadata']['source_base_url'],
                            trustContentDecisionEvidence: true,
                        );
                        $importRecord = WordpressImportRecord::query()
                            ->where('wordpress_type', 'post')
                            ->where('wordpress_id', (int) $payload['id'])
                            ->first();

                        if ($article->status !== 'published'
                            || ($importRecord instanceof WordpressImportRecord
                                && in_array('manual_review', (array) $importRecord->warnings, true))) {
                            throw new RuntimeException('published_postcondition_failed');
                        }
                    });
                }, $payload, 'post');
            }

            if (isset($publishedRedirectIds[$id])) {
                $this->attempt(
                    $summary,
                    'published_redirect',
                    $id,
                    function () use ($payload, $id): void {
                        $article = Article::query()
                            ->where('old_wordpress_id', $id)
                            ->where('status', 'published')
                            ->first(['slug']);

                        if (! $article instanceof Article
                            || $article->slug !== $this->expectedArticleSlug($payload, $id)) {
                            throw new RuntimeException('published_redirect_target_invalid');
                        }

                        $this->repairRedirect($payload['link'] ?? null, "/{$article->slug}");
                    },
                );
            }
        }

        foreach ($this->records($bundle, 'nonpublic_posts') as $entry) {
            $row = $entry['record'];
            $payload = $row['payload'];
            $id = (int) $payload['id'];
            $targetStatus = (string) $row['target_status'];
            $existingRecord = WordpressImportRecord::query()
                ->where('wordpress_type', 'post')
                ->where('wordpress_id', $id)
                ->first(['old_url', 'source_payload']);
            $sourceUrls = $this->nonpublicSourceUrls($payload, $existingRecord);

            if (isset($nonpublicIds[$id])) {
                $this->attempt($summary, 'nonpublic_post', $id, function () use ($payload, $targetStatus, $bundle): void {
                    DB::transaction(function () use ($payload, $targetStatus, $bundle): void {
                        $sourceStatus = (string) ($payload['status'] ?? '');

                        if (! $this->validNonpublicMapping($sourceStatus, $targetStatus)) {
                            throw new RuntimeException('nonpublic_source_status_invalid');
                        }

                        $article = $this->importer->importStoredPostPayload(
                            $this->withFrozenReconciliationProvenance($payload, $bundle),
                            $bundle['metadata']['source_base_url'],
                            trustContentDecisionEvidence: true,
                            allowUntitledNonpublic: true,
                        );

                        if ($targetStatus === 'archived') {
                            $article->timestamps = false;

                            try {
                                $article->forceFill([
                                    'status' => 'archived',
                                    'published_at' => null,
                                    'scheduled_at' => null,
                                    'created_at' => CarbonImmutable::parse($payload['date_gmt'], 'UTC'),
                                    'updated_at' => CarbonImmutable::parse($payload['modified_gmt'], 'UTC'),
                                ])->saveQuietly();
                            } finally {
                                $article->timestamps = true;
                            }
                        }

                        $article->refresh();

                        if (! in_array($article->status, ['draft', 'review', 'scheduled', 'archived'], true)
                            || $article->status !== $targetStatus
                            || $article->published_at !== null) {
                            throw new RuntimeException('nonpublic_postcondition_failed');
                        }
                    });
                }, $payload, 'post');
            }

            if (isset($redirectIds[$id])) {
                $this->attempt($summary, 'nonpublic_redirect', $id, function () use ($sourceUrls): void {
                    foreach ($sourceUrls as $sourceUrl) {
                        $this->deactivateRedirects($sourceUrl);
                    }
                });
            }
        }

        foreach ($this->records($bundle, 'authors') as $entry) {
            $author = $entry['record'];
            $id = (int) $author['wordpress_id'];

            if (isset($authorIds[$id])) {
                $this->attempt($summary, 'author', $id, function () use ($author): void {
                    DB::transaction(fn () => $this->importer->importStoredAuthorPayload($author));
                }, [
                    'id' => $id,
                    'slug' => $author['source_slug'],
                    'name' => $author['display_name'],
                    'link' => $author['old_url'],
                ], 'author');
            }

            if (isset($authorRedirectIds[$id])) {
                $slug = Str::slug(rawurldecode((string) $author['source_slug']));
                $this->attempt(
                    $summary,
                    'author_redirect',
                    $id,
                    fn () => $this->repairMappedRedirect(
                        'author',
                        $id,
                        Author::class,
                        $slug,
                        $author['old_url'] ?? null,
                        "/profil/{$slug}",
                    ),
                );
            }
        }

        return $summary;
    }

    private function mappedCategoryTarget(int $wordpressId): ?Category
    {
        if ($wordpressId < 1) {
            return null;
        }

        $mapping = WordpressImportMapping::query()
            ->where('source_type', 'category')
            ->where('wordpress_id', $wordpressId)
            ->where('target_type', Category::class)
            ->first(['target_id']);

        return $mapping?->target_id
            ? Category::query()->find($mapping->target_id)
            : null;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function attempt(
        array &$summary,
        string $sourceType,
        int $wordpressId,
        callable $callback,
        ?array $sourcePayload = null,
        ?string $wordpressType = null,
    ): void {
        $summary['attempted']++;

        try {
            $callback();
            $summary['succeeded']++;
        } catch (Throwable $exception) {
            if ($wordpressId > 0 && $wordpressType !== null) {
                WordpressImportRecord::query()->updateOrCreate(
                    ['wordpress_id' => $wordpressId, 'wordpress_type' => $wordpressType],
                    [
                        'old_url' => $sourcePayload['link'] ?? null,
                        'status' => 'failed',
                        'source_payload' => $sourcePayload,
                        'warnings' => [],
                        'error_message' => 'content_reconciliation_import_failed',
                        'imported_at' => null,
                    ],
                );
            }

            $summary['failed']++;
            $summary['failures'][] = [
                'source_type' => $sourceType,
                'wordpress_id' => $wordpressId,
                'reason' => 'import_failed',
                'exception_class' => $exception::class,
                'exception_message' => Str::limit($exception->getMessage(), 500, ''),
            ];
        }
    }

    private function hasActiveRedirect(mixed $sourceUrl): bool
    {
        $hash = Redirect::sourcePathHash(is_string($sourceUrl) ? $sourceUrl : null);

        if ($hash === null) {
            return false;
        }

        $path = UrlPath::normalize((string) $sourceUrl);

        return Redirect::query()
            ->where('source_path_hash', $hash)
            ->where('is_active', true)
            ->get(['source_url'])
            ->contains(fn (Redirect $redirect): bool => $this->redirectMatchesSource($redirect, (string) $sourceUrl, $path));
    }

    private function redirectNeedsRepair(mixed $sourceUrl, string $targetUrl): bool
    {
        if (! is_string($sourceUrl) || trim($sourceUrl) === '') {
            return false;
        }

        $sourcePath = UrlPath::normalize($sourceUrl);
        $targetPath = UrlPath::normalize($targetUrl);

        if ($sourcePath === '') {
            return false;
        }

        if ($sourcePath === $targetPath) {
            return $this->hasActiveRedirect($sourceUrl);
        }

        $hash = Redirect::sourcePathHash($sourceUrl);

        if ($hash === null) {
            return false;
        }

        $matchingRedirects = Redirect::query()
            ->where('source_path_hash', $hash)
            ->where('is_active', true)
            ->get(['source_url', 'target_url', 'status_code'])
            ->filter(fn (Redirect $redirect): bool => $this->redirectMatchesSource($redirect, $sourceUrl, $sourcePath));

        return $matchingRedirects->count() !== 1
            || (int) $matchingRedirects->first()?->status_code !== 301
            || ! $this->redirectTargetMatches($matchingRedirects->first()?->target_url, $targetUrl);
    }

    private function repairRedirect(mixed $sourceUrl, string $targetUrl): void
    {
        if (! is_string($sourceUrl) || trim($sourceUrl) === '') {
            return;
        }

        $this->deactivateRedirects($sourceUrl);

        if (UrlPath::normalize($sourceUrl) === UrlPath::normalize($targetUrl)) {
            return;
        }

        Redirect::query()->updateOrCreate(
            ['source_url' => $sourceUrl],
            [
                'target_url' => $targetUrl,
                'status_code' => 301,
                'is_active' => true,
            ],
        );
    }

    private function redirectTargetMatches(mixed $actualTarget, string $expectedTarget): bool
    {
        if (! is_string($actualTarget) || trim($actualTarget) === '') {
            return false;
        }

        $actualTarget = trim($actualTarget);
        $expectedPath = UrlPath::normalize($expectedTarget);

        if (str_starts_with($actualTarget, '/') && ! str_starts_with($actualTarget, '//')) {
            $parts = parse_url($actualTarget);

            return is_array($parts)
                && ! isset($parts['scheme'])
                && ! isset($parts['host'])
                && ! isset($parts['user'])
                && ! isset($parts['pass'])
                && ! isset($parts['port'])
                && ! isset($parts['query'])
                && ! isset($parts['fragment'])
                && UrlPath::normalize($actualTarget) === $expectedPath;
        }

        $parts = parse_url($actualTarget);
        $approvedParts = parse_url((string) config('app.frontend_url'));

        return is_array($parts)
            && is_array($approvedParts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && strtolower((string) ($parts['scheme'] ?? '')) === strtolower((string) ($approvedParts['scheme'] ?? ''))
            && strtolower((string) ($parts['host'] ?? '')) === strtolower((string) ($approvedParts['host'] ?? ''))
            && ($parts['port'] ?? null) === ($approvedParts['port'] ?? null)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && UrlPath::normalize($actualTarget) === $expectedPath;
    }

    private function repairMappedRedirect(
        string $wordpressType,
        int $wordpressId,
        string $targetClass,
        string $expectedSlug,
        mixed $sourceUrl,
        string $targetUrl,
    ): void {
        $record = WordpressImportRecord::query()
            ->where('wordpress_type', $wordpressType)
            ->where('wordpress_id', $wordpressId)
            ->first(['status', 'importable_type', 'importable_id']);
        $target = $record?->importable_type === $targetClass
            ? $targetClass::query()->find($record->importable_id, ['slug'])
            : null;

        if ($record?->status !== 'imported' || $target?->slug !== $expectedSlug) {
            throw new RuntimeException("{$wordpressType}_redirect_target_invalid");
        }

        $this->repairRedirect($sourceUrl, $targetUrl);
    }

    private function deactivateRedirects(mixed $sourceUrl): void
    {
        $hash = Redirect::sourcePathHash(is_string($sourceUrl) ? $sourceUrl : null);

        if ($hash === null) {
            return;
        }

        $path = UrlPath::normalize((string) $sourceUrl);

        Redirect::query()
            ->where('source_path_hash', $hash)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Redirect $redirect): bool => $this->redirectMatchesSource($redirect, (string) $sourceUrl, $path))
            ->each(fn (Redirect $redirect) => $redirect->forceFill(['is_active' => false])->save());
    }

    private function redirectMatchesSource(Redirect $redirect, string $sourceUrl, string $normalizedPath): bool
    {
        if (parse_url($sourceUrl, PHP_URL_QUERY) !== null || parse_url($sourceUrl, PHP_URL_FRAGMENT) !== null) {
            return trim($redirect->source_url) === trim($sourceUrl);
        }

        return UrlPath::normalize($redirect->source_url) === $normalizedPath;
    }

    /** @return array<int, string> */
    private function nonpublicSourceUrls(array $payload, ?WordpressImportRecord $record): array
    {
        return collect([
            $payload['link'] ?? null,
            $record?->old_url,
            data_get($record?->source_payload, 'link'),
        ])->filter(fn (mixed $url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => trim($url))
            ->unique()
            ->values()
            ->all();
    }

    private function storedMediaExists(?MediaAsset $asset): bool
    {
        if (! $asset instanceof MediaAsset
            || ! is_string($asset->disk)
            || $asset->disk === ''
            || $asset->disk === 'external'
            || ! is_string($asset->path)
            || $asset->path === '') {
            return false;
        }

        try {
            return Storage::disk($asset->disk)->exists($asset->path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function unavailableMediaMismatchReasons(
        array $evidence,
        array $payload,
        array $preview,
        mixed $record,
        string $persistedBody,
        string $section,
    ): array {
        if (! $record instanceof WordpressImportRecord
            || ! is_array($record->source_payload)) {
            return [
                'unavailable_media_provenance_mismatch',
                'unavailable_media_materialization_mismatch',
                'unavailable_media_evidence_mismatch',
            ];
        }

        $warnings = is_array($record->warnings) ? $record->warnings : [];
        $normalizationCounts = data_get($record->source_payload, '_razbudise.normalization_counts');
        $normalizationCounts = is_array($normalizationCounts) ? $normalizationCounts : [];
        $reasons = [];

        if (! $this->contentDecisionProvenance->hasValidReconciliationPayload(
            $record->source_payload,
        )) {
            $reasons[] = 'unavailable_media_provenance_mismatch';
        }

        if (! $this->contentDecisionProvenance->hasValidMaterialization(
            $record->source_payload,
            $persistedBody,
            $warnings,
            $normalizationCounts,
        )) {
            $reasons[] = 'unavailable_media_materialization_mismatch';
        }

        $expectedWarnings = collect($preview['warnings'] ?? [])
            ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
            ->map(fn (string $warning): string => trim($warning));
        $expectedNormalizationCounts = is_array($preview['normalization_counts'] ?? null)
            ? $preview['normalization_counts']
            : [];

        if (is_array(data_get($payload, '_razbudise.featured_image_fallback'))) {
            $expectedWarnings->push('trusted_featured_image_fallback');
            $expectedNormalizationCounts['trusted_featured_image_fallback'] = 1;
        }

        $featured = $evidence['featured'] ?? null;

        if (is_array($featured)) {
            $expectedWarnings->push('trusted_unavailable_featured_media');
            $expectedNormalizationCounts['trusted_unavailable_featured_media'] = 1;
        }

        if ($section === 'nonpublic_posts'
            && $this->cleanText(data_get($payload, 'title.rendered')) === '') {
            $expectedWarnings->push(WordpressRestImporter::UNTITLED_NONPUBLIC_WARNING);
        }

        $inline = $evidence['inline'] ?? null;

        if (is_array($inline)) {
            $expectedOccurrences = collect($inline)
                ->sum(fn (mixed $entry): int => is_array($entry)
                    ? (int) ($entry['occurrences'] ?? 0)
                    : 0);
            $sourceUrls = collect($inline)
                ->map(fn (mixed $entry): ?string => is_array($entry)
                    && is_string($entry['source_url'] ?? null)
                        ? $entry['source_url']
                        : null)
                ->filter()
                ->values()
                ->all();

            if ($expectedOccurrences < 1
                || (int) ($expectedNormalizationCounts['trusted_unavailable_inline_media'] ?? 0)
                    !== $expectedOccurrences
                || $this->bodyContainsImageSource($persistedBody, $sourceUrls)) {
                $reasons[] = 'unavailable_media_evidence_mismatch';
            }
        }

        $expectedWarnings = $expectedWarnings->unique()->sort()->values()->all();
        $actualWarnings = collect($warnings)
            ->filter(fn (mixed $warning): bool => is_string($warning) && trim($warning) !== '')
            ->map(fn (string $warning): string => trim($warning))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $expectedNormalizationCounts = $this->canonicalNormalizationCounts(
            $expectedNormalizationCounts,
        );
        $actualNormalizationCounts = $this->canonicalNormalizationCounts($normalizationCounts);

        if ($expectedWarnings !== $actualWarnings
            || $expectedNormalizationCounts === null
            || $actualNormalizationCounts === null
            || $expectedNormalizationCounts !== $actualNormalizationCounts
            || (! is_array($featured) && ! is_array($inline))) {
            $reasons[] = 'unavailable_media_evidence_mismatch';
        }

        return array_values(array_unique($reasons));
    }

    /** @return array<string, int>|null */
    private function canonicalNormalizationCounts(mixed $counts): ?array
    {
        if (! is_array($counts) || array_is_list($counts)) {
            return null;
        }

        $canonical = [];

        foreach ($counts as $normalization => $count) {
            if (! is_string($normalization)
                || trim($normalization) === ''
                || ! is_int($count)
                || $count < 0) {
                return null;
            }

            $canonical[$normalization] = $count;
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    /** @param array<int, string> $sourceUrls */
    private function bodyContainsImageSource(string $html, array $sourceUrls): bool
    {
        if ($sourceUrls === [] || stripos($html, '<img') === false) {
            return false;
        }

        $sources = array_fill_keys(array_map(
            fn (string $sourceUrl): string => html_entity_decode(
                trim($sourceUrl),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ),
            $sourceUrls,
        ), true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            if (! $document->loadHTML(
                '<?xml encoding="UTF-8"><div>'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            )) {
                return true;
            }

            foreach ($document->getElementsByTagName('img') as $image) {
                if ($image instanceof DOMElement
                    && isset($sources[trim($image->getAttribute('src'))])) {
                    return true;
                }
            }

            return false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    /** @param array<string, mixed> $payload */
    private function isRetryableMediaWarning(mixed $warnings, array $payload = []): bool
    {
        if (! is_array($warnings)
            || ! in_array('manual_review', $warnings, true)
            || array_intersect(self::RETRYABLE_MEDIA_WARNINGS, $warnings) === []) {
            return false;
        }

        $ignoredWarnings = [
            'manual_review',
            ...self::INFORMATIONAL_NORMALIZATION_WARNINGS,
        ];

        if ($this->isSupersededByValidatedBlobDecision($warnings, $payload)) {
            $ignoredWarnings[] = 'unsafe_html_removed';
        }

        $specificWarnings = array_values(array_diff(
            $warnings,
            $ignoredWarnings,
        ));

        return $specificWarnings !== []
            && array_diff($specificWarnings, self::RETRYABLE_MEDIA_WARNINGS) === [];
    }

    /**
     * A persisted public import may predate the private frozen decision for the
     * one versioned blob cleanup. That stale unsafe warning is superseded only
     * after bundle validation has proved the exact decision and a fresh preview
     * proves the trusted normalization is complete and nonblocking.
     *
     * @param  array<string, mixed>  $payload
     */
    private function isSupersededByValidatedBlobDecision(mixed $warnings, array $payload): bool
    {
        if (! is_array($warnings)
            || ! in_array('manual_review', $warnings, true)
            || ! in_array('unsafe_html_removed', $warnings, true)
            || ! is_array(data_get($payload, '_razbudise.blob_image_removal'))) {
            return false;
        }

        $specificWarnings = array_values(array_diff(
            $warnings,
            [
                'manual_review',
                'unsafe_html_removed',
                ...self::INFORMATIONAL_NORMALIZATION_WARNINGS,
            ],
        ));

        if (array_diff($specificWarnings, self::RETRYABLE_MEDIA_WARNINGS) !== []) {
            return false;
        }

        $unavailableInline = data_get($payload, '_razbudise.unavailable_media.inline');
        $preview = $this->contentSanitizer->preview(
            data_get($payload, 'content.rendered'),
            true,
            is_array($unavailableInline) ? $unavailableInline : [],
        );
        $previewWarnings = is_array($preview['warnings'] ?? null) ? $preview['warnings'] : [];
        $expectedBlobImageCount = (int) data_get(
            $payload,
            '_razbudise.blob_image_removal.expected_blob_image_count',
            0,
        );

        return in_array('trusted_blob_image_removed', $previewWarnings, true)
            && ! in_array('manual_review', $previewWarnings, true)
            && array_intersect(self::MANUAL_REVIEW_WARNINGS, $previewWarnings) === []
            && $expectedBlobImageCount > 0
            && (int) data_get(
                $preview,
                'normalization_counts.trusted_blob_image_removed',
                0,
            ) === $expectedBlobImageCount;
    }

    /** @return array<int, int> */
    private function duplicateLocalIds(string $modelClass, string $column): array
    {
        return $modelClass::query()
            ->select($column)
            ->whereNotNull($column)
            ->groupBy($column)
            ->havingRaw('COUNT(*) > 1')
            ->pluck($column)
            ->map(fn (mixed $id): int => (int) $id)
            ->sort()->values()->all();
    }

    /**
     * @param  array<int, bool>  $sourceIds
     * @return array<int, int>
     */
    private function extraRecordIds(string $type, array $sourceIds): array
    {
        return WordpressImportRecord::query()
            ->where('wordpress_type', $type)
            ->pluck('wordpress_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => ! isset($sourceIds[$id]))
            ->unique()->sort()->values()->all();
    }

    /**
     * @param  array<int, bool>  $sourceIds
     * @return array<int, int>
     */
    private function duplicateMappingWordpressIds(string $type, string $targetClass, array $sourceIds): array
    {
        $duplicateTargetIds = WordpressImportRecord::query()
            ->select('importable_id')
            ->where('wordpress_type', $type)
            ->where('status', 'imported')
            ->where('importable_type', $targetClass)
            ->whereIn('wordpress_id', array_keys($sourceIds))
            ->whereNotNull('importable_id')
            ->groupBy('importable_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('importable_id');

        if ($duplicateTargetIds->isEmpty()) {
            return [];
        }

        return WordpressImportRecord::query()
            ->where('wordpress_type', $type)
            ->whereIn('wordpress_id', array_keys($sourceIds))
            ->whereIn('importable_id', $duplicateTargetIds)
            ->pluck('wordpress_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function expectedArticleSlug(array $payload, int $wordpressId): string
    {
        $title = $this->cleanText(data_get($payload, 'title.rendered'));
        $slug = Str::slug(rawurldecode(trim((string) ($payload['slug'] ?? ''))))
            ?: Str::slug($title)
            ?: "wordpress-{$wordpressId}";
        $conflicts = Article::query()
            ->where('slug', $slug)
            ->where(function ($query) use ($wordpressId): void {
                $query
                    ->where('old_wordpress_id', '!=', $wordpressId)
                    ->orWhereNull('old_wordpress_id');
            })
            ->exists();

        return $conflicts ? "{$slug}-wp-{$wordpressId}" : $slug;
    }

    /**
     * @param  array<string, WordpressImportRecord>  $mappingRecords
     */
    private function mappedTargetId(
        array $mappingRecords,
        string $type,
        int $wordpressId,
        string $expectedClass,
    ): ?int {
        $mapping = $mappingRecords[$type.':'.$wordpressId] ?? null;

        if (! $mapping instanceof WordpressImportRecord
            || $mapping->status !== 'imported'
            || $mapping->importable_type !== $expectedClass
            || (int) $mapping->importable_id < 1) {
            return null;
        }

        return (int) $mapping->importable_id;
    }

    /**
     * @param  array<string, WordpressImportRecord>  $mappingRecords
     * @param  array<int, int>  $wordpressIds
     * @return array<int, int>|null
     */
    private function mappedTargetIds(
        array $mappingRecords,
        string $type,
        array $wordpressIds,
        string $expectedClass,
    ): ?array {
        $targetIds = [];

        foreach ($wordpressIds as $wordpressId) {
            $targetId = $this->mappedTargetId($mappingRecords, $type, $wordpressId, $expectedClass);

            if ($targetId === null) {
                return null;
            }

            $targetIds[] = $targetId;
        }

        return $this->sortedUniqueIds($targetIds);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, int>
     */
    private function embeddedTermIds(array $payload, string $taxonomy): array
    {
        return collect(data_get($payload, '_embedded.wp:term', []))
            ->flatten(1)
            ->filter(fn (mixed $term): bool => is_array($term) && ($term['taxonomy'] ?? null) === $taxonomy)
            ->map(fn (array $term): int => (int) ($term['id'] ?? 0))
            ->filter()
            ->values()
            ->all();
    }

    private function sourcePayloadHash(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        if (is_array($payload['_razbudise'] ?? null)) {
            unset(
                $payload['_razbudise']['normalization_counts'],
                $payload['_razbudise']['content_pipeline'],
                $payload['_razbudise']['frozen_content_decision_provenance'],
                $payload['_razbudise']['frozen_materialization_provenance'],
                $payload['_razbudise']['frozen_reconciliation_provenance'],
            );

            if ($payload['_razbudise'] === []) {
                unset($payload['_razbudise']);
            }
        }

        try {
            return hash('sha256', json_encode(
                $this->sortRecursively($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Add manifest-bound server-side provenance only after the frozen bundle
     * and any exceptional evidence have passed reconciliation validation.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    private function withFrozenReconciliationProvenance(array $payload, array $bundle): array
    {
        $hasDecisionEvidence = is_array(data_get($payload, '_razbudise.blob_image_removal'))
            || is_array(data_get($payload, '_razbudise.featured_image_fallback'));

        if ($hasDecisionEvidence) {
            $decisionSha256 = data_get($bundle, 'metadata.content_migration_decisions.sha256');

            if (! is_string($decisionSha256)
                || preg_match('/^[a-f0-9]{64}$/', $decisionSha256) !== 1) {
                throw new RuntimeException('content_migration_decision_provenance_invalid');
            }

            $payload = $this->contentDecisionProvenance->add(
                $payload,
                $bundle['sha256'],
                $decisionSha256,
            );
        }

        return $this->contentDecisionProvenance->addReconciliationPayload(
            $payload,
            $bundle['sha256'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function effectiveFeaturedMedia(array $payload): ?array
    {
        $featuredMediaId = (int) ($payload['featured_media'] ?? 0);
        $media = $featuredMediaId > 0
            ? data_get($payload, '_embedded.wp:featuredmedia.0')
            : data_get($payload, '_razbudise.featured_image_fallback.media');

        return is_array($media) ? $media : null;
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }

    private function cleanText(mixed $value): string
    {
        return trim(html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function cleanNullableText(mixed $value): ?string
    {
        $value = $this->cleanText($value);

        return $value === '' ? null : $value;
    }

    private function normalizedDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function isSourceDate(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s',
            $value,
            new \DateTimeZone('UTC'),
        );

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d\TH:i:s') === $value;
    }

    private function isUtcTimestamp(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) === 1
            && $this->normalizedDate($value) !== null;
    }

    private function isPrivateSourceBaseUrl(mixed $url): bool
    {
        $manifestBaseUrl = $this->normalizedSourceBaseUrl($url);
        $configuredBaseUrl = $this->normalizedSourceBaseUrl(config('services.wordpress.base_url'));

        return $manifestBaseUrl !== null
            && $configuredBaseUrl !== null
            && hash_equals($configuredBaseUrl, $manifestBaseUrl);
    }

    private function isAbsoluteHttpsUrl(mixed $url): bool
    {
        $parseableUrl = $this->parseableAbsoluteUrl($url);

        return $parseableUrl !== null
            && strtolower((string) parse_url($parseableUrl, PHP_URL_SCHEME)) === 'https';
    }

    private function normalizedSourceBaseUrl(mixed $url): ?string
    {
        if (! $this->isAbsoluteHttpsUrl($url)) {
            return null;
        }

        $parts = parse_url((string) $url);

        if (! is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! is_string($parts['host'] ?? null)
            || trim((string) $parts['host']) === '') {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');

        if ($path !== ''
            && $path !== '/'
            && (! str_starts_with($path, '/')
                || str_contains($path, '//')
                || preg_match('~(?:^|/)\.\.?(/|$)~', rawurldecode($path)) === 1)) {
            return null;
        }

        $path = $path === '/' ? '' : rtrim($path, '/');

        return 'https://'.strtolower($parts['host']).$path;
    }

    private function isConfiguredSourceUrl(mixed $url, string $sourceBaseUrl): bool
    {
        if (! $this->isAbsoluteHttpsUrl($url)) {
            return false;
        }

        $parts = parse_url((string) $url);
        $baseParts = parse_url($sourceBaseUrl);

        if (! is_array($parts)
            || ! is_array($baseParts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
            || isset($parts['fragment'])
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($baseParts['host'] ?? ''))) {
            return false;
        }

        $basePath = rtrim((string) ($baseParts['path'] ?? ''), '/');
        $path = (string) ($parts['path'] ?? '');

        return $basePath === '' || $path === $basePath || str_starts_with($path, $basePath.'/');
    }

    private function isAllowedConfiguredMediaUrl(mixed $url): bool
    {
        $parseableUrl = $this->parseableAbsoluteUrl($url);

        if ($parseableUrl === null) {
            return false;
        }

        $parts = parse_url($parseableUrl);

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! is_string($parts['host'] ?? null)
            || trim((string) $parts['host']) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            return false;
        }

        $allowedHosts = [];
        $baseParts = parse_url((string) config('services.wordpress.base_url'));

        if (is_array($baseParts)
            && is_string($baseParts['host'] ?? null)
            && ! isset($baseParts['user'])
            && ! isset($baseParts['pass'])
            && ! isset($baseParts['port'])) {
            $allowedHosts[] = strtolower($baseParts['host']);
        }

        $configuredSources = (array) config('services.wordpress.media_hosts', []);

        foreach ($configuredSources as $configuredSource) {
            if (! is_string($configuredSource) || trim($configuredSource) === '') {
                continue;
            }

            $configuredSource = str_contains($configuredSource, '://')
                ? $configuredSource
                : 'https://'.$configuredSource;
            $configuredParts = parse_url($configuredSource);

            if (! is_array($configuredParts)
                || ! in_array(strtolower((string) ($configuredParts['scheme'] ?? '')), ['http', 'https'], true)
                || ! is_string($configuredParts['host'] ?? null)
                || isset($configuredParts['user'])
                || isset($configuredParts['pass'])
                || isset($configuredParts['port'])
                || ! in_array((string) ($configuredParts['path'] ?? ''), ['', '/'], true)
                || isset($configuredParts['query'])
                || isset($configuredParts['fragment'])) {
                continue;
            }

            $allowedHosts[] = strtolower($configuredParts['host']);
        }

        return in_array(strtolower($parts['host']), array_values(array_unique($allowedHosts)), true);
    }

    private function parseableAbsoluteUrl(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || $url !== trim($url)) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        $parseableUrl = preg_replace_callback(
            '/[^\x20-\x7E]+/u',
            static fn (array $match): string => rawurlencode($match[0]),
            $url,
        );

        return is_string($parseableUrl) && filter_var($parseableUrl, FILTER_VALIDATE_URL) !== false
            ? $parseableUrl
            : null;
    }

    /** @return array<int, int>|null */
    private function strictWordpressIdList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $ids = [];

        foreach ($value as $id) {
            if (! is_int($id) || $id < 1 || isset($ids[$id])) {
                return null;
            }

            $ids[$id] = true;
        }

        return array_keys($ids);
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function sortedUniqueIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids;
    }

    private function safeValidationError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '' || strlen($message) > 160 || preg_match('/[\r\n\t]/', $message) === 1) {
            return 'manifest_validation_failed';
        }

        return Str::of($message)->replaceMatches('/[^A-Za-z0-9:_-]/', '_')->toString();
    }
}
