<?php

namespace App\Services\Seo;

use App\Models\Redirect;
use App\Models\WordpressImportRecord;
use App\Support\UrlPath;
use Illuminate\Support\Facades\DB;

class RedirectQaReportService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $missingSourceUrls = WordpressImportRecord::query()
            ->whereIn('wordpress_type', WordpressImportRecord::REDIRECTABLE_TYPES)
            ->where('status', 'imported')
            ->whereNotNull('new_url')
            ->where(function ($query): void {
                $query->whereNull('old_url')->orWhereRaw("TRIM(old_url) = ''");
            })
            ->orderBy('wordpress_id')
            ->get(['wordpress_id', 'wordpress_type', 'old_url', 'new_url'])
            ->map(fn (WordpressImportRecord $record): array => [
                'wordpress_id' => $record->wordpress_id,
                'wordpress_type' => $record->wordpress_type,
                'source_url' => null,
                'target_url' => $record->new_url,
                'reason' => 'missing_source_url',
            ])
            ->toBase();

        $missingRedirects = WordpressImportRecord::query()
            ->whereIn('wordpress_type', WordpressImportRecord::REDIRECTABLE_TYPES)
            ->where('status', 'imported')
            ->whereNotNull('old_url')
            ->whereNotNull('new_url')
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('redirects')
                    ->whereColumn('redirects.source_url', 'wordpress_import_records.old_url');
            })
            ->orderBy('wordpress_id')
            ->get(['wordpress_id', 'wordpress_type', 'old_url', 'new_url'])
            ->filter(fn (WordpressImportRecord $record): bool => ! $this->isSelfRedirect($record->old_url, $record->new_url))
            ->map(fn (WordpressImportRecord $record): array => [
                'wordpress_id' => $record->wordpress_id,
                'wordpress_type' => $record->wordpress_type,
                'source_url' => $record->old_url,
                'target_url' => $record->new_url,
                'reason' => 'missing_redirect',
            ])
            ->toBase();
        $missing = $missingSourceUrls
            ->merge($missingRedirects)
            ->sortBy([
                ['wordpress_id', 'asc'],
                ['wordpress_type', 'asc'],
            ])
            ->values()
            ->all();

        $activePermanentRedirects = Redirect::query()
            ->where('is_active', true)
            ->where('status_code', 301)
            ->get(['source_url', 'target_url'])
            ->mapWithKeys(fn (Redirect $redirect): array => [
                $this->redirectIdentity($redirect->source_url, $redirect->target_url) => true,
            ]);

        $inactive = Redirect::query()
            ->where('is_active', false)
            ->orderBy('source_url')
            ->get(['id', 'source_url', 'target_url', 'status_code'])
            ->reject(fn (Redirect $redirect): bool => $activePermanentRedirects->has(
                $this->redirectIdentity($redirect->source_url, $redirect->target_url),
            ))
            ->map(fn (Redirect $redirect): array => $this->redirectSummary($redirect))
            ->values()
            ->all();

        $non301 = Redirect::query()
            ->where('is_active', true)
            ->where('status_code', '!=', 301)
            ->orderBy('source_url')
            ->get(['id', 'source_url', 'target_url', 'status_code'])
            ->map(fn (Redirect $redirect): array => $this->redirectSummary($redirect))
            ->all();

        $duplicateTargets = Redirect::query()
            ->select('target_url', DB::raw('COUNT(*) as redirects_count'))
            ->whereNotNull('target_url')
            ->groupBy('target_url')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('target_url')
            ->get()
            ->map(fn ($row): array => [
                'target_url' => $row->target_url,
                'redirects_count' => (int) $row->redirects_count,
                'source_urls' => Redirect::query()
                    ->where('target_url', $row->target_url)
                    ->orderBy('source_url')
                    ->pluck('source_url')
                    ->all(),
            ])
            ->all();

        return [
            'totals' => [
                'missing' => count($missing),
                'inactive' => count($inactive),
                'non_301' => count($non301),
                'duplicate_targets' => count($duplicateTargets),
            ],
            'missing' => $missing,
            'inactive' => $inactive,
            'non_301' => $non301,
            'duplicate_targets' => $duplicateTargets,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(): array
    {
        $summary = $this->summary();
        $rows = [];

        foreach ($summary['missing'] as $item) {
            $missingSourceUrl = ($item['reason'] ?? null) === 'missing_source_url';
            $rows[] = [
                'issue' => $missingSourceUrl ? 'missing_source_url' : 'missing',
                'source_url' => $item['source_url'],
                'target_url' => $item['target_url'],
                'status_code' => null,
                'is_active' => null,
                'wordpress_id' => $item['wordpress_id'],
                'details' => $missingSourceUrl
                    ? "Recover the legacy source URL for WordPress {$item['wordpress_type']} {$item['wordpress_id']} from WordPress source evidence before generating redirects."
                    : ($item['wordpress_type'] === 'post'
                    ? "Create a 301 redirect for WordPress post {$item['wordpress_id']} from the old URL to the new article URL."
                    : "Create a 301 redirect for WordPress {$item['wordpress_type']} {$item['wordpress_id']} from the old URL to the imported archive URL."),
            ];
        }

        foreach ($summary['inactive'] as $item) {
            $rows[] = [
                'issue' => 'inactive',
                'source_url' => $item['source_url'],
                'target_url' => $item['target_url'],
                'status_code' => $item['status_code'],
                'is_active' => false,
                'wordpress_id' => null,
                'details' => 'Activate this redirect before launch or remove it if the old URL should not resolve.',
            ];
        }

        foreach ($summary['non_301'] as $item) {
            $rows[] = [
                'issue' => 'non_301',
                'source_url' => $item['source_url'],
                'target_url' => $item['target_url'],
                'status_code' => $item['status_code'],
                'is_active' => true,
                'wordpress_id' => null,
                'details' => "Change this active redirect to HTTP 301 before launch. Current status code: {$item['status_code']}.",
            ];
        }

        foreach ($summary['duplicate_targets'] as $item) {
            $redirectsCount = count($item['source_urls']);

            $rows[] = [
                'issue' => 'duplicate_target',
                'source_url' => implode(', ', $item['source_urls']),
                'target_url' => $item['target_url'],
                'status_code' => 301,
                'is_active' => true,
                'wordpress_id' => null,
                'details' => "Review {$redirectsCount} redirects pointing to this target; keep intentional aliases and remove accidental duplicates.",
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function redirectSummary(Redirect $redirect): array
    {
        return [
            'id' => $redirect->id,
            'source_url' => $redirect->source_url,
            'target_url' => $redirect->target_url,
            'status_code' => $redirect->status_code,
        ];
    }

    private function isSelfRedirect(?string $oldUrl, ?string $newUrl): bool
    {
        return $this->normalizedPath($oldUrl) !== ''
            && $this->normalizedPath($oldUrl) === $this->normalizedPath($newUrl);
    }

    private function normalizedPath(?string $url): string
    {
        return UrlPath::normalize($url);
    }

    private function redirectIdentity(?string $sourceUrl, ?string $targetUrl): string
    {
        return $this->normalizedPath($sourceUrl)."\0".$this->normalizedPath($targetUrl);
    }
}
