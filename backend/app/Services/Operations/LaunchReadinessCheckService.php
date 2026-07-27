<?php

namespace App\Services\Operations;

use App\Models\SiteSetting;
use App\Models\WordpressImportRecord;
use App\Services\Ads\AdReportService;
use App\Services\Homepage\HomepageSectionQaService;
use App\Services\Wordpress\WordpressMigrationReadinessService;

class LaunchReadinessCheckService
{
    public function __construct(
        private readonly WordpressMigrationReadinessService $migrationReadiness,
        private readonly HomepageSectionQaService $homepageQa,
        private readonly AdReportService $adReport,
    ) {}

    /**
     * @return array{ready: bool, checks: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public function summary(): array
    {
        $checks = [];

        if (config('queue.default') === 'sync') {
            $checks[] = $this->fail(
                'queue_not_async',
                'Queue driver is sync; use database or redis before launch so imports and publishing jobs can run asynchronously.',
            );
        }

        $missingSettings = collect([
            'site_name',
            'site_url',
            'default_meta_title',
            'default_meta_description',
        ])
            ->reject(fn (string $key): bool => $this->hasPublicSetting($key))
            ->values()
            ->all();

        if ($missingSettings !== []) {
            $checks[] = $this->fail(
                'missing_site_settings',
                'Required public site settings are missing: '.implode(', ', $missingSettings).'.',
                ['keys' => $missingSettings],
            );
        }

        $failedImports = WordpressImportRecord::query()
            ->where('wordpress_type', 'post')
            ->where('status', 'failed')
            ->count();

        if ($failedImports > 0) {
            $checks[] = $this->fail(
                'failed_wordpress_imports',
                "{$failedImports} WordPress post imports are still failed.",
                ['count' => $failedImports],
            );
        }

        $migration = $this->migrationReadiness->summary();

        if (! $migration['ready']) {
            $checks[] = $this->fail(
                'wordpress_migration_readiness',
                'WordPress migration readiness has launch blockers.',
                ['checks' => collect($migration['checks'])->where('status', 'fail')->values()->all()],
            );
        } elseif (collect($migration['checks'])->contains(fn (array $check): bool => $check['status'] === 'warn')) {
            $checks[] = $this->warn(
                'wordpress_migration_warnings',
                'WordPress migration readiness passed with cleanup warnings.',
                ['checks' => collect($migration['checks'])->where('status', 'warn')->values()->all()],
            );
        } else {
            $checks[] = $this->pass('wordpress_migration_readiness', 'WordPress migration readiness checks passed.');
        }

        $homepage = $this->homepageQa->summary();

        if ((int) $homepage['totals']['issues'] > 0) {
            $checks[] = $this->fail(
                'homepage_qa',
                "{$homepage['totals']['issues']} active homepage configuration issues block launch.",
                [
                    ...$homepage['totals'],
                    'issues' => $homepage['issues'],
                ],
            );
        } else {
            $checks[] = $this->pass('homepage_qa', 'Homepage section QA checks passed.');
        }

        $adZoneCoverage = $this->adReport->requiredZoneCoverage();
        $missingAdZones = $adZoneCoverage['missing_zone_keys'];

        if ($adZoneCoverage['blocking_count'] > 0) {
            $checks[] = $this->fail(
                'required_ad_zone_delivery',
                "{$adZoneCoverage['blocking_count']} required direct-ad zones are missing or cannot deliver a campaign/fallback.",
                $adZoneCoverage,
            );
        } else {
            $checks[] = $this->pass('required_ad_zone_delivery', 'All required direct-ad zones have launch delivery coverage.');
        }

        if ($checks === []) {
            $checks[] = [
                'code' => 'launch_ready',
                'status' => 'pass',
                'message' => 'Minimum launch checks passed.',
            ];
        }

        return [
            'ready' => collect($checks)->doesntContain(fn (array $check): bool => $check['status'] === 'fail'),
            'checks' => $checks,
            'totals' => [
                'migration' => $migration['totals'],
                'homepage' => $homepage['totals'],
                'missing_ad_zones' => count($missingAdZones),
                'undeliverable_ad_zones' => count($adZoneCoverage['undeliverable_zones']),
            ],
        ];
    }

    private function hasPublicSetting(string $key): bool
    {
        return SiteSetting::query()
            ->where('key', $key)
            ->where('is_public', true)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function fail(string $code, string $message, array $context = []): array
    {
        return [
            'code' => $code,
            'status' => 'fail',
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function warn(string $code, string $message, array $context = []): array
    {
        return [
            'code' => $code,
            'status' => 'warn',
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function pass(string $code, string $message, array $context = []): array
    {
        return [
            'code' => $code,
            'status' => 'pass',
            'message' => $message,
            'context' => $context,
        ];
    }
}
