<?php

namespace App\Services\Wordpress;

use RuntimeException;

final class WordpressFrozenContentDecisionProvenance
{
    private const string SOURCE = 'validated_reconciliation_bundle';

    private const string RECONCILIATION_SOURCE = 'validated_reconciliation_payload';

    private const string MATERIALIZATION_SOURCE = 'validated_reconciliation_materialization';

    /** @var array<int, string> */
    private const array CONTENT_PIPELINE_KEYS = [
        'version',
        'source_body_sha256',
        'preview_body_sha256',
        'persisted_body_sha256',
    ];

    /** @var array<int, string> */
    private const array MATERIALIZATION_KEYS = [
        'source',
        'post_id',
        'manifest_sha256',
        'source_payload_sha256',
        'reconciliation_signature',
        'persisted_body_sha256',
        'content_pipeline_sha256',
        'warnings',
        'normalization_counts',
        'signature',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function addReconciliationPayload(array $payload, string $manifestSha256): array
    {
        $postId = (int) ($payload['id'] ?? 0);

        if ($postId < 1 || preg_match('/^[a-f0-9]{64}$/', $manifestSha256) !== 1) {
            throw new RuntimeException('frozen_reconciliation_provenance_invalid');
        }

        $sourcePayloadSha256 = $this->sourcePayloadSha256($payload);

        $payload['_razbudise']['frozen_reconciliation_provenance'] = [
            'source' => self::RECONCILIATION_SOURCE,
            'post_id' => $postId,
            'manifest_sha256' => $manifestSha256,
            'source_payload_sha256' => $sourcePayloadSha256,
            'signature' => $this->reconciliationSignature(
                $postId,
                $manifestSha256,
                $sourcePayloadSha256,
            ),
        ];

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function hasValidReconciliationPayload(array $payload): bool
    {
        $provenance = data_get($payload, '_razbudise.frozen_reconciliation_provenance');
        $postId = (int) ($payload['id'] ?? 0);

        if (! is_array($provenance)
            || array_is_list($provenance)
            || ($provenance['source'] ?? null) !== self::RECONCILIATION_SOURCE
            || (int) ($provenance['post_id'] ?? 0) !== $postId
            || $postId < 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($provenance['manifest_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($provenance['source_payload_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($provenance['signature'] ?? '')) !== 1
            || ! hash_equals(
                $provenance['source_payload_sha256'],
                $this->sourcePayloadSha256($payload),
            )) {
            return false;
        }

        $expected = $this->reconciliationSignature(
            $postId,
            $provenance['manifest_sha256'],
            $provenance['source_payload_sha256'],
        );

        return hash_equals($expected, $provenance['signature']);
    }

    /**
     * Authenticate the server-materialized projection of a signed
     * reconciliation payload. The source signature intentionally excludes
     * derived pipeline data; this second signature binds that data to the
     * exact body, warnings, and normalization counts persisted by the
     * importer.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>  $warnings
     * @param  array<string, mixed>  $normalizationCounts
     * @return array<string, mixed>
     */
    public function addMaterialization(
        array $payload,
        string $persistedBodyHtml,
        array $warnings,
        array $normalizationCounts,
    ): array {
        if (! $this->hasValidReconciliationPayload($payload)) {
            throw new RuntimeException('frozen_materialization_reconciliation_provenance_invalid');
        }

        $reconciliation = data_get($payload, '_razbudise.frozen_reconciliation_provenance');
        $contentPipeline = $this->canonicalContentPipeline($payload);
        $canonicalWarnings = $this->canonicalWarnings($warnings);
        $canonicalNormalizationCounts = $this->canonicalNormalizationCounts($normalizationCounts);
        $payloadNormalizationCounts = $this->payloadNormalizationCounts($payload);
        $persistedBodySha256 = hash('sha256', $persistedBodyHtml);

        if (! is_array($reconciliation)
            || $contentPipeline === null
            || $canonicalWarnings === null
            || $canonicalNormalizationCounts === null
            || $payloadNormalizationCounts !== $canonicalNormalizationCounts
            || ! hash_equals($contentPipeline['persisted_body_sha256'], $persistedBodySha256)) {
            throw new RuntimeException('frozen_materialization_projection_invalid');
        }

        $materialization = [
            'source' => self::MATERIALIZATION_SOURCE,
            'post_id' => (int) $reconciliation['post_id'],
            'manifest_sha256' => $reconciliation['manifest_sha256'],
            'source_payload_sha256' => $reconciliation['source_payload_sha256'],
            'reconciliation_signature' => $reconciliation['signature'],
            'persisted_body_sha256' => $persistedBodySha256,
            'content_pipeline_sha256' => $this->canonicalSha256($contentPipeline),
            'warnings' => $canonicalWarnings,
            'normalization_counts' => $canonicalNormalizationCounts,
        ];
        $materialization['signature'] = $this->materializationSignature($materialization);
        $payload['_razbudise']['frozen_materialization_provenance'] = $materialization;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>  $warnings
     * @param  array<string, mixed>  $normalizationCounts
     */
    public function hasValidMaterialization(
        array $payload,
        string $persistedBodyHtml,
        array $warnings,
        array $normalizationCounts,
    ): bool {
        if (! $this->hasValidReconciliationPayload($payload)) {
            return false;
        }

        $reconciliation = data_get($payload, '_razbudise.frozen_reconciliation_provenance');
        $materialization = data_get($payload, '_razbudise.frozen_materialization_provenance');
        $contentPipeline = $this->canonicalContentPipeline($payload);
        $canonicalWarnings = $this->canonicalWarnings($warnings);
        $canonicalNormalizationCounts = $this->canonicalNormalizationCounts($normalizationCounts);
        $payloadNormalizationCounts = $this->payloadNormalizationCounts($payload);
        $persistedBodySha256 = hash('sha256', $persistedBodyHtml);

        if (! is_array($reconciliation)
            || ! is_array($materialization)
            || array_is_list($materialization)
            || ! $this->hasExactKeys($materialization, self::MATERIALIZATION_KEYS)
            || $contentPipeline === null
            || $canonicalWarnings === null
            || $canonicalNormalizationCounts === null
            || $payloadNormalizationCounts !== $canonicalNormalizationCounts
            || ($materialization['source'] ?? null) !== self::MATERIALIZATION_SOURCE
            || ! is_int($materialization['post_id'] ?? null)
            || $materialization['post_id'] !== (int) $reconciliation['post_id']
            || ($materialization['manifest_sha256'] ?? null) !== $reconciliation['manifest_sha256']
            || ($materialization['source_payload_sha256'] ?? null) !== $reconciliation['source_payload_sha256']
            || ($materialization['reconciliation_signature'] ?? null) !== $reconciliation['signature']
            || preg_match('/^[a-f0-9]{64}$/', (string) ($materialization['persisted_body_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($materialization['content_pipeline_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($materialization['signature'] ?? '')) !== 1
            || ! hash_equals($persistedBodySha256, $materialization['persisted_body_sha256'])
            || ! hash_equals($contentPipeline['persisted_body_sha256'], $persistedBodySha256)
            || ! hash_equals($this->canonicalSha256($contentPipeline), $materialization['content_pipeline_sha256'])
            || ($materialization['warnings'] ?? null) !== $canonicalWarnings
            || ($materialization['normalization_counts'] ?? null) !== $canonicalNormalizationCounts) {
            return false;
        }

        return hash_equals(
            $this->materializationSignature(array_diff_key($materialization, ['signature' => true])),
            $materialization['signature'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function add(array $payload, string $manifestSha256, string $decisionSha256): array
    {
        $postId = (int) ($payload['id'] ?? 0);

        if ($postId < 1
            || preg_match('/^[a-f0-9]{64}$/', $manifestSha256) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $decisionSha256) !== 1) {
            throw new RuntimeException('content_migration_decision_provenance_invalid');
        }

        $sourcePayloadSha256 = $this->sourcePayloadSha256($payload);

        $payload['_razbudise']['frozen_content_decision_provenance'] = [
            'source' => self::SOURCE,
            'post_id' => $postId,
            'manifest_sha256' => $manifestSha256,
            'content_decision_sha256' => $decisionSha256,
            'source_payload_sha256' => $sourcePayloadSha256,
            'signature' => $this->signature(
                $postId,
                $manifestSha256,
                $decisionSha256,
                $sourcePayloadSha256,
            ),
        ];

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function isValid(array $payload): bool
    {
        $provenance = data_get($payload, '_razbudise.frozen_content_decision_provenance');
        $postId = (int) ($payload['id'] ?? 0);
        $decisionHashes = collect([
            data_get($payload, '_razbudise.blob_image_removal.decision_sha256'),
            data_get($payload, '_razbudise.featured_image_fallback.decision_sha256'),
        ])->filter(fn (mixed $hash): bool => is_string($hash))->unique()->values();

        if (! is_array($provenance)
            || array_is_list($provenance)
            || ($provenance['source'] ?? null) !== self::SOURCE
            || (int) ($provenance['post_id'] ?? 0) !== $postId
            || $postId < 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($provenance['manifest_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($provenance['content_decision_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($provenance['source_payload_sha256'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($provenance['signature'] ?? '')) !== 1
            || $decisionHashes->count() !== 1
            || $decisionHashes->first() !== $provenance['content_decision_sha256']
            || ! hash_equals(
                $provenance['source_payload_sha256'],
                $this->sourcePayloadSha256($payload),
            )) {
            return false;
        }

        $expected = $this->signature(
            $postId,
            $provenance['manifest_sha256'],
            $provenance['content_decision_sha256'],
            $provenance['source_payload_sha256'],
        );

        return hash_equals($expected, $provenance['signature']);
    }

    private function signature(
        int $postId,
        string $manifestSha256,
        string $decisionSha256,
        string $sourcePayloadSha256,
    ): string {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('content_migration_decision_provenance_key_missing');
        }

        return hash_hmac('sha256', implode("\n", [
            self::SOURCE,
            (string) $postId,
            $manifestSha256,
            $decisionSha256,
            $sourcePayloadSha256,
        ]), $key);
    }

    private function reconciliationSignature(
        int $postId,
        string $manifestSha256,
        string $sourcePayloadSha256,
    ): string {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('frozen_reconciliation_provenance_key_missing');
        }

        return hash_hmac('sha256', implode("\n", [
            self::RECONCILIATION_SOURCE,
            (string) $postId,
            $manifestSha256,
            $sourcePayloadSha256,
        ]), $key);
    }

    /** @param array<string, mixed> $materialization */
    private function materializationSignature(array $materialization): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('frozen_materialization_provenance_key_missing');
        }

        return hash_hmac(
            'sha256',
            self::MATERIALIZATION_SOURCE."\n".$this->canonicalJson($materialization),
            $key,
        );
    }

    /**
     * @param  array<int, mixed>  $warnings
     * @return array<int, string>|null
     */
    private function canonicalWarnings(array $warnings): ?array
    {
        if (! array_is_list($warnings)) {
            return null;
        }

        $canonical = [];

        foreach ($warnings as $warning) {
            if (! is_string($warning) || trim($warning) === '' || trim($warning) !== $warning) {
                return null;
            }

            $canonical[$warning] = true;
        }

        $canonical = array_keys($canonical);
        sort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $normalizationCounts
     * @return array<string, int>|null
     */
    private function canonicalNormalizationCounts(array $normalizationCounts): ?array
    {
        if ($normalizationCounts !== [] && array_is_list($normalizationCounts)) {
            return null;
        }

        $canonical = [];

        foreach ($normalizationCounts as $normalization => $count) {
            if (! is_string($normalization)
                || trim($normalization) === ''
                || trim($normalization) !== $normalization
                || ! is_int($count)
                || $count < 0) {
                return null;
            }

            $canonical[$normalization] = $count;
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int>|null
     */
    private function payloadNormalizationCounts(array $payload): ?array
    {
        $counts = data_get($payload, '_razbudise.normalization_counts');

        return is_array($counts)
            ? $this->canonicalNormalizationCounts($counts)
            : null;
    }

    /** @param array<string, mixed> $payload */
    private function canonicalContentPipeline(array $payload): ?array
    {
        $pipeline = data_get($payload, '_razbudise.content_pipeline');

        if (! is_array($pipeline)
            || array_is_list($pipeline)
            || ! $this->hasExactKeys($pipeline, self::CONTENT_PIPELINE_KEYS)
            || ! is_string($pipeline['version'] ?? null)
            || trim($pipeline['version']) === ''
            || trim($pipeline['version']) !== $pipeline['version']) {
            return null;
        }

        foreach (array_slice(self::CONTENT_PIPELINE_KEYS, 1) as $hashKey) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) ($pipeline[$hashKey] ?? '')) !== 1) {
                return null;
            }
        }

        return array_combine(
            self::CONTENT_PIPELINE_KEYS,
            array_map(fn (string $key): mixed => $pipeline[$key], self::CONTENT_PIPELINE_KEYS),
        );
    }

    /** @param array<string, mixed> $value */
    private function hasExactKeys(array $value, array $keys): bool
    {
        return count($value) === count($keys)
            && array_diff(array_keys($value), $keys) === [];
    }

    private function canonicalSha256(mixed $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->sortRecursively($value),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param array<string, mixed> $payload */
    private function sourcePayloadSha256(array $payload): string
    {
        if (is_array($payload['_razbudise'] ?? null)) {
            unset(
                $payload['_razbudise']['normalization_counts'],
                $payload['_razbudise']['content_pipeline'],
                $payload['_razbudise']['frozen_content_decision_provenance'],
                $payload['_razbudise']['frozen_reconciliation_provenance'],
                $payload['_razbudise']['frozen_materialization_provenance'],
            );

            if ($payload['_razbudise'] === []) {
                unset($payload['_razbudise']);
            }
        }

        return $this->canonicalSha256($payload);
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
}
