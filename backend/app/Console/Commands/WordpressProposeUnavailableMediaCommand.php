<?php

namespace App\Console\Commands;

use App\Services\Wordpress\WordpressUnavailableMediaManifestService;
use Illuminate\Console\Command;
use Throwable;

final class WordpressProposeUnavailableMediaCommand extends Command
{
    protected $signature = 'wordpress:propose-unavailable-media
        {output : New private JSON artifact path under backend/storage/app.}
        {--reconciliation-sha256= : Required exact frozen reconciliation manifest SHA-256.}
        {--reconciliation-bundle= : Required private frozen reconciliation bundle directory.}
        {--uploads-inventory= : Required adjacent private frozen uploads inventory path.}
        {--owner= : Required approving content owner.}
        {--approved-at-utc= : Required approval timestamp in YYYY-MM-DDTHH:MM:SSZ form.}
        {--source-base-url= : Exact frozen WordPress source base URL; defaults to WORDPRESS_BASE_URL.}';

    protected $description = 'Propose a checksummed, approval-recorded unavailable-media treatment manifest.';

    public function handle(WordpressUnavailableMediaManifestService $service): int
    {
        $output = trim((string) $this->argument('output'));
        $reconciliationSha256 = trim((string) $this->option('reconciliation-sha256'));
        $reconciliationBundle = trim((string) $this->option('reconciliation-bundle'));
        $inventory = trim((string) $this->option('uploads-inventory'));
        $owner = trim((string) $this->option('owner'));
        $approvedAtUtc = trim((string) $this->option('approved-at-utc'));
        $sourceBaseUrl = $this->option('source-base-url');
        $sourceBaseUrl = is_string($sourceBaseUrl) && trim($sourceBaseUrl) !== ''
            ? trim($sourceBaseUrl)
            : trim((string) config('services.wordpress.base_url'));

        if ($output === ''
            || $reconciliationSha256 === ''
            || $reconciliationBundle === ''
            || $inventory === ''
            || $owner === ''
            || $approvedAtUtc === ''
            || $sourceBaseUrl === '') {
            $this->error('Output, reconciliation SHA-256, reconciliation bundle, uploads inventory, owner, approval UTC timestamp, and source base URL are required.');

            return self::FAILURE;
        }

        try {
            $manifest = $service->propose(
                $output,
                strtolower($reconciliationSha256),
                $sourceBaseUrl,
                $inventory,
                $owner,
                $approvedAtUtc,
                $reconciliationBundle,
            );
        } catch (Throwable $exception) {
            $this->error('Unavailable-media proposal failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Unavailable-media proposal written: '.realpath($output));
        $this->line('Manifest SHA-256: '.$manifest['sha256']);
        $this->line('Reconciliation SHA-256: '.$manifest['reconciliation_sha256']);
        $this->line('Uploads inventory SHA-256: '.$manifest['inventory']['sha256']);
        $this->line('Uploads inventory records: '.$manifest['inventory']['records']);
        $this->line('Posts: '.$manifest['totals']['posts']);
        $this->line('Featured treatments: '.$manifest['totals']['featured']);
        $this->line('Inline URL treatments: '.$manifest['totals']['inline']);
        $this->line('Inline element occurrences: '.$manifest['totals']['inline_occurrences']);
        $this->warn('Approval metadata is not a digital signature; independently record and root-anchor the manifest SHA-256 before apply.');

        return self::SUCCESS;
    }
}
