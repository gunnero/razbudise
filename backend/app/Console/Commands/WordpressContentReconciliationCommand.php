<?php

namespace App\Console\Commands;

use App\Services\Wordpress\WordpressContentReconciliationService;
use Illuminate\Console\Command;

final class WordpressContentReconciliationCommand extends Command
{
    protected $signature = 'wordpress:reconcile-content
        {manifest : Private metadata JSON under backend/storage/app.}
        {--expected-sha256= : Required exact metadata SHA-256 for apply mode.}
        {--unavailable-media-manifest= : Optional private root-anchored unavailable-media manifest; requires its exact SHA-256.}
        {--expected-unavailable-media-sha256= : Exact SHA-256 paired with --unavailable-media-manifest.}
        {--apply : Apply the validated deterministic reconciliation plan.}
        {--json : Print the complete machine-readable report.}';

    protected $description = 'Dry-run or apply a checksummed, streaming WordPress content reconciliation bundle.';

    public function handle(WordpressContentReconciliationService $service): int
    {
        $manifest = (string) $this->argument('manifest');
        $expectedSha256 = $this->option('expected-sha256');
        $unavailableMediaManifest = $this->option('unavailable-media-manifest');
        $expectedUnavailableMediaSha256 = $this->option('expected-unavailable-media-sha256');
        $apply = (bool) $this->option('apply');
        $report = $service->reconcile(
            $manifest,
            is_string($expectedSha256) && trim($expectedSha256) !== '' ? trim($expectedSha256) : null,
            $apply,
            is_string($unavailableMediaManifest) && trim($unavailableMediaManifest) !== ''
                ? trim($unavailableMediaManifest)
                : null,
            is_string($expectedUnavailableMediaSha256) && trim($expectedUnavailableMediaSha256) !== ''
                ? trim($expectedUnavailableMediaSha256)
                : null,
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->line('WordPress content reconciliation: '.($report['valid'] ? 'manifest valid' : 'manifest invalid'));
            $this->line('Mode: '.$report['mode']);
            $this->line('Safe to apply: '.($report['safe_to_apply'] ? 'yes' : 'no'));
            $this->line('Reconciliation complete: '.($report['reconciliation_complete'] ? 'yes' : 'no'));
            $this->line('Launch ready: '.($report['launch_ready'] ? 'yes' : 'no'));

            if (is_array($report['manifest'] ?? null)) {
                $this->line('Manifest SHA-256: '.$report['manifest']['sha256']);
            }

            if (is_array($report['unavailable_media_manifest'] ?? null)) {
                $this->line(
                    'Unavailable-media manifest SHA-256: '
                    .$report['unavailable_media_manifest']['sha256'],
                );
            }

            if (is_array($report['plan'] ?? null)) {
                $this->line('Planned writes: '.$report['plan']['total_writes']);
            }

            if (is_array($report['content_review'] ?? null)) {
                $this->line('Published manual review: '.$report['content_review']['published']['manual_review_articles']);
                $this->line('Nonpublic manual review: '.$report['content_review']['nonpublic']['manual_review_articles']);
            }

            if ($report['validation_errors'] !== []) {
                $this->error('Validation errors: '.implode(', ', $report['validation_errors']));
            }
        }

        if (! $report['valid'] || ! $report['safe_to_apply']) {
            return self::FAILURE;
        }

        if ((int) data_get($report, 'content_review.published.manual_review_articles', 0) > 0) {
            return self::FAILURE;
        }

        $publishedImportManualReviewCount = (int) data_get(
            $report,
            $apply
                ? 'parity_after.issues.published_import_manual_review_ids.count'
                : 'parity_before.issues.published_import_manual_review_ids.count',
            0,
        );

        if ($publishedImportManualReviewCount > 0) {
            return self::FAILURE;
        }

        if ($apply && ! $report['reconciliation_complete']) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
