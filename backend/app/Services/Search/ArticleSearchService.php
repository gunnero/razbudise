<?php

namespace App\Services\Search;

use App\Models\Article;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Throwable;

class ArticleSearchService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function search(string $query, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        if ($query !== '' && config('scout.driver') === 'meilisearch') {
            try {
                $ids = Article::search($query)->keys()->all();

                if ($ids === []) {
                    return new Paginator([], 0, $perPage);
                }

                $builder = Article::query()
                    ->published()
                    ->whereKey($ids)
                    ->with(['author', 'category', 'featuredImage', 'sponsoredContentDetail']);

                $this->applyFilters($builder, $filters);

                return $builder
                    ->orderByRaw($this->orderByIdsSql($ids))
                    ->paginate($perPage);
            } catch (Throwable) {
                return $this->databaseSearch($query, $filters, $perPage);
            }
        }

        return $this->databaseSearch($query, $filters, $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function databaseSearch(string $query, array $filters = [], int $perPage = 12): LengthAwarePaginator
    {
        $builder = Article::query()
            ->published()
            ->with(['author', 'category', 'featuredImage', 'sponsoredContentDetail']);

        if ($query !== '') {
            $builder->where(function (Builder $inner) use ($query): void {
                $inner
                    ->where('title', 'like', "%{$query}%")
                    ->orWhere('excerpt', 'like', "%{$query}%")
                    ->orWhere('body_html', 'like', "%{$query}%");
            });
        }

        $this->applyFilters($builder, $filters);

        return $builder
            ->latest('published_at')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $builder, array $filters): Builder
    {
        return $builder
            ->when($this->stringFilter($filters, 'type'), fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($this->stringFilter($filters, 'category'), fn (Builder $query, string $slug) => $query->whereHas(
                'category',
                fn (Builder $categoryQuery) => $categoryQuery->where('slug', $slug)->where('is_active', true),
            ))
            ->when($this->stringFilter($filters, 'tag'), fn (Builder $query, string $slug) => $query->whereHas(
                'tags',
                fn (Builder $tagQuery) => $tagQuery->where('slug', $slug),
            ))
            ->when($this->stringFilter($filters, 'author'), fn (Builder $query, string $slug) => $query->whereHas(
                'author',
                fn (Builder $authorQuery) => $authorQuery->where('slug', $slug)->where('is_active', true),
            ))
            ->when($this->booleanFilter($filters, 'sponsored') !== null, fn (Builder $query) => $query->where(
                'is_sponsored',
                $this->booleanFilter($filters, 'sponsored'),
            ))
            ->when($this->stringFilter($filters, 'published_from'), fn (Builder $query, string $date) => $query->whereDate('published_at', '>=', $date))
            ->when($this->stringFilter($filters, 'published_to'), fn (Builder $query, string $date) => $query->whereDate('published_at', '<=', $date));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function stringFilter(array $filters, string $key): ?string
    {
        $value = trim((string) ($filters[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function booleanFilter(array $filters, string $key): ?bool
    {
        if (! array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') {
            return null;
        }

        return filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    private function orderByIdsSql(array $ids): string
    {
        $cases = collect($ids)
            ->values()
            ->map(fn ($id, int $index): string => 'when '.((int) $id).' then '.$index)
            ->implode(' ');

        return "case id {$cases} else ".count($ids).' end';
    }
}
