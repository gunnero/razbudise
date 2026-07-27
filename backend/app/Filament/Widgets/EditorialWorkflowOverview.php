<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Articles\ArticleResource;
use App\Models\Article;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class EditorialWorkflowOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -10;

    protected function getHeading(): ?string
    {
        return __('admin.dashboard.editorial_heading');
    }

    protected function getDescription(): ?string
    {
        return __('admin.dashboard.editorial_description');
    }

    protected function getStats(): array
    {
        $articles = $this->visibleArticles();

        return [
            Stat::make(__('admin.dashboard.new_article'), __('admin.dashboard.start_writing'))
                ->description(__('admin.dashboard.new_article_help'))
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->url(ArticleResource::getUrl('create')),
            Stat::make(__('admin.dashboard.drafts'), number_format((clone $articles)->where('status', 'draft')->count()))
                ->description(__('admin.dashboard.continue_writing'))
                ->color('gray')
                ->url(ArticleResource::getUrl('index', [
                    'filters' => ['status' => ['value' => 'draft']],
                ])),
            Stat::make(__('admin.dashboard.in_review'), number_format((clone $articles)->whereIn('status', ['submitted', 'review', 'in_review'])->count()))
                ->description(__('admin.dashboard.review_help'))
                ->color('warning')
                ->url(ArticleResource::getUrl('index', [
                    'filters' => ['editorial_queue' => ['value' => 'needs_approval']],
                ])),
            Stat::make(__('admin.dashboard.published'), number_format((clone $articles)->where('status', 'published')->count()))
                ->description(__('admin.dashboard.published_help'))
                ->color('success')
                ->url(ArticleResource::getUrl()),
        ];
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user?->hasPermission('articles.view')
            || $user?->hasPermission('articles.create')
            || false;
    }

    private function visibleArticles(): Builder
    {
        $query = Article::query();
        $user = auth()->user();

        return $user
            ? $query->visibleToEditorialUser($user)
            : $query->whereRaw('1 = 0');
    }
}
