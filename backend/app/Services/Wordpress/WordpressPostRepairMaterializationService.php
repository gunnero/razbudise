<?php

namespace App\Services\Wordpress;

use App\Models\Article;
use App\Models\WordpressImportRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class WordpressPostRepairMaterializationService
{
    public function __construct(
        private readonly WordpressFrozenContentDecisionProvenance $provenance,
        private readonly WordpressRestImporter $importer,
        private readonly WordpressOldLinkRepairService $oldLinkRepair,
    ) {}

    /** @param array<string, mixed> $evidence */
    public function run(array $evidence, bool $apply = false): array
    {
        $this->validateEvidenceHeader($evidence);
        $rows = $evidence['articles'];
        $plan = [];
        $alreadyValid = 0;

        foreach ($rows as $row) {
            $wordpressId = (int) $row['wordpress_id'];
            $article = Article::query()->where('old_wordpress_id', $wordpressId)->first();
            $record = $article?->wordpressImportRecord;

            if (! $article instanceof Article || ! $record instanceof WordpressImportRecord) {
                throw new RuntimeException("{$wordpressId}:article_or_record_missing");
            }

            $payload = is_array($record->source_payload) ? $record->source_payload : [];
            $warnings = is_array($record->warnings) ? $record->warnings : [];
            $counts = data_get($payload, '_razbudise.normalization_counts', []);
            $counts = is_array($counts) ? $counts : [];
            $currentHash = hash('sha256', (string) $article->body_html);

            if ($record->wordpress_type !== 'post'
                || $record->status !== 'imported'
                || (int) $record->wordpress_id !== $wordpressId
                || (int) ($payload['id'] ?? 0) !== $wordpressId
                || ! $this->provenance->hasValidReconciliationPayload($payload)) {
                throw new RuntimeException("{$wordpressId}:reconciliation_payload_invalid");
            }

            $this->validatePolicyEvidence($payload, $evidence, $wordpressId);

            if (! hash_equals($row['after_body_sha256'], $currentHash)) {
                throw new RuntimeException("{$wordpressId}:repaired_body_hash_mismatch");
            }

            if ($this->oldLinkRepair->hasLocalUploadReferences($article)) {
                throw new RuntimeException("{$wordpressId}:local_wordpress_upload_reference_remaining");
            }

            if ($this->provenance->hasValidMaterialization($payload, (string) $article->body_html, $warnings, $counts)) {
                $alreadyValid++;

                continue;
            }

            if (! hash_equals(
                $row['before_body_sha256'],
                (string) data_get($payload, '_razbudise.frozen_materialization_provenance.persisted_body_sha256'),
            )) {
                throw new RuntimeException("{$wordpressId}:authorized_before_hash_mismatch");
            }

            $plan[] = compact('article', 'record', 'payload', 'warnings', 'counts', 'wordpressId');
        }

        if ($apply && $plan !== []) {
            DB::transaction(function () use ($plan): void {
                foreach ($plan as $item) {
                    $article = Article::query()->lockForUpdate()->findOrFail($item['article']->id);
                    $record = WordpressImportRecord::query()->lockForUpdate()->findOrFail($item['record']->id);

                    if ((string) $article->body_html !== (string) $item['article']->body_html
                        || $record->source_payload !== $item['payload']
                        || $record->warnings !== $item['warnings']) {
                        throw new RuntimeException("{$item['wordpressId']}:concurrent_change_detected");
                    }

                    $payload = $item['payload'];
                    $payload['_razbudise']['content_pipeline']['persisted_body_sha256'] = hash(
                        'sha256',
                        (string) $article->body_html,
                    );
                    $payload = $this->provenance->addMaterialization(
                        $payload,
                        (string) $article->body_html,
                        $item['warnings'],
                        $item['counts'],
                    );
                    $record->forceFill(['source_payload' => $payload])->save();
                }
            });
        }

        return [
            'valid' => true,
            'mode' => $apply ? 'apply' : 'dry_run',
            'evidence' => [
                'repair_chain_sha256' => $evidence['repair_chain_sha256'],
                'reconciliation_sha256' => $evidence['reconciliation_sha256'],
                'unavailable_media_sha256' => $evidence['unavailable_media_sha256'],
            ],
            'articles_authorized' => count($rows),
            'writes_planned' => count($plan),
            'writes_applied' => $apply ? count($plan) : 0,
            'already_valid' => $alreadyValid,
            'body_writes' => 0,
        ];
    }

    /** @param array<string, mixed> $evidence */
    private function validateEvidenceHeader(array $evidence): void
    {
        if (($evidence['schema'] ?? null) !== 'razbudise.wordpress-old-link-repair.v1'
            || ! $this->sha($evidence['repair_chain_sha256'] ?? null)
            || ! $this->sha($evidence['reconciliation_sha256'] ?? null)
            || ! $this->sha($evidence['unavailable_media_sha256'] ?? null)
            || ! is_array($evidence['articles'] ?? null)
            || ! array_is_list($evidence['articles'])
            || $evidence['articles'] === []) {
            throw new RuntimeException('repair_evidence_invalid');
        }

        $ids = [];
        foreach ($evidence['articles'] as $row) {
            if (! is_array($row)
                || array_keys($row) !== ['wordpress_id', 'before_body_sha256', 'after_body_sha256']
                || ! is_int($row['wordpress_id'])
                || $row['wordpress_id'] < 1
                || isset($ids[$row['wordpress_id']])
                || ! $this->sha($row['before_body_sha256'])
                || ! $this->sha($row['after_body_sha256'])) {
                throw new RuntimeException('repair_article_evidence_invalid');
            }
            $ids[$row['wordpress_id']] = true;
        }
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $evidence */
    private function validatePolicyEvidence(array $payload, array $evidence, int $wordpressId): void
    {
        if (! hash_equals(
            $evidence['reconciliation_sha256'],
            (string) data_get($payload, '_razbudise.frozen_reconciliation_provenance.manifest_sha256'),
        )) {
            throw new RuntimeException("{$wordpressId}:reconciliation_manifest_mismatch");
        }

        if (is_array(data_get($payload, '_razbudise.unavailable_media'))
            && (! hash_equals(
                $evidence['unavailable_media_sha256'],
                (string) data_get($payload, '_razbudise.unavailable_media.manifest_sha256'),
            ) || ! is_array($this->importer->trustedUnavailableMediaEvidence($payload)))) {
            throw new RuntimeException("{$wordpressId}:unavailable_media_policy_invalid");
        }

        if ((is_array(data_get($payload, '_razbudise.blob_image_removal'))
                || is_array(data_get($payload, '_razbudise.featured_image_fallback')))
            && ! $this->provenance->isValid($payload)) {
            throw new RuntimeException("{$wordpressId}:content_decision_invalid");
        }
    }

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }
}
