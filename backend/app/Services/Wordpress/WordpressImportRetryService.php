<?php

namespace App\Services\Wordpress;

use App\Models\Article;
use App\Models\WordpressImportRecord;
use InvalidArgumentException;
use Throwable;

class WordpressImportRetryService
{
    public function __construct(private readonly WordpressRestImporter $importer) {}

    public function retry(WordpressImportRecord $record): Article
    {
        if ($record->wordpress_type !== 'post') {
            throw new InvalidArgumentException('Only WordPress post import records can be retried.');
        }

        $payload = $record->source_payload;

        if (! is_array($payload) || $payload === []) {
            throw new InvalidArgumentException('WordPress import record is missing a stored source payload.');
        }

        try {
            $hasFrozenContentDecisionProvenance = $this->importer
                ->hasFrozenContentDecisionProvenance($payload);
            $hasFrozenReconciliationProvenance = $this->importer
                ->hasFrozenReconciliationProvenance($payload);

            return $this->importer->importStoredPostPayload(
                $payload,
                trustContentDecisionEvidence: $hasFrozenContentDecisionProvenance,
                allowUntitledNonpublic: $hasFrozenReconciliationProvenance
                    && ($payload['status'] ?? null) === 'draft',
            );
        } catch (Throwable $exception) {
            $record->forceFill([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'imported_at' => null,
            ])->save();

            throw $exception;
        }
    }
}
