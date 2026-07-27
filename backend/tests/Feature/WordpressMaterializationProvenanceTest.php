<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\WordpressImportRecord;
use App\Services\Wordpress\WordpressFrozenContentDecisionProvenance;
use App\Services\Wordpress\WordpressRestImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordpressMaterializationProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_materialization_authenticates_the_canonical_persisted_projection(): void
    {
        $provenance = app(WordpressFrozenContentDecisionProvenance::class);
        $bodyHtml = '<p>Materialized body.</p>';
        $payload = $this->payloadReadyForMaterialization(7101, $bodyHtml);
        $warnings = ['trusted_unavailable_featured_media', 'trusted_unavailable_inline_media'];
        $normalizationCounts = [
            'trusted_unavailable_featured_media' => 1,
            'trusted_unavailable_inline_media' => 2,
        ];

        $payload = $provenance->addMaterialization(
            $payload,
            $bodyHtml,
            array_reverse($warnings),
            array_reverse($normalizationCounts, preserve_keys: true),
        );

        $this->assertTrue($provenance->hasValidReconciliationPayload($payload));
        $this->assertTrue($provenance->hasValidMaterialization(
            $payload,
            $bodyHtml,
            $warnings,
            $normalizationCounts,
        ));
        $this->assertSame($warnings, data_get(
            $payload,
            '_razbudise.frozen_materialization_provenance.warnings',
        ));
        $this->assertSame($normalizationCounts, data_get(
            $payload,
            '_razbudise.frozen_materialization_provenance.normalization_counts',
        ));
        $this->assertSame(
            data_get($payload, '_razbudise.frozen_reconciliation_provenance.signature'),
            data_get($payload, '_razbudise.frozen_materialization_provenance.reconciliation_signature'),
        );
    }

    public function test_materialization_rejects_body_warning_count_pipeline_source_and_marker_tampering(): void
    {
        $provenance = app(WordpressFrozenContentDecisionProvenance::class);
        $bodyHtml = '<p>Materialized body.</p>';
        $warnings = ['trusted_unavailable_inline_media'];
        $normalizationCounts = [
            'trusted_unavailable_featured_media' => 1,
            'trusted_unavailable_inline_media' => 2,
        ];
        $payload = $provenance->addMaterialization(
            $this->payloadReadyForMaterialization(7102, $bodyHtml),
            $bodyHtml,
            $warnings,
            $normalizationCounts,
        );

        $this->assertFalse($provenance->hasValidMaterialization(
            $payload,
            '<p>Tampered body.</p>',
            $warnings,
            $normalizationCounts,
        ));
        $this->assertFalse($provenance->hasValidMaterialization(
            $payload,
            $bodyHtml,
            [...$warnings, 'manual_review'],
            $normalizationCounts,
        ));
        $this->assertFalse($provenance->hasValidMaterialization(
            $payload,
            $bodyHtml,
            $warnings,
            [
                'trusted_unavailable_featured_media' => 1,
                'trusted_unavailable_inline_media' => 1,
            ],
        ));

        $pipelineTampered = $payload;
        data_set(
            $pipelineTampered,
            '_razbudise.content_pipeline.preview_body_sha256',
            str_repeat('f', 64),
        );
        $this->assertTrue($provenance->hasValidReconciliationPayload($pipelineTampered));
        $this->assertFalse($provenance->hasValidMaterialization(
            $pipelineTampered,
            $bodyHtml,
            $warnings,
            $normalizationCounts,
        ));

        $sourceTampered = $payload;
        data_set($sourceTampered, 'title.rendered', 'Tampered source title');
        $this->assertFalse($provenance->hasValidReconciliationPayload($sourceTampered));
        $this->assertFalse($provenance->hasValidMaterialization(
            $sourceTampered,
            $bodyHtml,
            $warnings,
            $normalizationCounts,
        ));

        $markerTampered = $payload;
        data_set(
            $markerTampered,
            '_razbudise.frozen_materialization_provenance.signature',
            str_repeat('0', 64),
        );
        $this->assertTrue($provenance->hasValidReconciliationPayload($markerTampered));
        $this->assertFalse($provenance->hasValidMaterialization(
            $markerTampered,
            $bodyHtml,
            $warnings,
            $normalizationCounts,
        ));
    }

    public function test_importer_persists_and_recreates_materialization_provenance_on_retry(): void
    {
        $provenance = app(WordpressFrozenContentDecisionProvenance::class);
        $post = $provenance->addReconciliationPayload(
            $this->minimalPost(7103, '<p>Original body.</p>'),
            str_repeat('a', 64),
        );

        app(WordpressRestImporter::class)->importStoredPostPayload(
            $post,
            'https://old.example.test',
        );

        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 7103)
            ->firstOrFail();
        $article = Article::query()->where('old_wordpress_id', 7103)->firstOrFail();
        $payload = $record->source_payload;
        $normalizationCounts = data_get($payload, '_razbudise.normalization_counts', []);

        $this->assertIsArray(data_get(
            $payload,
            '_razbudise.frozen_materialization_provenance',
        ));
        $this->assertTrue($provenance->hasValidMaterialization(
            $payload,
            (string) $article->body_html,
            $record->warnings,
            $normalizationCounts,
        ));

        data_set(
            $payload,
            '_razbudise.frozen_materialization_provenance.signature',
            str_repeat('0', 64),
        );
        $record->forceFill([
            'source_payload' => $payload,
            'warnings' => ['manual_review'],
        ])->save();
        $article->forceFill(['body_html' => '<script>alert(1)</script>'])->save();

        $this->assertTrue($provenance->hasValidReconciliationPayload($payload));
        $this->assertFalse($provenance->hasValidMaterialization(
            $payload,
            (string) $article->body_html,
            $record->fresh()->warnings,
            data_get($payload, '_razbudise.normalization_counts', []),
        ));

        app(WordpressRestImporter::class)->importStoredPostPayload(
            $payload,
            'https://old.example.test',
        );

        $record = $record->fresh();
        $article = $article->fresh();
        $payload = $record->source_payload;

        $this->assertSame('<p>Original body.</p>', $article->body_html);
        $this->assertSame([], $record->warnings);
        $this->assertTrue($provenance->hasValidMaterialization(
            $payload,
            (string) $article->body_html,
            $record->warnings,
            data_get($payload, '_razbudise.normalization_counts', []),
        ));
    }

    public function test_public_rest_cannot_persist_or_promote_a_forged_materialization_marker(): void
    {
        $post = $this->minimalPost(7104, '<p>Public body.</p>');
        $post['_razbudise']['frozen_materialization_provenance'] = [
            'source' => 'validated_reconciliation_materialization',
            'post_id' => 7104,
            'signature' => str_repeat('f', 64),
        ];

        Http::fake([
            'https://old.example.test/wp-json/wp/v2/posts*' => Http::response(
                [$post],
                200,
                ['X-WP-TotalPages' => '1'],
            ),
        ]);

        app(WordpressRestImporter::class)->importPosts(
            'https://old.example.test',
            perPage: 1,
        );

        $record = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('wordpress_id', 7104)
            ->firstOrFail();

        $this->assertNull(data_get(
            $record->source_payload,
            '_razbudise.frozen_materialization_provenance',
        ));
        $this->assertNull(data_get(
            $record->source_payload,
            '_razbudise.frozen_reconciliation_provenance',
        ));
    }

    /** @return array<string, mixed> */
    private function payloadReadyForMaterialization(int $id, string $bodyHtml): array
    {
        $payload = app(WordpressFrozenContentDecisionProvenance::class)
            ->addReconciliationPayload(
                $this->minimalPost($id, $bodyHtml),
                str_repeat('a', 64),
            );
        $payload['_razbudise']['normalization_counts'] = [
            'trusted_unavailable_inline_media' => 2,
            'trusted_unavailable_featured_media' => 1,
        ];
        $payload['_razbudise']['content_pipeline'] = [
            'version' => WordpressRestImporter::CONTENT_PIPELINE_VERSION,
            'source_body_sha256' => hash('sha256', $bodyHtml),
            'preview_body_sha256' => hash('sha256', $bodyHtml),
            'persisted_body_sha256' => hash('sha256', $bodyHtml),
        ];

        return $payload;
    }

    /** @return array<string, mixed> */
    private function minimalPost(int $id, string $bodyHtml): array
    {
        return [
            'id' => $id,
            'slug' => "post-{$id}",
            'status' => 'publish',
            'date_gmt' => '2024-05-01T10:00:00',
            'modified_gmt' => '2024-05-02T11:00:00',
            'link' => "https://old.example.test/post-{$id}/",
            'title' => ['rendered' => "Post {$id}"],
            'excerpt' => ['rendered' => '<p>Excerpt.</p>'],
            'content' => ['rendered' => $bodyHtml],
            '_embedded' => [],
        ];
    }
}
