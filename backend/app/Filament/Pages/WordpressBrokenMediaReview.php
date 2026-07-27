<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasLocalizedPageNavigation;
use App\Models\Article;
use App\Models\MediaAsset;
use App\Services\Wordpress\WordpressOldLinkRepairService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class WordpressBrokenMediaReview extends Page
{
    use HasLocalizedPageNavigation;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|\UnitEnum|null $navigationGroup = 'Migration';

    protected static ?string $navigationLabel = 'Broken Media Review';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'WordPress broken media review';

    protected string $view = 'filament.pages.wordpress-broken-media-review';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $groupedRows = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $detailRows = [];

    public string $statusFilter = 'all';

    public string $sourcePeriodFilter = 'all';

    public bool $onlyMultipleReferences = false;

    public string $search = '';

    public ?int $articleId = null;

    public string $sourceUrl = '';

    public ?int $mediaAssetId = null;

    public ?array $lastReplacement = null;

    public ?array $lastRemoval = null;

    public function mount(): void
    {
        $this->refreshReport();
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasPermission('imports.view') ?? false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function filteredGroupedRows(): array
    {
        return $this->filterRows($this->groupedRows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function filteredDetailRows(): array
    {
        return $this->filterRows($this->detailRows);
    }

    public function replaceReference(): void
    {
        $validated = validator([
            'article_id' => $this->articleId,
            'source_url' => $this->sourceUrl,
            'media_asset_id' => $this->mediaAssetId,
        ], [
            'article_id' => ['required', 'integer', 'exists:articles,id'],
            'source_url' => ['required', 'string'],
            'media_asset_id' => ['required', 'integer', 'exists:media_assets,id'],
        ])->validate();

        $this->lastReplacement = app(WordpressOldLinkRepairService::class)->replaceOldUploadReference(
            Article::query()->findOrFail($validated['article_id']),
            $validated['source_url'],
            MediaAsset::query()->findOrFail($validated['media_asset_id']),
        );

        $this->refreshReport();

        Notification::make()
            ->title('Old media reference replaced')
            ->body($this->lastReplacement['replaced_references'].' reference(s) updated.')
            ->success()
            ->send();
    }

    public function removeReference(): void
    {
        $validated = validator([
            'article_id' => $this->articleId,
            'source_url' => $this->sourceUrl,
        ], [
            'article_id' => ['required', 'integer', 'exists:articles,id'],
            'source_url' => ['required', 'string'],
        ])->validate();

        $this->lastRemoval = app(WordpressOldLinkRepairService::class)->removeOldUploadReference(
            Article::query()->findOrFail($validated['article_id']),
            $validated['source_url'],
        );

        $this->refreshReport();

        Notification::make()
            ->title('Old media reference removed')
            ->body($this->lastRemoval['removed_references'].' reference(s) removed.')
            ->success()
            ->send();
    }

    public function selectReference(int $articleId, string $sourceUrl): void
    {
        $this->articleId = $articleId;
        $this->sourceUrl = $sourceUrl;
    }

    public function selectedMediaPreviewUrl(): ?string
    {
        if (! $this->mediaAssetId) {
            return null;
        }

        $asset = MediaAsset::query()->find($this->mediaAssetId);

        return $asset?->url ?: $asset?->admin_preview_url;
    }

    /**
     * @return Collection<int, MediaAsset>
     */
    public function mediaOptions(): Collection
    {
        return MediaAsset::query()
            ->whereNotNull('url')
            ->orWhereNotNull('path')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function sourcePeriodOptions(): array
    {
        return collect([...$this->groupedRows, ...$this->detailRows])
            ->map(fn (array $row): ?string => $this->sourcePeriod($row))
            ->filter()
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    public function displayValue(mixed $value): string
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): string => (string) $item)
                ->filter()
                ->implode(', ');
        }

        return $value === null || $value === '' ? '-' : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function publicUrlForRow(array $row): ?string
    {
        $path = $row['new_url'] ?? null;

        if (! is_string($path) || $path === '') {
            $slug = $row['slug'] ?? null;
            $path = is_string($slug) && $slug !== '' ? '/'.$slug : null;
        }

        if (! $path) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }

    private function refreshReport(): void
    {
        $repair = app(WordpressOldLinkRepairService::class);

        $this->groupedRows = $repair->brokenUploadReportRows();
        $this->detailRows = $repair->exportRows();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterRows(array $rows): array
    {
        $statusFilter = $this->statusFilter;
        $sourcePeriodFilter = $this->sourcePeriodFilter;
        $onlyMultipleReferences = $this->onlyMultipleReferences;
        $search = mb_strtolower(trim($this->search));
        $multipleReferenceArticleIds = $this->multipleReferenceArticleIds();
        $multipleReferenceSources = $this->multipleReferenceSources();

        return collect($rows)
            ->filter(function (array $row) use ($multipleReferenceArticleIds, $multipleReferenceSources, $onlyMultipleReferences, $search, $sourcePeriodFilter, $statusFilter): bool {
                if ($statusFilter !== 'all' && ($row['status'] ?? null) !== $statusFilter) {
                    return false;
                }

                if ($sourcePeriodFilter !== 'all' && $this->sourcePeriod($row) !== $sourcePeriodFilter) {
                    return false;
                }

                if (
                    $onlyMultipleReferences
                    && ! in_array((string) ($row['source_url'] ?? ''), $multipleReferenceSources, true)
                    && ! $this->rowHasMultipleReferenceArticle($row, $multipleReferenceArticleIds)
                ) {
                    return false;
                }

                if ($search === '') {
                    return true;
                }

                return str_contains($this->rowSearchText($row), $search);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function multipleReferenceSources(): array
    {
        return collect($this->groupedRows)
            ->filter(fn (array $row): bool => (int) ($row['occurrence_count'] ?? 0) > 1 || (int) ($row['article_count'] ?? 0) > 1)
            ->pluck('source_url')
            ->filter()
            ->map(fn (mixed $sourceUrl): string => (string) $sourceUrl)
            ->values()
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function multipleReferenceArticleIds(): array
    {
        return collect($this->detailRows)
            ->groupBy(fn (array $row): int => (int) ($row['article_id'] ?? 0))
            ->filter(fn (Collection $rows, int $articleId): bool => $articleId > 0 && $rows->count() > 1)
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, int>  $articleIds
     */
    private function rowHasMultipleReferenceArticle(array $row, array $articleIds): bool
    {
        $rowArticleIds = collect([$row['article_id'] ?? null])
            ->flatMap(fn (mixed $value): array => is_array($value) ? $value : [$value])
            ->map(fn (mixed $value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->all();

        return collect($rowArticleIds)
            ->contains(fn (int $articleId): bool => in_array($articleId, $articleIds, true));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sourcePeriod(array $row): ?string
    {
        $sourceUrl = (string) ($row['source_url'] ?? '');

        if (preg_match('/\/wp-content\/uploads\/(\d{4}\/\d{2})\//', $sourceUrl, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowSearchText(array $row): string
    {
        return mb_strtolower(collect([
            $row['article_id'] ?? null,
            $row['wordpress_id'] ?? null,
            $row['title'] ?? null,
            $row['slug'] ?? null,
            $row['old_url'] ?? null,
            $row['new_url'] ?? null,
            $row['source_url'] ?? null,
            $row['literal_url'] ?? null,
            $row['literal'] ?? null,
        ])
            ->flatMap(fn (mixed $value): array => is_array($value) ? $value : [$value])
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->map(fn (mixed $value): string => (string) $value)
            ->implode(' '));
    }
}
