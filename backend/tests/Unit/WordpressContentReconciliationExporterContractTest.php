<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WordpressContentReconciliationExporterContractTest extends TestCase
{
    public function test_exporter_keeps_frozen_bundle_private_atomic_and_drift_checked(): void
    {
        $source = $this->exporterSource();

        $this->assertStringContainsString('is_link($destinationInput)', $source);
        $this->assertStringContainsString('(($destinationPermissions & 0777) & 0027) !== 0', $source);
        $this->assertStringContainsString('$destinationOwner !== $processOwner', $source);
        $this->assertStringContainsString("fopen(\$temporary, 'xb')", $source);
        $this->assertStringContainsString('chmod($temporary, 0600)', $source);
        $this->assertStringContainsString('rename($temporary, $final)', $source);
        $this->assertStringContainsString('chmod($final, 0640)', $source);
        $this->assertStringContainsString('chmod($destination.DIRECTORY_SEPARATOR.$fileName, 0440)', $source);
        $this->assertStringContainsString('chmod($destination, 0550)', $source);
        $this->assertStringContainsString('Refusing to overwrite frozen export file', $source);
        $this->assertStringContainsString('$sourceFingerprintBefore = $sourceFingerprint()', $source);
        $this->assertStringContainsString('$sourceFingerprintAfter = $sourceFingerprint()', $source);
        $this->assertStringContainsString('hash_equals($sourceFingerprintBefore, $sourceFingerprintAfter)', $source);
        $this->assertStringContainsString('@unlink($destination.DIRECTORY_SEPARATOR.$filename)', $source);
        $this->assertStringContainsString("'source_fingerprint_sha256' => \$sourceFingerprintAfter", $source);
        $this->assertStringContainsString('p.post_date, p.post_date_gmt, p.post_modified, p.post_modified_gmt', $source);
        $this->assertStringContainsString("\$update('post_meta'", $source);
        $this->assertStringContainsString("\$update('featured_attachment'", $source);
        $this->assertStringContainsString("\$update('featured_attachment_meta'", $source);
        $this->assertStringContainsString("\$update('fallback_featured_attachment'", $source);
        $this->assertStringContainsString("\$update('fallback_featured_attachment_meta'", $source);
        $this->assertStringContainsString("'password_protected_posts' => [", $source);
        $this->assertStringContainsString("'content_migration_decisions' => [", $source);
    }

    public function test_exporter_requires_explicit_private_post_decisions_and_never_defaults_to_draft(): void
    {
        $source = $this->exporterSource();

        $this->assertStringContainsString("getenv('RAZBUDISE_PRIVATE_POST_DISPOSITIONS_FILE')", $source);
        $this->assertStringContainsString('(($decisionPermissions & 0777) & 0137) !== 0', $source);
        $this->assertStringContainsString('array_keys($privateDispositions) !== $privateIds', $source);
        $this->assertStringContainsString("in_array(\$targetStatus, ['draft', 'archived'], true)", $source);
        $this->assertStringContainsString('$privateDispositions[$wordpressId] ?? null', $source);
        $this->assertStringNotContainsString("'private' => 'draft'", $source);
        $this->assertStringContainsString("'private_post_dispositions' => [", $source);
    }

    public function test_exporter_suppresses_only_the_elementor_derived_asset_cache_write(): void
    {
        $source = $this->exporterSource();

        $this->assertStringContainsString(
            "\$readOnlyDerivedPostMetaKeys = ['_elementor_page_assets'];",
            $source,
        );
        $this->assertStringContainsString(
            "['add_post_metadata', 'update_post_metadata', 'delete_post_metadata']",
            $source,
        );
        $this->assertStringContainsString(
            "if (in_array((string) \$metaKey, \$readOnlyDerivedPostMetaKeys, true)) {\n".
            "        return true;\n".
            "    }\n\n".
            '    return $check;',
            $source,
        );
        $this->assertStringContainsString(
            'add_filter($filter, $suppressDerivedPostMetaWrite, PHP_INT_MAX, 5);',
            $source,
        );
        $this->assertStringContainsString(
            'WORDPRESS_RECONCILIATION_DERIVED_CACHE_WRITES_SUPPRESSED=1',
            $source,
        );
        $this->assertStringContainsString("\$update('post_meta'", $source);
    }

    public function test_exporter_applies_only_the_two_versioned_content_decisions(): void
    {
        $source = $this->exporterSource();
        $decisionPath = dirname(__DIR__, 3).'/deployment/wordpress/content-migration-decisions.json';
        $decisionJson = file_get_contents($decisionPath);

        $this->assertIsString($decisionJson);
        $this->assertSame([
            'schema_version' => 1,
            'featured_image_fallbacks' => ['2782' => 2795],
            'blob_image_removals' => [
                '10145' => [
                    'raw_post_content_sha256' => '7bc2af22f1f5260e2109d9e90c8966db9c80d0ae7902b7d7e3d79ace1f282f0b',
                    'expected_blob_image_count' => 13,
                ],
            ],
        ], json_decode($decisionJson, true, 16, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString("'content-migration-decisions.json'", $source);
        $this->assertStringContainsString("'strategy' => 'explicit_inline_attachment'", $source);
        $this->assertStringContainsString("'decision_sha256' => \$contentMigrationDecisionSha256", $source);
        $this->assertStringContainsString("'featured_image_fallbacks' => \$featuredImageFallbacks", $source);
        $this->assertStringContainsString("'blob_image_removals' => \$blobImageRemovals", $source);
        $this->assertStringContainsString("'strategy' => 'remove_nonpersistent_blob_images'", $source);
        $this->assertStringContainsString('SELECT ID, post_status, post_content', $source);
        $this->assertStringContainsString("hash('sha256', (string) (\$post['post_content'] ?? ''))", $source);
        $this->assertStringContainsString("'raw_post_content_sha256' => \$blobImageRemoval['raw_post_content_sha256']", $source);
        $this->assertStringContainsString("'expected_blob_image_count' => \$blobImageCount", $source);
        $this->assertStringContainsString('post_parent', $source);
        $this->assertStringContainsString("\$expectedClass = 'wp-image-'.\$attachmentId", $source);
        $this->assertStringContainsString("\$document->getElementsByTagName('img')", $source);
    }

    private function exporterSource(): string
    {
        $path = dirname(__DIR__, 3).'/deployment/wordpress/export-content-reconciliation.php';
        $source = file_get_contents($path);

        $this->assertIsString($source);

        return $source;
    }
}
