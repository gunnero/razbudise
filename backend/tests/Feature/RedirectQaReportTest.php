<?php

namespace Tests\Feature;

use App\Models\Redirect;
use App\Models\WordpressImportRecord;
use App\Services\Seo\RedirectQaReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectQaReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_redirect_superseded_by_equivalent_active_301_is_not_unsafe(): void
    {
        Redirect::query()->create([
            'source_url' => 'https://old.example.test/superseded/',
            'target_url' => 'https://new.example.test/current/',
            'status_code' => 302,
            'is_active' => false,
        ]);
        Redirect::query()->create([
            'source_url' => '/superseded',
            'target_url' => '/current',
            'status_code' => 301,
            'is_active' => true,
        ]);
        Redirect::query()->create([
            'source_url' => '/still-unsafe',
            'target_url' => '/other',
            'status_code' => 301,
            'is_active' => false,
        ]);

        $report = app(RedirectQaReportService::class)->summary();

        $this->assertSame(1, $report['totals']['inactive']);
        $this->assertSame('/still-unsafe', $report['inactive'][0]['source_url']);
    }

    public function test_redirect_qa_report_flags_missing_inactive_non_301_and_duplicate_targets(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 1,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/missing-old/',
            'new_url' => '/missing-new',
            'status' => 'imported',
            'warnings' => [],
        ]);

        WordpressImportRecord::query()->create([
            'wordpress_id' => 2,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/inactive/',
            'new_url' => '/inactive',
            'status' => 'imported',
            'warnings' => [],
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/inactive/',
            'target_url' => '/inactive',
            'status_code' => 301,
            'is_active' => false,
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/temp/',
            'target_url' => '/temp',
            'status_code' => 302,
            'is_active' => true,
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/duplicate-one/',
            'target_url' => '/same-target',
            'status_code' => 301,
            'is_active' => true,
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/duplicate-two/',
            'target_url' => '/same-target',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $report = app(RedirectQaReportService::class)->summary();

        $this->assertSame(1, $report['totals']['missing']);
        $this->assertSame(1, $report['totals']['inactive']);
        $this->assertSame(1, $report['totals']['non_301']);
        $this->assertSame(1, $report['totals']['duplicate_targets']);
        $this->assertSame('https://old.example.test/missing-old/', $report['missing'][0]['source_url']);
        $this->assertSame('/same-target', $report['duplicate_targets'][0]['target_url']);
    }

    public function test_redirect_qa_report_command_outputs_summary(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 3,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/missing-command-old/',
            'new_url' => '/missing-command-new',
            'status' => 'imported',
            'warnings' => [],
        ]);

        $this->artisan('redirects:qa-report')
            ->expectsOutput('Missing redirects: 1')
            ->expectsOutput('Inactive redirects: 0')
            ->expectsOutput('Non-301 redirects: 0')
            ->expectsOutput('Duplicate redirect targets: 0')
            ->assertFailed();
    }

    public function test_redirect_qa_report_exports_flat_issue_rows(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 4,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/missing-export-old/',
            'new_url' => '/missing-export-new',
            'status' => 'imported',
            'warnings' => [],
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/temp-export/',
            'target_url' => '/temp-export',
            'status_code' => 302,
            'is_active' => true,
        ]);

        $rows = app(RedirectQaReportService::class)->exportRows();

        $this->assertSame('missing', $rows[0]['issue']);
        $this->assertSame('https://old.example.test/missing-export-old/', $rows[0]['source_url']);
        $this->assertSame('non_301', $rows[1]['issue']);
        $this->assertSame(302, $rows[1]['status_code']);
    }

    public function test_redirect_qa_export_rows_include_editor_next_action_details(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 6,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/missing-detail-old/',
            'new_url' => '/missing-detail-new',
            'status' => 'imported',
            'warnings' => [],
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/inactive-detail/',
            'target_url' => '/inactive-detail',
            'status_code' => 301,
            'is_active' => false,
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/temp-detail/',
            'target_url' => '/temp-detail',
            'status_code' => 302,
            'is_active' => true,
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/duplicate-detail-one/',
            'target_url' => '/duplicate-detail-target',
            'status_code' => 301,
            'is_active' => true,
        ]);

        Redirect::query()->create([
            'source_url' => 'https://old.example.test/duplicate-detail-two/',
            'target_url' => '/duplicate-detail-target',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $rows = collect(app(RedirectQaReportService::class)->exportRows())->keyBy('issue');

        $this->assertSame(
            'Create a 301 redirect for WordPress post 6 from the old URL to the new article URL.',
            $rows['missing']['details'],
        );
        $this->assertSame(
            'Activate this redirect before launch or remove it if the old URL should not resolve.',
            $rows['inactive']['details'],
        );
        $this->assertSame(
            'Change this active redirect to HTTP 301 before launch. Current status code: 302.',
            $rows['non_301']['details'],
        );
        $this->assertSame(
            'Review 2 redirects pointing to this target; keep intentional aliases and remove accidental duplicates.',
            $rows['duplicate_target']['details'],
        );
    }

    public function test_redirect_qa_report_ignores_intentionally_skipped_self_redirects(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 5,
            'wordpress_type' => 'post',
            'old_url' => 'https://old.example.test/same-path/',
            'new_url' => '/same-path',
            'status' => 'imported',
            'warnings' => [],
        ]);

        $report = app(RedirectQaReportService::class)->summary();

        $this->assertSame(0, $report['totals']['missing']);
        $this->assertSame([], $report['missing']);
    }

    public function test_redirect_qa_report_covers_taxonomy_and_author_records_but_not_pages_or_self_redirects(): void
    {
        $records = [
            [20, 'category', 'https://razbudise.mk/kategorija/kultura/', '/category/kultura'],
            [21, 'tag', 'https://razbudise.mk/tag/stara-oznaka/', '/tag/nova-oznaka'],
            [22, 'author', 'https://razbudise.mk/author/elena/', '/profil/elena'],
            [23, 'page', 'https://razbudise.mk/za-nas/', '/nova-za-nas'],
            [24, 'tag', 'https://razbudise.mk/tag/%D0%BD%D0%B0%D1%81%D1%82%D0%B0%D0%BD%D0%B8/', '/tag/настани'],
        ];

        foreach ($records as [$wordpressId, $wordpressType, $oldUrl, $newUrl]) {
            WordpressImportRecord::query()->create([
                'wordpress_id' => $wordpressId,
                'wordpress_type' => $wordpressType,
                'old_url' => $oldUrl,
                'new_url' => $newUrl,
                'status' => 'imported',
                'warnings' => [],
            ]);
        }

        $report = app(RedirectQaReportService::class)->summary();

        $this->assertSame(3, $report['totals']['missing']);
        $this->assertSame(['category', 'tag', 'author'], collect($report['missing'])->pluck('wordpress_type')->all());
        $this->assertSame([20, 21, 22], collect($report['missing'])->pluck('wordpress_id')->all());
    }

    public function test_redirect_qa_report_treats_missing_author_source_url_as_a_blocker(): void
    {
        WordpressImportRecord::query()->create([
            'wordpress_id' => 25,
            'wordpress_type' => 'author',
            'old_url' => null,
            'new_url' => '/profil/elena',
            'status' => 'imported',
            'source_payload' => ['id' => 25, 'slug' => 'elena'],
            'warnings' => [],
        ]);

        $report = app(RedirectQaReportService::class)->summary();
        $row = app(RedirectQaReportService::class)->exportRows()[0];

        $this->assertSame(1, $report['totals']['missing']);
        $this->assertNull($report['missing'][0]['source_url']);
        $this->assertSame('missing_source_url', $report['missing'][0]['reason']);
        $this->assertSame('missing_source_url', $row['issue']);
        $this->assertStringContainsString('from WordPress source evidence', $row['details']);
    }
}
