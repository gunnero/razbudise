<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\WordpressImportRecord;
use App\Services\Wordpress\WordpressFrozenContentDecisionProvenance;
use App\Services\Wordpress\WordpressPostRepairMaterializationService;
use App\Services\Wordpress\WordpressRestImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WordpressPostRepairMaterializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_preserves_repaired_body_and_produces_valid_materialization(): void
    {
        [$article, $record, $evidence] = $this->fixture();
        $before = $article->body_html;

        $result = app(WordpressPostRepairMaterializationService::class)->run($evidence, apply: true);

        $this->assertSame(1, $result['writes_applied']);
        $this->assertSame(0, $result['body_writes']);
        $this->assertSame($before, $article->fresh()->body_html);
        $payload = $record->fresh()->source_payload;
        $this->assertTrue(app(WordpressFrozenContentDecisionProvenance::class)->hasValidMaterialization(
            $payload,
            $before,
            $record->fresh()->warnings,
            data_get($payload, '_razbudise.normalization_counts'),
        ));

        $second = app(WordpressPostRepairMaterializationService::class)->run($evidence, apply: true);
        $this->assertSame(0, $second['writes_planned']);
        $this->assertSame(0, $second['writes_applied']);
        $this->assertSame(1, $second['already_valid']);
    }

    public function test_tampered_reconciliation_payload_is_rejected(): void
    {
        [, $record, $evidence] = $this->fixture();
        $payload = $record->source_payload;
        data_set($payload, 'title.rendered', 'tampered');
        $record->forceFill(['source_payload' => $payload])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reconciliation_payload_invalid');
        app(WordpressPostRepairMaterializationService::class)->run($evidence, apply: true);
    }

    public function test_missing_or_invalid_repair_evidence_is_rejected(): void
    {
        [, , $evidence] = $this->fixture();
        unset($evidence['repair_chain_sha256']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('repair_evidence_invalid');
        app(WordpressPostRepairMaterializationService::class)->run($evidence);
    }

    public function test_repaired_body_hash_mismatch_is_rejected(): void
    {
        [, , $evidence] = $this->fixture();
        $evidence['articles'][0]['after_body_sha256'] = str_repeat('f', 64);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('repaired_body_hash_mismatch');
        app(WordpressPostRepairMaterializationService::class)->run($evidence, apply: true);
    }

    public function test_remaining_local_wordpress_upload_reference_is_rejected(): void
    {
        [$article, , $evidence] = $this->fixture(
            '<p><img src="https://old.example.test/wp-content/uploads/local.jpg"></p>',
        );
        $evidence['articles'][0]['after_body_sha256'] = hash('sha256', $article->body_html);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('local_wordpress_upload_reference_remaining');
        app(WordpressPostRepairMaterializationService::class)->run($evidence, apply: true);
    }

    /** @return array{Article, WordpressImportRecord, array<string, mixed>} */
    private function fixture(string $repairedBody = '<p>Repaired local media reference.</p>'): array
    {
        $oldBody = '<p><img src="https://old.example.test/wp-content/uploads/old.jpg"></p>';
        $article = Article::factory()->published()->create([
            'old_wordpress_id' => 501,
            'body_html' => $repairedBody,
        ]);
        $warnings = [];
        $counts = [];
        $payload = [
            'id' => 501,
            'title' => ['rendered' => 'Post'],
            '_razbudise' => [
                'normalization_counts' => $counts,
                'content_pipeline' => [
                    'version' => WordpressRestImporter::CONTENT_PIPELINE_VERSION,
                    'source_body_sha256' => hash('sha256', $oldBody),
                    'preview_body_sha256' => hash('sha256', $oldBody),
                    'persisted_body_sha256' => hash('sha256', $oldBody),
                ],
            ],
        ];
        $provenance = app(WordpressFrozenContentDecisionProvenance::class);
        $payload = $provenance->addReconciliationPayload($payload, str_repeat('a', 64));
        $payload = $provenance->addMaterialization($payload, $oldBody, $warnings, $counts);
        $record = WordpressImportRecord::query()->create([
            'wordpress_id' => 501,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'old_url' => 'https://old.example.test/post/',
            'status' => 'imported',
            'source_payload' => $payload,
            'warnings' => $warnings,
        ]);
        $evidence = [
            'schema' => 'razbudise.wordpress-old-link-repair.v1',
            'repair_chain_sha256' => str_repeat('c', 64),
            'reconciliation_sha256' => str_repeat('a', 64),
            'unavailable_media_sha256' => str_repeat('b', 64),
            'articles' => [[
                'wordpress_id' => 501,
                'before_body_sha256' => hash('sha256', $oldBody),
                'after_body_sha256' => hash('sha256', $repairedBody),
            ]],
        ];

        return [$article, $record, $evidence];
    }
}
