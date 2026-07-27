<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\WordpressImportRecord;
use App\Services\Wordpress\WordpressContentSanitizer;
use App\Services\Wordpress\WordpressUnavailableMediaManifestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WordpressUnavailableMediaManifestTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            @chmod($directory, 0700);

            foreach ((array) glob($directory.'/*') as $path) {
                @chmod($path, 0600);
                @unlink($path);
            }

            @rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_it_validates_nul_inventory_and_emits_exact_payload_bound_evidence(): void
    {
        $payload = $this->payload();
        $artifact = $this->writeManifest($payload, "2026/07/present.jpg\t100\t1720000000.0000000000\0");

        $validated = app(WordpressUnavailableMediaManifestService::class)->load(
            $artifact['path'],
            $artifact['sha256'],
            str_repeat('a', 64),
            'https://old.example.test',
            false,
        );

        $this->assertSame($artifact['sha256'], $validated['sha256']);
        $this->assertSame(1, $validated['inventory']['records']);
        $this->assertSame([
            'posts' => 1,
            'featured' => 1,
            'inline' => 1,
            'inline_occurrences' => 2,
        ], $validated['totals']);
        $this->assertSame([
            'manifest_sha256' => $artifact['sha256'],
            'featured' => [
                'attachment_id' => 700,
                'source_url' => 'https://old.example.test/wp-content/uploads/2024/05/missing-featured.jpg',
                'role' => 'featured',
                'treatment' => 'frontend_placeholder',
            ],
            'inline' => [[
                'source_url' => 'https://old.example.test/wp-content/uploads/2024/05/missing-inline.jpg',
                'occurrences' => 2,
                'role' => 'inline',
                'treatment' => 'remove_element',
            ]],
        ], app(WordpressUnavailableMediaManifestService::class)->evidenceFor($validated, $payload));
    }

    public function test_payload_hash_uses_raw_wordpress_projection_but_rejects_forged_marker_and_drift(): void
    {
        $payload = $this->payload();
        $artifact = $this->writeManifest($payload, "2026/07/present.jpg\t100\t1720000000.0000000000\n");
        $service = app(WordpressUnavailableMediaManifestService::class);
        $validated = $service->load(
            $artifact['path'],
            $artifact['sha256'],
            str_repeat('a', 64),
            'https://old.example.test',
            false,
        );

        $differentServerMetadata = $payload;
        $differentServerMetadata['_razbudise'] = [
            'blob_image_removal' => ['decision_sha256' => str_repeat('f', 64)],
            'content_pipeline' => ['version' => 'changed'],
        ];
        $this->assertNotNull($service->evidenceFor($validated, $differentServerMetadata));

        $forged = $payload;
        $forged['_razbudise']['unavailable_media'] = null;
        $this->assertNull($service->evidenceFor($validated, $forged));

        $drifted = $payload;
        $drifted['content']['rendered'] .= '<p>Changed.</p>';
        $this->assertNull($service->evidenceFor($validated, $drifted));

        $occurrenceDrift = $payload;
        $occurrenceDrift['content']['rendered'] = str_replace(
            '</p><p><img',
            '</p><p>Removed</p><p data-image="removed"><span',
            $occurrenceDrift['content']['rendered'],
        );
        $this->assertNull($service->evidenceFor($validated, $occurrenceDrift));

        $wrongOccurrenceArtifact = $this->writeManifest(
            $payload,
            "2026/07/present.jpg\t100\t1720000000.0000000000\0",
            ['inline' => [[
                'source_url' => 'https://old.example.test/wp-content/uploads/2024/05/missing-inline.jpg',
                'occurrences' => 1,
                'role' => 'inline',
                'treatment' => 'remove_element',
            ]]],
        );
        $wrongOccurrenceManifest = $service->load(
            $wrongOccurrenceArtifact['path'],
            $wrongOccurrenceArtifact['sha256'],
            str_repeat('a', 64),
            'https://old.example.test',
            false,
        );
        $this->assertNull($service->evidenceFor($wrongOccurrenceManifest, $payload));
    }

    public function test_featured_fallback_role_is_bound_to_separately_supplied_fallback_media(): void
    {
        $payload = $this->payload();
        $payload['featured_media'] = 0;
        unset($payload['_embedded']['wp:featuredmedia']);
        $payload['_razbudise']['featured_image_fallback']['media'] = [
            'id' => 701,
            'source_url' => 'https://old.example.test/wp-content/uploads/2024/05/missing-fallback.jpg',
        ];
        $artifact = $this->writeManifest($payload, '', [
            'featured' => [
                'attachment_id' => 701,
                'source_url' => 'https://old.example.test/wp-content/uploads/2024/05/missing-fallback.jpg',
                'role' => 'featured_fallback',
                'treatment' => 'frontend_placeholder',
            ],
            'inline' => null,
        ]);
        $service = app(WordpressUnavailableMediaManifestService::class);
        $validated = $service->load(
            $artifact['path'],
            $artifact['sha256'],
            str_repeat('a', 64),
            'https://old.example.test',
            false,
        );

        $this->assertSame('featured_fallback', $service->evidenceFor($validated, $payload)['featured']['role']);

        $payload['featured_media'] = 701;
        $this->assertNull($service->evidenceFor($validated, $payload));
    }

    public function test_it_rejects_inventory_presence_count_drift_query_urls_and_missing_apply_checksum(): void
    {
        $payload = $this->payload();
        $present = $this->writeManifest(
            $payload,
            "2024/05/missing-featured.jpg\t100\t1720000000.0000000000\0"
            ."2024/05/missing-inline.jpg\t100\t1720000000.0000000000\0",
        );
        $service = app(WordpressUnavailableMediaManifestService::class);

        try {
            $service->load(
                $present['path'],
                $present['sha256'],
                str_repeat('a', 64),
                'https://old.example.test',
                false,
            );
            $this->fail('An unavailable path present in the inventory was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('unavailable_media_path_present_in_inventory', $exception->getMessage());
        }

        $apply = $this->writeManifest($payload, "2026/07/present.jpg\t100\t1720000000.0000000000\0");
        chmod($apply['directory'].'/uploads.inventory', 0440);
        chmod($apply['path'], 0440);
        chmod($apply['directory'], 0550);

        try {
            $service->load(
                $apply['path'],
                null,
                str_repeat('a', 64),
                'https://old.example.test',
                true,
            );
            $this->fail('Apply without an exact manifest SHA was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('unavailable_media_expected_sha256_required_for_apply', $exception->getMessage());
        }

        $queryPayload = $this->payload();
        $queryPayload['_embedded']['wp:featuredmedia'][0]['source_url'] .= '?cache=1';
        $queryArtifact = $this->writeManifest($queryPayload, '', [
            'featured' => [
                'attachment_id' => 700,
                'source_url' => $queryPayload['_embedded']['wp:featuredmedia'][0]['source_url'],
                'role' => 'featured',
                'treatment' => 'frontend_placeholder',
            ],
            'inline' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unavailable_media_source_url_invalid');
        $service->load(
            $queryArtifact['path'],
            $queryArtifact['sha256'],
            str_repeat('a', 64),
            'https://old.example.test',
            false,
        );
    }

    public function test_proposal_command_builds_deterministic_private_artifact_from_exact_failed_roles(): void
    {
        $payload = $this->payload();
        $refreshedPayload = $payload;
        $refreshedPayload['yoast_head'] = 'volatile refreshed public REST projection';
        $article = Article::factory()->create([
            'old_wordpress_id' => 320,
            'body_html' => $payload['content']['rendered'],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 320,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'status' => 'imported',
            'source_payload' => $refreshedPayload,
            'warnings' => [
                'unsafe_html_removed',
                'inline_image_download_failed',
                'manual_review',
            ],
        ]);
        $directory = storage_path('app/unavailable-media-command-'.str_replace('.', '', uniqid('', true)));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        $this->directories[] = $directory;
        $inventory = $directory.'/wordpress-uploads-freeze.inventory';
        file_put_contents($inventory, "2026/07/present.jpg\t100\t1720000000.0000000000\0");
        chmod($inventory, 0600);
        $output = $directory.'/wordpress-unavailable-media-manifest.json';
        $bundle = $this->writeFrozenBundle($payload);

        $this->artisan('wordpress:propose-unavailable-media', [
            'output' => $output,
            '--reconciliation-sha256' => $bundle['sha256'],
            '--reconciliation-bundle' => $bundle['path'],
            '--uploads-inventory' => $inventory,
            '--owner' => 'Content Lead',
            '--approved-at-utc' => '2026-07-17T10:00:00Z',
            '--source-base-url' => 'https://old.example.test',
        ])->assertSuccessful()
            ->expectsOutputToContain('Manifest SHA-256: ')
            ->expectsOutputToContain('not a digital signature');

        $this->assertFileExists($output);
        $this->assertSame(0440, fileperms($output) & 0777);
        $manifest = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'posts' => 1,
            'featured' => 1,
            'inline' => 1,
            'inline_occurrences' => 2,
        ], $manifest['totals']);
        $this->assertSame('featured', $manifest['posts'][0]['featured']['role']);
        $this->assertSame('inline', $manifest['posts'][0]['inline'][0]['role']);
        $validated = app(WordpressUnavailableMediaManifestService::class)->load(
            $output,
            hash_file('sha256', $output),
            $bundle['sha256'],
            'https://old.example.test',
            false,
        );
        $this->assertNotNull(app(WordpressUnavailableMediaManifestService::class)->evidenceFor(
            $validated,
            $payload,
        ));
        $this->assertNull(app(WordpressUnavailableMediaManifestService::class)->evidenceFor(
            $validated,
            $refreshedPayload,
        ));
    }

    public function test_frozen_inventory_adds_absent_featured_fallback_even_when_historical_warning_is_inline_only(): void
    {
        $payload = $this->payload();
        $sharedUrl = 'https://old.example.test/wp-content/uploads/2024/05/missing-inline.jpg';
        $payload['featured_media'] = 0;
        unset($payload['_embedded']['wp:featuredmedia']);
        $payload['_razbudise']['featured_image_fallback'] = [
            'strategy' => 'explicit_inline_attachment',
            'post_id' => 320,
            'attachment_id' => 701,
            'decision_sha256' => str_repeat('b', 64),
            'media' => [
                'id' => 701,
                'source_url' => $sharedUrl,
            ],
        ];
        $article = Article::factory()->create([
            'old_wordpress_id' => 320,
            'body_html' => $payload['content']['rendered'],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 320,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'status' => 'imported',
            'source_payload' => $payload,
            'warnings' => [
                'inline_image_download_failed',
                'manual_review',
            ],
        ]);
        $directory = storage_path('app/unavailable-media-fallback-'.str_replace('.', '', uniqid('', true)));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        $this->directories[] = $directory;
        $inventory = $directory.'/wordpress-uploads-freeze.inventory';
        file_put_contents($inventory, "2026/07/present.jpg\t100\t1720000000.0000000000\0");
        chmod($inventory, 0600);
        $bundle = $this->writeFrozenBundle($payload);
        $output = $directory.'/wordpress-unavailable-media-manifest.json';

        $validated = app(WordpressUnavailableMediaManifestService::class)->propose(
            $output,
            $bundle['sha256'],
            'https://old.example.test',
            $inventory,
            'Content Lead',
            '2026-07-17T10:00:00Z',
            $bundle['path'],
        );

        $this->assertSame('featured_fallback', $validated['posts'][0]['featured']['role']);
        $this->assertSame($sharedUrl, $validated['posts'][0]['featured']['source_url']);
        $this->assertSame($sharedUrl, $validated['posts'][0]['inline'][0]['source_url']);
        $this->assertSame([
            'posts' => 1,
            'featured' => 1,
            'inline' => 1,
            'inline_occurrences' => 2,
        ], $validated['totals']);
        $this->assertNotNull(app(WordpressUnavailableMediaManifestService::class)->evidenceFor(
            $validated,
            $payload,
        ));
    }

    public function test_frozen_inventory_excludes_present_inline_path_despite_historical_retryable_warning(): void
    {
        $payload = $this->payload();
        $article = Article::factory()->create([
            'old_wordpress_id' => 320,
            'body_html' => $payload['content']['rendered'],
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => 320,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'status' => 'imported',
            'source_payload' => $payload,
            'warnings' => ['inline_image_download_failed', 'manual_review'],
        ]);
        $directory = storage_path('app/unavailable-media-present-inline-'.str_replace('.', '', uniqid('', true)));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        $this->directories[] = $directory;
        $inventory = $directory.'/wordpress-uploads-freeze.inventory';
        file_put_contents($inventory, "2024/05/missing-inline.jpg\t100\t1720000000.0000000000\0");
        chmod($inventory, 0600);
        $bundle = $this->writeFrozenBundle($payload);

        $validated = app(WordpressUnavailableMediaManifestService::class)->propose(
            $directory.'/wordpress-unavailable-media-manifest.json',
            $bundle['sha256'],
            'https://old.example.test',
            $inventory,
            'Content Lead',
            '2026-07-17T10:00:00Z',
            $bundle['path'],
        );

        $this->assertSame([
            'posts' => 1,
            'featured' => 1,
            'inline' => 0,
            'inline_occurrences' => 0,
        ], $validated['totals']);
        $this->assertArrayNotHasKey('inline', $validated['posts'][0]);
        $this->assertSame('featured', $validated['posts'][0]['featured']['role']);
    }

    public function test_frozen_inventory_recovers_absent_inline_evidence_after_prior_policy_cleared_warning_and_body(): void
    {
        $payload = $this->payload();
        $staleSourcePayload = $payload;
        $staleSourcePayload['_razbudise']['unavailable_media'] = [
            'manifest_sha256' => str_repeat('f', 64),
            'inline' => [[
                'source_url' => 'https://old.example.test/wp-content/uploads/2024/05/missing-inline.jpg',
                'occurrences' => 2,
                'role' => 'inline',
                'treatment' => 'remove_element',
            ]],
        ];
        $this->createImportedPostState(
            $payload,
            '<p>The prior policy removed both image elements.</p>',
            ['trusted_unavailable_inline_media'],
            $staleSourcePayload,
        );
        $proposal = $this->prepareFrozenProposal(
            $payload,
            "2024/05/missing-featured.jpg\t100\t1720000000.0000000000\0",
        );

        $validated = $this->proposeFrozen($proposal);

        $this->assertSame([
            'posts' => 1,
            'featured' => 0,
            'inline' => 1,
            'inline_occurrences' => 2,
        ], $validated['totals']);
        $this->assertArrayNotHasKey('featured', $validated['posts'][0]);
        $this->assertSame([[
            'source_url' => 'https://old.example.test/wp-content/uploads/2024/05/missing-inline.jpg',
            'occurrences' => 2,
            'role' => 'inline',
            'treatment' => 'remove_element',
        ]], $validated['posts'][0]['inline']);
    }

    public function test_frozen_inventory_includes_absent_inline_without_historical_failure_warning(): void
    {
        $payload = $this->payload();
        $this->createImportedPostState(
            $payload,
            '<p>No retryable warning or source image remains in mutable state.</p>',
            [],
        );
        $proposal = $this->prepareFrozenProposal(
            $payload,
            "2024/05/missing-featured.jpg\t100\t1720000000.0000000000\0",
        );

        $validated = $this->proposeFrozen($proposal);

        $this->assertSame(1, $validated['totals']['inline']);
        $this->assertSame(2, $validated['totals']['inline_occurrences']);
        $this->assertSame(
            'https://old.example.test/wp-content/uploads/2024/05/missing-inline.jpg',
            $validated['posts'][0]['inline'][0]['source_url'],
        );
    }

    public function test_frozen_inventory_excludes_two_present_avif_paths_from_absence_policy(): void
    {
        $payload = $this->payload();
        $first = 'https://old.example.test/wp-content/uploads/2025/04/idninata-na-zgradite-e-drvena.avif';
        $second = 'https://old.example.test/wp-content/uploads/2025/04/vtor-prisuten-format.avif';
        $payload['content']['rendered'] = '<p><img src="'.$first.'"></p>'
            .'<p><img src="'.$second.'"></p>';
        $this->createImportedPostState(
            $payload,
            '<p>A stale policy removed both AVIF elements.</p>',
            ['trusted_unavailable_inline_media'],
        );
        $proposal = $this->prepareFrozenProposal(
            $payload,
            "2025/04/idninata-na-zgradite-e-drvena.avif\t100\t1720000000.0000000000\0"
                ."2025/04/vtor-prisuten-format.avif\t100\t1720000000.0000000000\0",
        );

        $validated = $this->proposeFrozen($proposal);

        $this->assertSame([
            'posts' => 1,
            'featured' => 1,
            'inline' => 0,
            'inline_occurrences' => 0,
        ], $validated['totals']);
        $this->assertArrayNotHasKey('inline', $validated['posts'][0]);
        $this->assertSame('featured', $validated['posts'][0]['featured']['role']);
    }

    public function test_frozen_inventory_rejects_canonical_collision_from_exact_source_preview(): void
    {
        $filename = 'недостапна-фотографија.jpg';
        $sourceUrl = 'https://old.example.test/wp-content/uploads/2024/05/'.$filename;
        $encodedUrl = 'https://old.example.test/wp-content/uploads/2024/05/'.rawurlencode($filename);
        $payload = $this->payload();
        $payload['content']['rendered'] = '<p><img src="'.$sourceUrl.'"></p>'
            .'<p><img src="'.$encodedUrl.'"></p>';
        $this->createImportedPostState($payload, '<p>Mutable body is irrelevant.</p>', []);
        $proposal = $this->prepareFrozenProposal(
            $payload,
            "2024/05/missing-featured.jpg\t100\t1720000000.0000000000\0",
        );

        try {
            $this->proposeFrozen($proposal);
            $this->fail('A canonical collision in the frozen source preview was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'unavailable_media_inline_source_path_ambiguous:320',
                $exception->getMessage(),
            );
        }

        $this->assertFileDoesNotExist($proposal['output']);
    }

    public function test_proposal_matches_encoded_current_url_to_exact_frozen_preview_url(): void
    {
        $filename = 'недостапна-фотографија.jpg';
        $sourceUrl = 'https://old.example.test/wp-content/uploads/2024/05/'.$filename;
        $currentUrl = 'https://old.example.test/wp-content/uploads/2024/05/'.rawurlencode($filename);
        $payload = $this->payload();
        $payload['content']['rendered'] = '<p><img src="'.$sourceUrl.'" alt="One"></p>'
            .'<p><img src="'.$sourceUrl.'" alt="Two"></p>';
        $proposal = $this->prepareInlineProposal(
            $payload,
            '<p><img src="'.$currentUrl.'" alt="One"></p>'
                .'<p><img src="'.$currentUrl.'" alt="Two"></p>',
        );
        $service = app(WordpressUnavailableMediaManifestService::class);
        $validated = $this->proposeInline($service, $proposal);
        $preview = app(WordpressContentSanitizer::class)->preview($payload['content']['rendered']);
        preg_match('/<img\\b[^>]*\\bsrc=(["\'])([^"\']+)\\1/i', (string) $preview['html'], $matches);
        $previewUrl = html_entity_decode((string) ($matches[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertSame([[
            'source_url' => $previewUrl,
            'occurrences' => 2,
            'role' => 'inline',
            'treatment' => 'remove_element',
        ]], $validated['posts'][0]['inline']);
        $this->assertSame(
            $previewUrl,
            $service->evidenceFor($validated, $payload)['inline'][0]['source_url'],
        );
        $this->assertNotSame('', $previewUrl);
    }

    public function test_proposal_uses_trusted_divi_normalization_removal_stage(): void
    {
        $sourceUrl = 'https://old.example.test/wp-content/uploads/2024/05/divi-story.jpg';
        $payload = $this->payload();
        $payload['content']['rendered'] = '[et_pb_section][et_pb_row][et_pb_column type=”4_4″]'
            .'[et_pb_image src=”'.$sourceUrl.'” title_text=”Опис″][/et_pb_image]'
            .'[et_pb_text]<p>Зачуван текст.</p>[/et_pb_text]'
            .'[/et_pb_column][/et_pb_row][/et_pb_section]';
        $proposal = $this->prepareInlineProposal(
            $payload,
            '<figure><img src="'.$sourceUrl.'" alt="Опис"><figcaption>Опис</figcaption></figure>'
                .'<p>Зачуван текст.</p>',
        );
        $service = app(WordpressUnavailableMediaManifestService::class);
        $validated = $this->proposeInline($service, $proposal);

        $this->assertSame([[
            'source_url' => $sourceUrl,
            'occurrences' => 1,
            'role' => 'inline',
            'treatment' => 'remove_element',
        ]], $validated['posts'][0]['inline']);
        $this->assertSame(
            $sourceUrl,
            $service->evidenceFor($validated, $payload)['inline'][0]['source_url'],
        );
    }

    public function test_proposal_rejects_current_url_canonical_collision(): void
    {
        $filename = 'недостапна-фотографија.jpg';
        $sourceUrl = 'https://old.example.test/wp-content/uploads/2024/05/'.$filename;
        $encodedUrl = 'https://old.example.test/wp-content/uploads/2024/05/'.rawurlencode($filename);
        $payload = $this->payload();
        $payload['content']['rendered'] = '<p><img src="'.$sourceUrl.'"></p>';

        $this->assertInlineProposalFails(
            $payload,
            '<p><img src="'.$sourceUrl.'"></p><p><img src="'.$encodedUrl.'"></p>',
            'unavailable_media_inline_current_path_ambiguous:320',
        );
    }

    public function test_proposal_rejects_frozen_source_url_canonical_collision(): void
    {
        $sourceUrl = 'https://old.example.test/wp-content/uploads/2024/05/photo.jpg';
        $encodedUrl = 'https://old.example.test/wp-content/uploads/2024/05/photo%2Ejpg';
        $payload = $this->payload();
        $payload['content']['rendered'] = '<p><img src="'.$sourceUrl.'"></p>'
            .'<p><img src="'.$encodedUrl.'"></p>';

        $this->assertInlineProposalFails(
            $payload,
            '<p><img src="'.$encodedUrl.'"></p>',
            'unavailable_media_inline_source_path_ambiguous:320',
        );
    }

    public function test_proposal_rejects_canonical_path_mismatch(): void
    {
        $sourceUrl = 'https://old.example.test/wp-content/uploads/2024/05/недостапна.jpg';
        $differentFilename = 'друга-фотографија.jpg';
        $differentUrl = 'https://old.example.test/wp-content/uploads/2024/05/'.rawurlencode($differentFilename);
        $payload = $this->payload();
        $payload['content']['rendered'] = '<p><img src="'.$sourceUrl.'"></p>';

        $this->assertInlineProposalFails(
            $payload,
            '<p><img src="'.$differentUrl.'"></p>',
            'unavailable_media_inline_source_path_mismatch:320',
        );
    }

    public function test_proposal_rejects_canonical_occurrence_count_mismatch(): void
    {
        $filename = 'недостапна-фотографија.jpg';
        $sourceUrl = 'https://old.example.test/wp-content/uploads/2024/05/'.$filename;
        $encodedUrl = 'https://old.example.test/wp-content/uploads/2024/05/'.rawurlencode($filename);
        $payload = $this->payload();
        $payload['content']['rendered'] = '<p><img src="'.$sourceUrl.'"></p>'
            .'<p><img src="'.$sourceUrl.'"></p>';

        $this->assertInlineProposalFails(
            $payload,
            '<p><img src="'.$encodedUrl.'"></p>',
            'unavailable_media_inline_occurrences_ambiguous:320',
        );
    }

    public function test_inventory_hash_and_absence_evidence_share_one_open_stream_during_path_swap(): void
    {
        $directory = storage_path('app/unavailable-media-swap-'.str_replace('.', '', uniqid('', true)));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        $this->directories[] = $directory;
        $inventoryPath = $directory.'/uploads.inventory';
        $replacementPath = $directory.'/replacement.inventory';
        $missingPath = '2024/05/missing.jpg';
        $original = $missingPath."\t100\t1720000000.0000000000\0"
            ."2026/07/control.jpg\t100\t1720000000.0000000000\0";
        $replacement = "2024/05/present.jpg\t100\t1720000000.0000000000\0"
            ."2026/07/control.jpg\t100\t1720000000.0000000000\0";
        file_put_contents($inventoryPath, $original);
        file_put_contents($replacementPath, $replacement);
        chmod($inventoryPath, 0600);
        chmod($replacementPath, 0600);
        $handle = fopen($inventoryPath, 'rb');
        $this->assertIsResource($handle);

        rename($replacementPath, $inventoryPath);

        try {
            $method = new \ReflectionMethod(
                WordpressUnavailableMediaManifestService::class,
                'scanInventoryStream',
            );
            $scan = $method->invoke(
                app(WordpressUnavailableMediaManifestService::class),
                $handle,
                [$missingPath => true],
            );
        } finally {
            fclose($handle);
        }

        $this->assertSame(hash('sha256', $original), $scan['sha256']);
        $this->assertSame(2, $scan['records']);
        $this->assertSame([$missingPath], $scan['present_paths']);
        $this->assertSame(hash('sha256', $replacement), hash_file('sha256', $inventoryPath));
        $this->assertSame(strlen($original), strlen($replacement));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{output: string, inventory: string}
     */
    private function prepareInlineProposal(array $payload, string $bodyHtml): array
    {
        $wordpressId = $payload['id'];
        $article = Article::factory()->create([
            'old_wordpress_id' => $wordpressId,
            'body_html' => $bodyHtml,
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => $wordpressId,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'status' => 'imported',
            'source_payload' => $payload,
            'warnings' => [
                'inline_image_download_failed',
                'manual_review',
            ],
        ]);
        $directory = storage_path('app/unavailable-media-inline-'.str_replace('.', '', uniqid('', true)));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        $this->directories[] = $directory;
        $inventory = $directory.'/wordpress-uploads-freeze.inventory';
        file_put_contents($inventory, "2026/07/present.jpg\t100\t1720000000.0000000000\0");
        chmod($inventory, 0600);

        return [
            'output' => $directory.'/wordpress-unavailable-media-manifest.json',
            'inventory' => $inventory,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{output: string, inventory: string, bundle: array{path: string, sha256: string}}
     */
    private function prepareFrozenProposal(array $payload, string $inventoryContents): array
    {
        $directory = storage_path('app/unavailable-media-frozen-inline-'.str_replace('.', '', uniqid('', true)));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        $this->directories[] = $directory;
        $inventory = $directory.'/wordpress-uploads-freeze.inventory';
        file_put_contents($inventory, $inventoryContents);
        chmod($inventory, 0600);

        return [
            'output' => $directory.'/wordpress-unavailable-media-manifest.json',
            'inventory' => $inventory,
            'bundle' => $this->writeFrozenBundle($payload),
        ];
    }

    /**
     * @param  array{output: string, inventory: string, bundle: array{path: string, sha256: string}}  $proposal
     * @return array<string, mixed>
     */
    private function proposeFrozen(array $proposal): array
    {
        return app(WordpressUnavailableMediaManifestService::class)->propose(
            $proposal['output'],
            $proposal['bundle']['sha256'],
            'https://old.example.test',
            $proposal['inventory'],
            'Content Lead',
            '2026-07-17T10:00:00Z',
            $proposal['bundle']['path'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $warnings
     * @param  array<string, mixed>|null  $sourcePayload
     */
    private function createImportedPostState(
        array $payload,
        string $bodyHtml,
        array $warnings,
        ?array $sourcePayload = null,
    ): void {
        $wordpressId = (int) $payload['id'];
        $article = Article::factory()->create([
            'old_wordpress_id' => $wordpressId,
            'body_html' => $bodyHtml,
        ]);
        WordpressImportRecord::query()->create([
            'wordpress_id' => $wordpressId,
            'wordpress_type' => 'post',
            'importable_type' => Article::class,
            'importable_id' => $article->id,
            'status' => 'imported',
            'source_payload' => $sourcePayload ?? $payload,
            'warnings' => $warnings,
        ]);
    }

    /**
     * @param  array{output: string, inventory: string}  $proposal
     * @return array<string, mixed>
     */
    private function proposeInline(
        WordpressUnavailableMediaManifestService $service,
        array $proposal,
    ): array {
        return $service->propose(
            $proposal['output'],
            str_repeat('a', 64),
            'https://old.example.test',
            $proposal['inventory'],
            'Content Lead',
            '2026-07-17T10:00:00Z',
        );
    }

    /** @param array<string, mixed> $payload */
    private function assertInlineProposalFails(array $payload, string $bodyHtml, string $expectedError): void
    {
        $proposal = $this->prepareInlineProposal($payload, $bodyHtml);

        try {
            $this->proposeInline(app(WordpressUnavailableMediaManifestService::class), $proposal);
            $this->fail('An ambiguous canonical inline-media mapping was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame($expectedError, $exception->getMessage());
        }

        $this->assertFileDoesNotExist($proposal['output']);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{path: string, sha256: string}
     */
    private function writeFrozenBundle(array $payload): array
    {
        $directory = storage_path('app/unavailable-media-bundle-'.str_replace('.', '', uniqid('', true)));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        $this->directories[] = $directory;
        $published = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
        file_put_contents($directory.'/published-posts.ndjson', $published);
        file_put_contents($directory.'/nonpublic-posts.ndjson', '');
        chmod($directory.'/published-posts.ndjson', 0600);
        chmod($directory.'/nonpublic-posts.ndjson', 0600);
        $metadata = [
            'files' => [
                'published_posts' => [
                    'filename' => 'published-posts.ndjson',
                    'records' => 1,
                    'sha256' => hash('sha256', $published),
                ],
                'nonpublic_posts' => [
                    'filename' => 'nonpublic-posts.ndjson',
                    'records' => 0,
                    'sha256' => hash('sha256', ''),
                ],
            ],
        ];
        $metadataJson = json_encode(
            $metadata,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
        file_put_contents($directory.'/metadata.json', $metadataJson);
        chmod($directory.'/metadata.json', 0600);

        return [
            'path' => $directory,
            'sha256' => hash('sha256', $metadataJson),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $inline = 'https://old.example.test/wp-content/uploads/2024/05/missing-inline.jpg';

        return [
            'id' => 320,
            'slug' => 'missing-media',
            'status' => 'publish',
            'featured_media' => 700,
            'content' => [
                'rendered' => '<p><img src="'.$inline.'" alt="One"></p>'
                    .'<p><img src="'.$inline.'" alt="Two"></p>',
            ],
            '_embedded' => [
                'wp:featuredmedia' => [[
                    'id' => 700,
                    'source_url' => 'https://old.example.test/wp-content/uploads/2024/05/missing-featured.jpg',
                ]],
            ],
            '_razbudise' => [
                'normalization_counts' => ['trusted_iframe_hardened' => 1],
                'content_pipeline' => ['version' => 'test'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{featured?: array<string, mixed>|null, inline?: array<int, array<string, mixed>>|null}  $evidence
     * @return array{path: string, sha256: string, directory: string}
     */
    private function writeManifest(array $payload, string $inventory, array $evidence = []): array
    {
        $directory = storage_path('app/unavailable-media-test-'.str_replace('.', '', uniqid('', true)));
        mkdir($directory, 0700, true);
        chmod($directory, 0700);
        $this->directories[] = $directory;
        $inventoryPath = $directory.'/uploads.inventory';
        file_put_contents($inventoryPath, $inventory);
        chmod($inventoryPath, 0600);
        $featured = array_key_exists('featured', $evidence)
            ? $evidence['featured']
            : [
                'attachment_id' => 700,
                'source_url' => 'https://old.example.test/wp-content/uploads/2024/05/missing-featured.jpg',
                'role' => 'featured',
                'treatment' => 'frontend_placeholder',
            ];
        $inline = array_key_exists('inline', $evidence)
            ? $evidence['inline']
            : [[
                'source_url' => 'https://old.example.test/wp-content/uploads/2024/05/missing-inline.jpg',
                'occurrences' => 2,
                'role' => 'inline',
                'treatment' => 'remove_element',
            ]];
        $post = [
            'wordpress_id' => 320,
            'source_payload_sha256' => $this->payloadSha256($payload),
        ];

        if (is_array($featured)) {
            $post['featured'] = $featured;
        }

        if (is_array($inline)) {
            $post['inline'] = $inline;
        }

        $manifest = [
            'schema_version' => 1,
            'reconciliation_sha256' => str_repeat('a', 64),
            'source_base_url' => 'https://old.example.test',
            'inventory' => [
                'filename' => 'uploads.inventory',
                'sha256' => hash('sha256', $inventory),
                'records' => $inventory === '' ? 0 : substr_count($inventory, str_contains($inventory, "\0") ? "\0" : "\n"),
            ],
            'approval' => [
                'owner' => 'Content Lead',
                'approved_at_utc' => '2026-07-17T10:00:00Z',
                'featured_treatment' => 'frontend_placeholder',
                'inline_treatment' => 'remove_element',
            ],
            'totals' => [
                'posts' => 1,
                'featured' => is_array($featured) ? 1 : 0,
                'inline' => is_array($inline) ? count($inline) : 0,
                'inline_occurrences' => is_array($inline)
                    ? array_sum(array_column($inline, 'occurrences'))
                    : 0,
            ],
            'posts' => [$post],
        ];
        $json = json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
        $path = $directory.'/manifest.json';
        file_put_contents($path, $json);
        chmod($path, 0600);

        return [
            'path' => $path,
            'sha256' => hash('sha256', $json),
            'directory' => $directory,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function payloadSha256(array $payload): string
    {
        unset($payload['_razbudise']);

        return hash('sha256', json_encode(
            $this->sortRecursively($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }
}
